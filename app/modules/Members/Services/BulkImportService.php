<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Core\Csv;
use App\Core\Database;

/**
 * Bulk member import service.
 *
 * Generates CSV templates scoped to a node (including custom fields),
 * parses uploaded CSVs with per-row validation, and imports valid rows
 * as new member records.
 */
class BulkImportService
{
    private Database $db;

    /** @var array Core CSV columns (order matters for template) */
    private const CORE_COLUMNS = [
        'first_name', 'surname', 'email', 'phone', 'dob', 'gender',
        'address_line1', 'address_line2', 'city', 'postcode', 'country',
    ];

    /** @var array Required columns */
    private const REQUIRED_COLUMNS = ['first_name', 'surname'];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Generate a CSV template for a target node.
     *
     * Includes core fixed columns plus all active custom field keys.
     *
     * @param int $nodeId Target node ID (for context, not included in CSV)
     * @return string CSV content with header row
     */
    public function generateTemplate(int $nodeId): string
    {
        $headers = self::CORE_COLUMNS;

        // Add active custom field keys
        $customFields = $this->db->fetchAll(
            "SELECT `field_key` FROM `custom_field_definitions` WHERE `is_active` = 1 ORDER BY `sort_order` ASC"
        );
        foreach ($customFields as $cf) {
            $headers[] = 'custom_' . $cf['field_key'];
        }

        $output = fopen('php://temp', 'r+');
        Csv::put($output, $headers);

        // Add an example row as guidance
        $example = array_fill(0, count($headers), '');
        $example[0] = 'John'; // first_name
        $example[1] = 'Doe';  // surname
        Csv::put($output, $example);

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Parse an uploaded CSV file and validate each row.
     *
     * @param int $nodeId Target node for the import
     * @param string $filePath Path to the uploaded CSV
     * @return array{valid: array, errors: array} Valid rows and error rows with messages
     * @throws \InvalidArgumentException If file cannot be read or has no headers
     */
    public function parseUpload(int $nodeId, string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("Cannot read uploaded file.");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \InvalidArgumentException("Cannot open uploaded file.");
        }

        // Read header row
        $headers = Csv::get($handle);
        if ($headers === false || empty($headers)) {
            fclose($handle);
            throw new \InvalidArgumentException("CSV file has no headers.");
        }

        // Normalise headers (trim, lowercase)
        $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

        // Load existing emails and membership numbers for duplicate checking
        $existingEmails = $this->loadExistingEmails();

        // Load custom field definitions for validation
        $customFieldDefs = $this->loadCustomFieldDefs();

        $valid = [];
        $errors = [];
        $rowNum = 1; // Header is row 0
        $seenEmails = [];

        while (($row = Csv::get($handle)) !== false) {
            $rowNum++;

            // Skip completely empty rows
            if (empty(array_filter($row, fn($v) => $v !== '' && $v !== null))) {
                continue;
            }

            // Map columns to values
            $data = [];
            foreach ($headers as $i => $header) {
                $data[$header] = isset($row[$i]) ? trim($row[$i]) : '';
            }

            $rowErrors = $this->validateRow($data, $existingEmails, $seenEmails, $customFieldDefs);

            if (empty($rowErrors)) {
                $valid[] = ['row' => $rowNum, 'data' => $data];
                // Track email for intra-file duplicate detection
                if (!empty($data['email'])) {
                    $seenEmails[strtolower($data['email'])] = $rowNum;
                }
            } else {
                $errors[] = ['row' => $rowNum, 'data' => $data, 'errors' => $rowErrors];
            }
        }

        fclose($handle);

        return ['valid' => $valid, 'errors' => $errors];
    }

    /**
     * Import validated rows as new members assigned to a node.
     *
     * @param int $nodeId Target node
     * @param array $validRows Array of validated row data from parseUpload()
     * @param int $importedBy User ID performing the import
     * @return int Number of members imported
     */
    public function import(int $nodeId, array $validRows, int $importedBy): int
    {
        $count = 0;

        foreach ($validRows as $row) {
            $data = $row['data'];

            // Generate membership number
            $membershipNumber = $this->generateMembershipNumber();

            // Separate custom fields from core fields
            $customData = [];
            $coreData = [];
            foreach ($data as $key => $value) {
                if (str_starts_with($key, 'custom_')) {
                    $cfKey = substr($key, 7); // strip 'custom_' prefix
                    if ($value !== '') {
                        $customData[$cfKey] = $value;
                    }
                } elseif (in_array($key, self::CORE_COLUMNS, true)) {
                    $coreData[$key] = $value !== '' ? $value : null;
                }
            }

            $memberId = $this->db->insert('members', [
                'membership_number' => $membershipNumber,
                'first_name' => $coreData['first_name'] ?? '',
                'surname' => $coreData['surname'] ?? '',
                'email' => isset($coreData['email']) ? strtolower($coreData['email']) : null,
                'phone' => $coreData['phone'] ?? null,
                'dob' => $coreData['dob'] ?? null,
                'gender' => $coreData['gender'] ?? null,
                'address_line1' => $coreData['address_line1'] ?? null,
                'address_line2' => $coreData['address_line2'] ?? null,
                'city' => $coreData['city'] ?? null,
                'postcode' => $coreData['postcode'] ?? null,
                'country' => $coreData['country'] ?? null,
                'status' => 'active',
                'joined_date' => date('Y-m-d'),
                'member_custom_data' => !empty($customData) ? json_encode($customData) : null,
            ]);

            // Assign to node
            $this->db->query(
                "INSERT INTO `member_nodes` (`member_id`, `node_id`, `is_primary`) VALUES (?, ?, 1)",
                [$memberId, $nodeId]
            );

            $count++;
        }

        return $count;
    }

