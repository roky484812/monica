<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportFileService
{
    /**
     * Maximum file size in bytes (10 MB).
     */
    private const MAX_FILE_SIZE = 10485760;

    /**
     * Allowed MIME types for CSV files.
     */
    private const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'text/comma-separated-values',
        'application/vnd.ms-excel',
    ];

    /**
     * Store an uploaded CSV file.
     *
     * @return array{filename: string, file_path: string}
     *
     * @throws \InvalidArgumentException
     */
    public function store(UploadedFile $file, string $accountId): array
    {
        $this->validateFile($file);

        $filename = $this->generateUniqueFilename($file->getClientOriginalName());
        $directory = $accountId;
        $path = Storage::disk('imports')->putFileAs($directory, $file, $filename);

        return [
            'filename' => $file->getClientOriginalName(),
            'file_path' => $path,
        ];
    }

    /**
     * Retrieve file contents from storage.
     *
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function get(string $filePath): string
    {
        if (! Storage::disk('imports')->exists($filePath)) {
            throw new \Illuminate\Contracts\Filesystem\FileNotFoundException("File not found: {$filePath}");
        }

        return Storage::disk('imports')->get($filePath);
    }

    /**
     * Delete file from storage.
     */
    public function delete(string $filePath): bool
    {
        if (Storage::disk('imports')->exists($filePath)) {
            return Storage::disk('imports')->delete($filePath);
        }

        return false;
    }

    /**
     * Get the full path to a file in storage.
     */
    public function path(string $filePath): string
    {
        return Storage::disk('imports')->path($filePath);
    }

    /**
     * Validate uploaded file.
     *
     *
     * @throws \InvalidArgumentException
     */
    private function validateFile(UploadedFile $file): void
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size of 10MB.');
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'csv') {
            throw new \InvalidArgumentException('Only CSV files are allowed.');
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException('Invalid file type. Only CSV files are allowed.');
        }
    }

    /**
     * Generate a unique filename.
     */
    private function generateUniqueFilename(string $originalFilename): string
    {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $timestamp = now()->format('YmdHis');
        $random = Str::random(8);

        return "{$timestamp}_{$random}.{$extension}";
    }
}
