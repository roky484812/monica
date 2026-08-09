<?php

namespace App\Jobs;

use App\Domains\Contact\ManageContact\Services\CreateContact;
use App\Models\ImportError;
use App\Models\ImportJob;
use App\Models\User;
use App\Models\Vault;
use App\Services\CsvValidatorService;
use App\Services\ImportFileService;
use App\Validators\ContactRowValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process contact import from CSV file.
 *
 * RETRY SAFETY STRATEGY:
 * ----------------------
 * This job is designed to be safely retried if it fails during processing.
 *
 * 1. RESUME FROM LAST PROCESSED ROW:
 *    - The job tracks 'last_processed_row' in the database after each row.
 *    - On retry, it resumes from this row number instead of starting over.
 *    - This prevents reprocessing successfully imported contacts.
 *
 * 2. IDEMPOTENCY CHECKS:
 *    - Before creating a contact, we check if a duplicate already exists.
 *    - Duplicates are identified by matching first_name + last_name in the vault.
 *    - Duplicate rows are marked as failed with a specific error message.
 *
 * 3. DATABASE TRANSACTIONS:
 *    - Each row is processed within a database transaction.
 *    - If contact creation fails, the transaction rolls back automatically.
 *    - This ensures atomic row processing (all or nothing).
 *
 * 4. ERROR ISOLATION:
 *    - Each row's processing is wrapped in try-catch.
 *    - Errors in one row don't affect other rows.
 *    - All errors are recorded in the import_errors table.
 *
 * 5. PROGRESS TRACKING:
 *    - Progress is updated after each chunk (50 rows).
 *    - This allows monitoring and provides accurate status.
 *
 * FAILURE HANDLING:
 * -----------------
 * - The job will retry up to 3 times with 60-second backoff.
 * - After 3 failed attempts, the failed() method is called.
 * - The import job status is set to 'failed' with the error message.
 */
class ProcessContactImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * The import job instance.
     */
    protected ImportJob $importJob;

    /**
     * The vault to import contacts into.
     */
    protected string $vaultId;

    /**
     * Chunk size for processing rows.
     */
    protected int $chunkSize = 50;

    /**
     * Create a new job instance.
     */
    public function __construct(ImportJob $importJob, string $vaultId)
    {
        $this->importJob = $importJob;
        $this->vaultId = $vaultId;
    }

    /**
     * Execute the job.
     */
    public function handle(
        CsvValidatorService $csvValidator,
        ImportFileService $fileService,
        ContactRowValidator $rowValidator
    ): void {
        try {
            Log::info('Starting contact import', [
                'import_job_id' => $this->importJob->id,
                'vault_id' => $this->vaultId,
            ]);

            // Update status to processing
            $this->importJob->update([
                'status' => ImportJob::STATUS_PROCESSING,
                'started_at' => now(),
            ]);

            // Get file path
            $filePath = $fileService->path($this->importJob->file_path);

            // Validate file exists
            if (! file_exists($filePath)) {
                throw new \Exception("Import file not found: {$filePath}");
            }

            // Count total rows (only if not already set)
            if ($this->importJob->total_rows === 0) {
                $totalRows = $csvValidator->countRows($filePath);
                $this->importJob->update(['total_rows' => $totalRows]);
            } else {
                $totalRows = $this->importJob->total_rows;
            }

            Log::info('CSV file validated', [
                'import_job_id' => $this->importJob->id,
                'total_rows' => $totalRows,
                'resuming_from_row' => $this->importJob->last_processed_row,
            ]);

            // Resume from last processed row (for retries)
            $startRow = $this->importJob->last_processed_row;
            $processedRows = $this->importJob->processed_rows;
            $failedRows = $this->importJob->failed_rows;
            $offset = $startRow;

            while ($offset < $totalRows) {
                $rows = $csvValidator->readRows($filePath, $offset, $this->chunkSize);

                foreach ($rows as $rowInfo) {
                    $rowNumber = $rowInfo['row_number'];
                    $rowData = $rowInfo['data'];

                    // Skip if already processed (safety check)
                    if ($rowNumber <= $startRow) {
                        continue;
                    }

                    try {
                        $success = $this->processRow($rowData, $rowNumber, $rowValidator);
                        if (! $success) {
                            $failedRows++;
                        }
                    } catch (\Exception $e) {
                        // Log error and continue
                        $this->recordError($rowNumber, $rowData, $e->getMessage());
                        $failedRows++;

                        Log::warning('Row processing failed', [
                            'import_job_id' => $this->importJob->id,
                            'row_number' => $rowNumber,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $processedRows++;

                    // Update last processed row after each successful iteration
                    $this->importJob->update([
                        'last_processed_row' => $rowNumber,
                    ]);
                }

                // Update progress after each chunk
                $this->importJob->update([
                    'processed_rows' => $processedRows,
                    'failed_rows' => $failedRows,
                ]);

                Log::info('Chunk processed', [
                    'import_job_id' => $this->importJob->id,
                    'processed_rows' => $processedRows,
                    'failed_rows' => $failedRows,
                ]);

                $offset += $this->chunkSize;
            }

            // Mark as completed
            $this->importJob->update([
                'status' => ImportJob::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            Log::info('Contact import completed', [
                'import_job_id' => $this->importJob->id,
                'processed_rows' => $processedRows,
                'failed_rows' => $failedRows,
            ]);
        } catch (\Exception $e) {
            // Mark as failed
            $this->importJob->update([
                'status' => ImportJob::STATUS_FAILED,
                'failure_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            Log::error('Contact import failed', [
                'import_job_id' => $this->importJob->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Process a single row from the CSV.
     *
     * @return bool Success status
     */
    protected function processRow(array $rowData, int $rowNumber, ContactRowValidator $rowValidator): bool
    {
        // Use database transaction for atomic row processing
        return \DB::transaction(function () use ($rowData, $rowNumber, $rowValidator) {
            // Sanitize row data
            $sanitizedData = $rowValidator->sanitize($rowData);

            // Validate row
            $validation = $rowValidator->validate($sanitizedData, $rowNumber);

            if (! $validation['valid']) {
                $this->recordError($rowNumber, $sanitizedData, implode(', ', $validation['errors']));

                return false;
            }

            // Check for duplicate contact (idempotency check)
            if ($this->isDuplicateContact($sanitizedData)) {
                $this->recordError($rowNumber, $sanitizedData, 'Contact already exists with the same first name and last name in this vault.');

                return false;
            }

            // Create contact
            try {
                $this->createContact($sanitizedData);

                return true;
            } catch (\Exception $e) {
                $this->recordError($rowNumber, $sanitizedData, "Contact creation failed: {$e->getMessage()}");

                return false;
            }
        });
    }

    /**
     * Check if a contact with the same name already exists in the vault.
     */
    protected function isDuplicateContact(array $rowData): bool
    {
        $vault = Vault::findOrFail($this->vaultId);

        // Check for existing contact with same first_name and last_name
        $query = $vault->contacts()
            ->where('first_name', $rowData['first_name']);

        if (! empty($rowData['last_name'])) {
            $query->where('last_name', $rowData['last_name']);
        } else {
            $query->whereNull('last_name');
        }

        return $query->exists();
    }

    /**
     * Create a contact from row data.
     *
     *
     * @throws \Exception
     */
    protected function createContact(array $rowData): void
    {
        $vault = Vault::findOrFail($this->vaultId);
        $user = User::findOrFail($this->importJob->user_id);

        $service = app(CreateContact::class);
        $service->execute([
            'account_id' => $this->importJob->account_id,
            'author_id' => $user->id,
            'vault_id' => $vault->id,
            'first_name' => $rowData['first_name'],
            'last_name' => $rowData['last_name'] ?? null,
            'middle_name' => $rowData['middle_name'] ?? null,
            'nickname' => $rowData['nickname'] ?? null,
        ]);

        // TODO: Add email and phone number after contact creation
        // These require additional services to create contact information
    }

    /**
     * Record an error for a specific row.
     */
    protected function recordError(int $rowNumber, array $rowData, string $errorMessage): void
    {
        ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => $rowNumber,
            'row_data' => $rowData,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'import',
            'contact-import',
            "import-job:{$this->importJob->id}",
            "account:{$this->importJob->account_id}",
        ];
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->importJob->update([
            'status' => ImportJob::STATUS_FAILED,
            'failure_message' => $exception->getMessage(),
            'completed_at' => now(),
        ]);

        Log::error('Contact import job failed permanently', [
            'import_job_id' => $this->importJob->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
