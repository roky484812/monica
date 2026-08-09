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

            // Count total rows
            $totalRows = $csvValidator->countRows($filePath);
            $this->importJob->update(['total_rows' => $totalRows]);

            Log::info('CSV file validated', [
                'import_job_id' => $this->importJob->id,
                'total_rows' => $totalRows,
            ]);

            // Process CSV in chunks
            $processedRows = 0;
            $failedRows = 0;
            $offset = 0;

            while ($offset < $totalRows) {
                $rows = $csvValidator->readRows($filePath, $offset, $this->chunkSize);

                foreach ($rows as $rowInfo) {
                    $rowNumber = $rowInfo['row_number'];
                    $rowData = $rowInfo['data'];

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
        // Sanitize row data
        $sanitizedData = $rowValidator->sanitize($rowData);

        // Validate row
        $validation = $rowValidator->validate($sanitizedData, $rowNumber);

        if (! $validation['valid']) {
            $this->recordError($rowNumber, $sanitizedData, implode(', ', $validation['errors']));

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
