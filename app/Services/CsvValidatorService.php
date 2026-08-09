<?php

namespace App\Services;

class CsvValidatorService
{
    /**
     * Required CSV headers.
     */
    private const REQUIRED_HEADERS = ['first_name'];

    /**
     * Optional CSV headers.
     */
    private const OPTIONAL_HEADERS = [
        'last_name',
        'middle_name',
        'nickname',
        'email',
        'phone',
    ];

    /**
     * Validate CSV file structure and return information.
     *
     * @return array{valid: bool, total_rows: int, headers: array, errors: array}
     */
    public function validate(string $filePath): array
    {
        $errors = [];

        // Check if file exists and is readable
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return [
                'valid' => false,
                'total_rows' => 0,
                'headers' => [],
                'errors' => ['File does not exist or is not readable.'],
            ];
        }

        // Open file
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [
                'valid' => false,
                'total_rows' => 0,
                'headers' => [],
                'errors' => ['Failed to open CSV file.'],
            ];
        }

        // Read headers
        $headers = fgetcsv($handle);
        if ($headers === false || empty($headers)) {
            fclose($handle);

            return [
                'valid' => false,
                'total_rows' => 0,
                'headers' => [],
                'errors' => ['CSV file is empty or has no headers.'],
            ];
        }

        // Normalize headers (trim and lowercase)
        $headers = array_map(fn ($header) => strtolower(trim($header)), $headers);

        // Validate required headers
        foreach (self::REQUIRED_HEADERS as $requiredHeader) {
            if (! in_array($requiredHeader, $headers)) {
                $errors[] = "Missing required header: {$requiredHeader}";
            }
        }

        // Count total data rows (excluding header)
        $totalRows = 0;
        while (fgetcsv($handle) !== false) {
            $totalRows++;
        }

        fclose($handle);

        // Check if there are any data rows
        if ($totalRows === 0) {
            $errors[] = 'CSV file contains no data rows.';
        }

        return [
            'valid' => empty($errors),
            'total_rows' => $totalRows,
            'headers' => $headers,
            'errors' => $errors,
        ];
    }

    /**
     * Count total rows in CSV file (excluding header).
     */
    public function countRows(string $filePath): int
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return 0;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return 0;
        }

        // Skip header row
        fgetcsv($handle);

        $count = 0;
        while (fgetcsv($handle) !== false) {
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * Get CSV headers.
     */
    public function getHeaders(string $filePath): array
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return [];
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        fclose($handle);

        if ($headers === false || empty($headers)) {
            return [];
        }

        // Normalize headers
        return array_map(fn ($header) => strtolower(trim($header)), $headers);
    }

    /**
     * Read CSV file and return rows as associative arrays.
     */
    public function readRows(string $filePath, int $offset = 0, int $limit = 50): array
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return [];
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        // Read headers
        $headers = fgetcsv($handle);
        if ($headers === false || empty($headers)) {
            fclose($handle);

            return [];
        }

        // Normalize headers
        $headers = array_map(fn ($header) => strtolower(trim($header)), $headers);

        $rows = [];
        $currentRow = 0;

        // Skip rows until offset
        while ($currentRow < $offset && fgetcsv($handle) !== false) {
            $currentRow++;
        }

        // Read rows up to limit
        $readCount = 0;
        while ($readCount < $limit && ($data = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $data[$index] ?? '';
            }
            $rows[] = [
                'row_number' => $currentRow + 1, // 1-indexed, excluding header
                'data' => $row,
            ];
            $currentRow++;
            $readCount++;
        }

        fclose($handle);

        return $rows;
    }
}
