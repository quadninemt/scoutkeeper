<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Email queue and sending service.
 *
 * Manages the email queue (enqueue, batch processing) and handles
 * actual email delivery via PHP mail() or a socket-based SMTP client
 * with STARTTLS and AUTH LOGIN support. No external dependencies.
 */
class EmailService
{
    private Database $db;
    private array $config;

    /** Counter for redacting the next N SMTP writes (for AUTH LOGIN credentials). */
    private int $redactNextWrites = 0;

    /**
     * @param Database $db Database instance
     * @param array $config SMTP configuration (host, port, username, password, encryption, from_email, from_name)
     */
    public function __construct(Database $db, array $config = [])
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Build an EmailService with the effective SMTP configuration:
     * DB `smtp` settings overlaid onto config.php values.
     */
    public static function create(\App\Core\Application $app): self
    {
        $fileConfig = $app->getConfig()['smtp'] ?? [];

        $dbConfig = [];
        try {
            $dbConfig = (new \App\Modules\Admin\Services\SettingsService($app->getDb()))->getGroup('smtp');
        } catch (\Throwable) {
            // Settings table may not exist yet (setup, tests) — fall back to file config
        }

        return new self($app->getDb(), [
            'host' => (string) ($dbConfig['smtp_host'] ?? $fileConfig['host'] ?? ''),
            'port' => (int) ($dbConfig['smtp_port'] ?? $fileConfig['port'] ?? 587),
            'username' => (string) ($dbConfig['smtp_username'] ?? $fileConfig['username'] ?? ''),
            'password' => (string) ($dbConfig['smtp_password'] ?? $fileConfig['password'] ?? ''),
            'encryption' => (string) ($dbConfig['smtp_encryption'] ?? $fileConfig['encryption'] ?? 'tls'),
            'from_email' => (string) ($dbConfig['smtp_from_email'] ?? $fileConfig['from_email'] ?? 'noreply@localhost'),
            'from_name' => (string) ($dbConfig['smtp_from_name'] ?? $fileConfig['from_name'] ?? 'ScoutKeeper'),
        ]);
    }

    // ──── Queue management ────

