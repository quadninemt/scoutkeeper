<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Core\Database;

/**
 * iCalendar feed service.
 *
 * Manages per-member iCal tokens for unauthenticated calendar feed access
 * and generates RFC 5545 compliant iCalendar output from event data.
 */
class ICalService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Generate a new iCal feed token for a member.
     *
     * Creates a cryptographically secure 64-character hex token and stores it
     * in the database. If the member already has a token, a duplicate will be
     * created — use regenerateToken() to replace an existing token.
     *
     * @param int $memberId The member ID
     * @return string The generated 64-character hex token
     */
    public function generateToken(int $memberId): string
    {
        $token = bin2hex(random_bytes(32));

        $this->db->insert('event_ical_tokens', [
            'member_id' => $memberId,
            'token' => $token,
        ]);

        return $token;
    }

    /**
     * Get the existing iCal token record for a member.
     *
     * @param int $memberId The member ID
     * @return array|null Token record (id, member_id, token, created_at) or null
     */
    public function getTokenForMember(int $memberId): ?array
    {
        $record = $this->db->fetchOne(
            "SELECT * FROM event_ical_tokens WHERE member_id = :member_id ORDER BY created_at DESC, id DESC LIMIT 1",
            ['member_id' => $memberId]
        );

        return $record !== null ? $this->castTokenTypes($record) : null;
    }

    /**
     * Validate an iCal feed token.
     *
     * Looks up the token and returns the token record including member_id
     * if valid. Returns null if the token does not exist.
     *
     * @param string $token The 64-character hex token
     * @return array|null Token record with member_id if valid, null otherwise
     */
    public function validateToken(string $token): ?array
    {
        $record = $this->db->fetchOne(
            "SELECT * FROM event_ical_tokens WHERE token = :token",
            ['token' => $token]
        );

        return $record !== null ? $this->castTokenTypes($record) : null;
    }

    /**
     * Regenerate a member's iCal feed token.
     *
     * Deletes all existing tokens for the member and creates a new one.
     * This invalidates any previously shared feed URLs.
     *
     * @param int $memberId The member ID
     * @return string The new 64-character hex token
     */
    public function regenerateToken(int $memberId): string
    {
        $this->db->delete('event_ical_tokens', ['member_id' => $memberId]);

        return $this->generateToken($memberId);
    }

    /**
     * Generate an iCalendar (.ics) feed from an array of events.
     *
     * Produces valid RFC 5545 output with a VCALENDAR wrapper containing
     * one VEVENT component per event. Handles all-day events (DATE format)
     * and timed events (DATE-TIME format with UTC).
     *
     * @param array $events Array of event rows (from EventService)
     * @param string $calendarName Display name for the calendar
     * @return string RFC 5545 iCalendar content
     */
    public function generateFeed(array $events, string $calendarName = 'ScoutKeeper Events'): string
    {
        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//ScoutKeeper//Events//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-CALNAME:' . $this->escapeText($calendarName);

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:event-' . ($event['id'] ?? '0') . '@scoutkeeper';
            $lines[] = 'DTSTAMP:' . $this->formatDateTimeUtc(gmdate('Y-m-d H:i:s'));

            if (!empty($event['all_day'])) {
                $lines[] = 'DTSTART;VALUE=DATE:' . $this->formatDate($event['start_date']);
                if (!empty($event['end_date'])) {
                    // iCal all-day DTEND is exclusive, so add one day
                    $endDate = new \DateTimeImmutable($event['end_date']);
                    $endDate = $endDate->modify('+1 day');
                    $lines[] = 'DTEND;VALUE=DATE:' . $endDate->format('Ymd');
                }
            } else {
                $lines[] = 'DTSTART:' . $this->formatDateTimeUtc($event['start_date']);
                if (!empty($event['end_date'])) {
                    $lines[] = 'DTEND:' . $this->formatDateTimeUtc($event['end_date']);
                }
            }

            $lines[] = 'SUMMARY:' . $this->escapeText($event['title'] ?? '');

            if (!empty($event['description'])) {
                $lines[] = 'DESCRIPTION:' . $this->escapeText($event['description']);
            }

            if (!empty($event['location'])) {
                $lines[] = 'LOCATION:' . $this->escapeText($event['location']);
            }

            if (!empty($event['updated_at'])) {
                $lines[] = 'LAST-MODIFIED:' . $this->formatDateTimeUtc($event['updated_at']);
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        // Fold lines longer than 75 octets per RFC 5545 Section 3.1
        $folded = array_map([$this, 'foldLine'], $lines);

        return implode("\r\n", $folded) . "\r\n";
    }

    /**
     * Format a datetime string as an iCal UTC datetime (YYYYMMDDTHHMMSSZ).
     *
     * @param string $datetime Date/time string parseable by DateTimeImmutable
     * @return string Formatted UTC datetime
     */
    private function formatDateTimeUtc(string $datetime): string
    {
        $dt = new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
        return $dt->format('Ymd\THis\Z');
    }

    /**
     * Format a datetime string as an iCal date (YYYYMMDD).
     *
     * @param string $datetime Date/time string parseable by DateTimeImmutable
     * @return string Formatted date
     */
    private function formatDate(string $datetime): string
    {
        $dt = new \DateTimeImmutable($datetime);
        return $dt->format('Ymd');
    }

    /**
     * Escape text for iCalendar content.
     *
     * Per RFC 5545 Section 3.3.11, backslashes, semicolons, commas, and
     * newlines must be escaped in TEXT values.
     *
     * @param string $text Raw text
     * @return string Escaped text safe for iCal properties
     */
    private function escapeText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(';', '\\;', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace("\r\n", '\\n', $text);
        $text = str_replace("\r", '\\n', $text);
        $text = str_replace("\n", '\\n', $text);

        return $text;
    }

    /**
     * Fold a content line to comply with the 75-octet limit.
     *
     * Per RFC 5545 Section 3.1, lines longer than 75 octets should be folded
     * by inserting a CRLF followed by a single whitespace character.
     *
     * @param string $line Single logical line
     * @return string Folded line(s) joined with CRLF + space
     */
    private function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = mb_substr($line, 0, 75);
        $remaining = mb_substr($line, 75);

        while (strlen($remaining) > 0) {
            $chunk = mb_substr($remaining, 0, 74); // 74 + 1 leading space = 75
            $folded .= "\r\n " . $chunk;
            $remaining = mb_substr($remaining, 74);
        }

        return $folded;
    }

    /**
     * Cast database types on a token row.
     *
     * @param array $record Raw token row from database
     * @return array Token row with properly typed values
     */
    private function castTokenTypes(array $record): array
    {
        if (isset($record['id'])) {
            $record['id'] = (int) $record['id'];
        }
        if (isset($record['member_id'])) {
            $record['member_id'] = (int) $record['member_id'];
        }

        return $record;
    }
}