    // ── Internal ─────────────────────────────────────────────────────

    /**
     * Validate a single CSV row.
     *
     * @return array List of error messages (empty if valid)
     */
    private function validateRow(array $data, array $existingEmails, array $seenEmails, array $customFieldDefs): array
    {
        $errors = [];

        // Required fields
        foreach (self::REQUIRED_COLUMNS as $col) {
            if (empty($data[$col] ?? '')) {
                $errors[] = "Missing required field: {$col}";
            }
        }

        // Email format
        $email = strtolower(trim($data['email'] ?? ''));
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid email format: {$email}";
            } elseif (isset($existingEmails[$email])) {
                $errors[] = "Email already exists in database: {$email}";
            } elseif (isset($seenEmails[$email])) {
                $errors[] = "Duplicate email in CSV (first seen row {$seenEmails[$email]}): {$email}";
            }
        }

        // Date format (dob)
        $dob = $data['dob'] ?? '';
        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            $errors[] = "Invalid date format for dob (expected YYYY-MM-DD): {$dob}";
        }

        // Gender
        $gender = $data['gender'] ?? '';
        if ($gender !== '' && !in_array($gender, ['male', 'female', 'other', 'prefer_not_to_say'], true)) {
            $errors[] = "Invalid gender value: {$gender}";
        }

        // Custom field validation
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'custom_') && $value !== '') {
                $cfKey = substr($key, 7);
                if (isset($customFieldDefs[$cfKey])) {
                    $def = $customFieldDefs[$cfKey];
                    $cfError = $this->validateCustomFieldValue($def, $value);
                    if ($cfError) {
                        $errors[] = $cfError;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate a custom field value against its definition.
     */
    private function validateCustomFieldValue(array $def, string $value): ?string
    {
        $rules = $def['validation_rules'];
        if (is_string($rules)) {
            $rules = json_decode($rules, true) ?? [];
        }

        $type = $def['field_type'];
        $label = $def['label'];

        switch ($type) {
            case 'number':
                if (!is_numeric($value)) {
                    return "{$label}: must be a number";
                }
                if (isset($rules['min']) && (float) $value < (float) $rules['min']) {
                    return "{$label}: must be at least {$rules['min']}";
                }
                if (isset($rules['max']) && (float) $value > (float) $rules['max']) {
                    return "{$label}: must be at most {$rules['max']}";
                }
                break;

            case 'dropdown':
                $options = $rules['dropdown_options'] ?? [];
                if (!empty($options) && !in_array($value, $options, true)) {
                    return "{$label}: invalid option '{$value}'";
                }
                break;

            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    return "{$label}: invalid date format (expected YYYY-MM-DD)";
                }
                break;
        }

        return null;
    }

    /**
     * Load all existing member emails as a lookup set.
     */
    private function loadExistingEmails(): array
    {
        $rows = $this->db->fetchAll("SELECT LOWER(`email`) AS email FROM `members` WHERE `email` IS NOT NULL");
        $emails = [];
        foreach ($rows as $row) {
            $emails[$row['email']] = true;
        }
        return $emails;
    }

    /**
     * Load active custom field definitions keyed by field_key.
     */
    private function loadCustomFieldDefs(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM `custom_field_definitions` WHERE `is_active` = 1"
        );
        $defs = [];
        foreach ($rows as $row) {
            $defs[$row['field_key']] = $row;
        }
        return $defs;
    }

    /**
     * Generate a unique membership number.
     */
    private function generateMembershipNumber(): string
    {
        $row = $this->db->fetchOne(
            "SELECT MAX(CAST(SUBSTRING(membership_number, 4) AS UNSIGNED)) AS max_num FROM `members`"
        );
        $next = ((int) ($row['max_num'] ?? 0)) + 1;
        return 'SK-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