    /**
     * Add a single email to the queue.
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $bodyHtml HTML body
     * @param string|null $bodyText Plain-text body (auto-generated from HTML if null)
     * @param string|null $recipientName Recipient display name
     * @param string|null $scheduledAt ISO datetime to schedule sending (defaults to now)
     * @return int The queue entry ID
     */
    public function queue(
        string $to,
        string $subject,
        string $bodyHtml,
        ?string $bodyText = null,
        ?string $recipientName = null,
        ?string $scheduledAt = null,
    ): int {
        return $this->db->insert('email_queue', [
            'recipient_email' => strtolower(trim($to)),
            'recipient_name' => $recipientName,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText ?? $this->stripHtml($bodyHtml),
            'status' => 'pending',
            'scheduled_at' => $scheduledAt ?? gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Queue the same email to multiple recipients.
     *
     * Each recipient is an associative array with 'email' and optional 'name' keys.
     *
     * @param array $recipients List of ['email' => ..., 'name' => ...] arrays
     * @param string $subject Email subject
     * @param string $bodyHtml HTML body
     * @param string|null $bodyText Plain-text body
     * @return int Number of emails queued
     */
    public function queueBulk(
        array $recipients,
        string $subject,
        string $bodyHtml,
        ?string $bodyText = null,
    ): int {
        $textBody = $bodyText ?? $this->stripHtml($bodyHtml);
        $count = 0;

        foreach ($recipients as $recipient) {
            $email = $recipient['email'] ?? null;
            if ($email === null || $email === '') {
                continue;
            }

            $this->db->insert('email_queue', [
                'recipient_email' => strtolower(trim($email)),
                'recipient_name' => $recipient['name'] ?? null,
                'subject' => $subject,
                'body_html' => $bodyHtml,
                'body_text' => $textBody,
                'status' => 'pending',
                'scheduled_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Process a batch of queued emails.
     *
     * Fetches pending or failed (under max_attempts) emails that are due
     * for sending, attempts delivery, and updates their status.
     *
     * @param int $batchSize Maximum number of emails to process
     * @return array{sent: int, failed: int}
     */
    public function processBatch(int $batchSize = 10): array
    {
        $results = ['sent' => 0, 'failed' => 0];

        $emails = $this->db->fetchAll(
            "SELECT * FROM email_queue
             WHERE status IN ('pending', 'failed')
             AND attempts < max_attempts
             AND scheduled_at <= :now
             ORDER BY scheduled_at ASC
             LIMIT :batch",
            ['now' => gmdate('Y-m-d H:i:s'), 'batch' => $batchSize]
        );

        foreach ($emails as $email) {
            $id = (int) $email['id'];

            // Mark as sending
            $this->db->update('email_queue', [
                'status' => 'sending',
                'attempts' => (int) $email['attempts'] + 1,
            ], ['id' => $id]);

            try {
                $sent = $this->sendEmail(
                    $email['recipient_email'],
                    $email['subject'],
                    $email['body_html'],
                    $email['body_text'],
                    $email['recipient_name'],
                );

                if ($sent) {
                    $now = gmdate('Y-m-d H:i:s');
                    $this->db->update('email_queue', [
                        'status' => 'sent',
                        'sent_at' => $now,
                        'last_error' => null,
                    ], ['id' => $id]);

                    $this->logEmail($email['recipient_email'], $email['subject'], 'sent', $now, null, $id);
                    $results['sent']++;
                } else {
                    $this->markFailed($id, 'sendEmail returned false');
                    $this->logEmail($email['recipient_email'], $email['subject'], 'failed', gmdate('Y-m-d H:i:s'), 'Send returned false', $id);
                    $results['failed']++;
                }
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                $this->markFailed($id, $errorMsg);
                $this->logEmail($email['recipient_email'], $email['subject'], 'failed', gmdate('Y-m-d H:i:s'), $errorMsg, $id);
                $results['failed']++;

                Logger::error('Email send failed', [
                    'queue_id' => $id,
                    'recipient' => $email['recipient_email'],
                    'error' => $errorMsg,
                ]);
            }
        }

        return $results;
    }

    /**
     * Send an email immediately.
     *
     * If smtp.host is configured, uses a socket-based SMTP client with
     * STARTTLS and AUTH LOGIN. Otherwise falls back to PHP mail().
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $bodyHtml HTML body
     * @param string|null $bodyText Plain-text body
     * @param string|null $toName Recipient display name
     * @return bool True if sent successfully
     */
    public function sendEmail(
        string $to,
        string $subject,
        string $bodyHtml,
        ?string $bodyText = null,
        ?string $toName = null,
    ): bool {
        $fromEmail = $this->config['from_email'] ?? 'noreply@localhost';
        $fromName = $this->config['from_name'] ?? 'ScoutKeeper';
        $smtpHost = $this->config['host'] ?? '';

        if ($smtpHost !== '') {
            return $this->sendViaSmtp($to, $toName, $subject, $bodyHtml, $bodyText, $fromEmail, $fromName);
        }

        return $this->sendViaMail($to, $toName, $subject, $bodyHtml, $bodyText, $fromEmail, $fromName);
    }

    /**
     * Get email queue statistics grouped by status.
     *
     * @return array Status => count (e.g. ['pending' => 5, 'sent' => 120, ...])
     */
    public function getQueueStats(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS cnt FROM email_queue GROUP BY status"
        );

        $stats = [
            'pending' => 0,
            'sending' => 0,
            'sent' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['cnt'];
        }

        return $stats;
    }

    /**
     * Get paginated email log entries.
     *
     * @param int $page Current page (1-based)
     * @param int $perPage Items per page
     * @return array{items: array, total: int, page: int, pages: int, per_page: int}
     */
    public function getLog(int $page = 1, int $perPage = 25): array
    {
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM email_log");

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT * FROM email_log
             ORDER BY sent_at DESC
             LIMIT :limit OFFSET :offset",
            ['limit' => $perPage, 'offset' => $offset]
        );

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    // ──── SMTP client ────

    /**
     * Send email via socket-based SMTP with STARTTLS and AUTH LOGIN.
     *
     * @throws \RuntimeException on connection or protocol errors
     */
    private function sendViaSmtp(
        string $to,
        ?string $toName,
        string $subject,
        string $bodyHtml,
        ?string $bodyText,
        string $fromEmail,
        string $fromName,
    ): bool {
        $host = $this->config['host'];
        $port = (int) ($this->config['port'] ?? 587);
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';
        $encryption = $this->config['encryption'] ?? 'tls';

        // Connect
        $contextOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
        $context = stream_context_create($contextOptions);

        $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
        $target = $prefix . $host . ':' . $port;

        Logger::smtp('connect', "Connecting to $target", [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'to' => $to,
        ]);

        $socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            Logger::smtp('error', "Connection failed: $errstr ($errno)", [
                'host' => $host,
                'port' => $port,
                'errno' => $errno,
                'errstr' => $errstr,
            ]);
            throw new \RuntimeException("SMTP connection failed: $errstr ($errno)");
        }

        // Set timeout
        stream_set_timeout($socket, 30);

        try {
            // Read greeting
            $this->smtpRead($socket, 220);

            // EHLO
            $this->smtpWrite($socket, 'EHLO ' . gethostname());
            $this->smtpRead($socket, 250);

            // STARTTLS for non-SSL connections on port 587
            if ($encryption === 'tls') {
                $this->smtpWrite($socket, 'STARTTLS');
                $this->smtpRead($socket, 220);

                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }

                if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                    throw new \RuntimeException('STARTTLS negotiation failed');
                }

                // Re-EHLO after TLS
                $this->smtpWrite($socket, 'EHLO ' . gethostname());
                $this->smtpRead($socket, 250);
            }

            // AUTH LOGIN
            if ($username !== '') {
                $this->smtpWrite($socket, 'AUTH LOGIN');
                $this->smtpRead($socket, 334);

                $this->smtpWrite($socket, base64_encode($username));
                $this->smtpRead($socket, 334);

                $this->smtpWrite($socket, base64_encode($password));
                $this->smtpRead($socket, 235);
            }

            // MAIL FROM
            $this->smtpWrite($socket, 'MAIL FROM:<' . $fromEmail . '>');
            $this->smtpRead($socket, 250);

            // RCPT TO
            $this->smtpWrite($socket, 'RCPT TO:<' . $to . '>');
            $this->smtpRead($socket, 250);

            // DATA
            $this->smtpWrite($socket, 'DATA');
            $this->smtpRead($socket, 354);

            // Build message
            $message = $this->buildMimeMessage($to, $toName, $subject, $bodyHtml, $bodyText, $fromEmail, $fromName);

            // Send message body (escape leading dots per RFC 5321)
            $this->smtpWrite($socket, $message);
            $this->smtpWrite($socket, '.');
            $this->smtpRead($socket, 250);

            // QUIT
            $this->smtpWrite($socket, 'QUIT');
            // Don't require a specific response for QUIT

            Logger::smtp('info', 'Message delivered', ['to' => $to, 'subject' => $subject]);
            return true;
        } catch (\Throwable $e) {
            Logger::smtp('error', $e->getMessage(), [
                'host' => $host,
                'port' => $port,
                'to' => $to,
            ]);
            throw $e;
        } finally {
            @fclose($socket);
        }
    }

    /**
     * Send email via PHP mail() as a fallback.
     */
    private function sendViaMail(
        string $to,
        ?string $toName,
        string $subject,
        string $bodyHtml,
        ?string $bodyText,
        string $fromEmail,
        string $fromName,
    ): bool {
        $boundary = '----=_Part_' . bin2hex(random_bytes(8));

        $headers = [];
        $headers[] = 'From: ' . $this->formatAddress($fromEmail, $fromName);
        $headers[] = 'Reply-To: ' . $fromEmail;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $headers[] = 'X-Mailer: ScoutKeeper/1.0';

        $textPart = $bodyText ?? $this->stripHtml($bodyHtml);

        $body = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($textPart) . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($bodyHtml) . "\r\n";
        $body .= "--$boundary--\r\n";

        $recipient = $toName !== null ? $this->formatAddress($to, $toName) : $to;

        return mail($recipient, $subject, $body, implode("\r\n", $headers));
    }

    /**
     * Build a MIME multipart/alternative message for SMTP.
     */
    private function buildMimeMessage(
        string $to,
        ?string $toName,
        string $subject,
        string $bodyHtml,
        ?string $bodyText,
        string $fromEmail,
        string $fromName,
    ): string {
        $boundary = '----=_Part_' . bin2hex(random_bytes(8));
        $textPart = $bodyText ?? $this->stripHtml($bodyHtml);

        $msg = '';
        $msg .= 'From: ' . $this->formatAddress($fromEmail, $fromName) . "\r\n";
        $msg .= 'To: ' . ($toName !== null ? $this->formatAddress($to, $toName) : $to) . "\r\n";
        $msg .= 'Subject: ' . $this->encodeHeader($subject) . "\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";
        $msg .= 'Date: ' . gmdate('r') . "\r\n";
        $msg .= "X-Mailer: ScoutKeeper/1.0\r\n";
        $msg .= "\r\n";
        $msg .= "--$boundary\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($textPart)) . "\r\n";
        $msg .= "--$boundary\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($bodyHtml)) . "\r\n";
        $msg .= "--$boundary--";

        return $msg;
    }

    // ──── SMTP helpers ────

    /**
     * Write a command to the SMTP socket.
     *
     * @param resource $socket
     * @param string $data
     */
    private function smtpWrite($socket, string $data): void
    {
        $logged = $data;
        if ($this->redactNextWrites > 0) {
            $logged = '[redacted ' . strlen($data) . ' bytes]';
            $this->redactNextWrites--;
        } elseif (strlen($data) > 500) {
            $logged = substr($data, 0, 200) . '… [' . strlen($data) . ' bytes total]';
        }
        Logger::smtp('send', $logged);

        // After AUTH LOGIN, the next two writes carry base64-encoded username + password.
        if (strcasecmp(trim($data), 'AUTH LOGIN') === 0) {
            $this->redactNextWrites = 2;
        }

        $result = fwrite($socket, $data . "\r\n");
        if ($result === false) {
            Logger::smtp('error', 'Failed to write to SMTP socket');
            throw new \RuntimeException('Failed to write to SMTP socket');
        }
    }

    /**
     * Read the SMTP server response and validate the status code.
     *
     * @param resource $socket
     * @param int $expectedCode Expected 3-digit SMTP status code
     * @return string The full response text
     */
    private function smtpRead($socket, int $expectedCode): string
    {
        $response = '';
        while (true) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                Logger::smtp('error', 'SMTP connection lost while reading response');
                throw new \RuntimeException('SMTP connection lost while reading response');
            }
            $response .= $line;

            // Multi-line responses have a hyphen after the code; final line has a space
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            // Also break if the line is shorter than expected (malformed but end)
            if (strlen($line) < 4) {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        Logger::smtp('recv', trim($response), ['expected' => $expectedCode, 'got' => $code]);

        if ($code !== $expectedCode) {
            throw new \RuntimeException(
                "SMTP error: expected $expectedCode, got $code. Response: " . trim($response)
            );
        }

        return $response;
    }

    // ──── Utility helpers ────

    /**
     * Mark a queued email as failed.
     */
    private function markFailed(int $id, string $error): void
    {
        $this->db->update('email_queue', [
            'status' => 'failed',
            'last_error' => mb_substr($error, 0, 500),
        ], ['id' => $id]);
    }

    /**
     * Log an email send result.
     */
    private function logEmail(
        string $recipientEmail,
        string $subject,
        string $status,
        string $sentAt,
        ?string $errorMessage,
        ?int $queueId,
    ): void {
        $this->db->insert('email_log', [
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'status' => $status,
            'sent_at' => $sentAt,
            'error_message' => $errorMessage,
            'email_queue_id' => $queueId,
        ]);
    }

    /**
     * Strip HTML tags and decode entities to produce plain-text.
     */
    private function stripHtml(string $html): string
    {
        // Convert <br> and block-level tags to newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Format an email address with a display name.
     */
    private function formatAddress(string $email, ?string $name = null): string
    {
        if ($name === null || $name === '') {
            return $email;
        }

        // Encode name if it contains special characters
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return $this->encodeHeader($name) . ' <' . $email . '>';
        }

        return '"' . str_replace('"', '\\"', $name) . '" <' . $email . '>';
    }

    /**
     * Encode a header value using RFC 2047 (UTF-8 base64).
     */
    private function encodeHeader(string $value): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
