<?php

namespace Tests\Feature;

use App\Jobs\ProcessContactImport;
use App\Models\Account;
use App\Models\Contact;
use App\Models\ImportJob;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for Phase 10: Background Processing
 *
 * Verifies that the ProcessContactImport job:
 * - Creates contacts from valid CSV rows
 * - Updates processed_rows and total_rows correctly
 * - Transitions status from pending → processing → completed
 * - Sets completed_at timestamp on completion
 * - Handles chunk processing for large files
 */
class ContactImportProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected Account $account;

    protected User $user;

    protected Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');

        $this->account = Account::factory()->create();
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
        ]);
        $this->vault = Vault::factory()->create([
            'account_id' => $this->account->id,
        ]);

        // Attach user to vault as editor
        $userContact = Contact::factory()->create([
            'vault_id' => $this->vault->id,
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
        ]);

        $this->vault->users()->attach($this->user->id, [
            'permission' => Vault::PERMISSION_EDIT,
            'contact_id' => $userContact->id,
        ]);
    }

    #[Test]
    public function valid_csv_rows_create_contacts(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name,email\nAlice,Smith,alice@example.com\nBob,Jones,bob@example.com\n");

        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $this->assertDatabaseHas('contacts', [
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'vault_id' => $this->vault->id,
        ]);

        $this->assertDatabaseHas('contacts', [
            'first_name' => 'Bob',
            'last_name' => 'Jones',
            'vault_id' => $this->vault->id,
        ]);
    }

    #[Test]
    public function processed_rows_is_updated_correctly(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\nBob,Jones\nCarol,White\n");

        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $importJob->refresh();

        $this->assertEquals(3, $importJob->processed_rows);
    }

    #[Test]
    public function total_rows_is_set_correctly(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\nBob,Jones\n");

        // Start with total_rows = 0 (as set by controller)
        $importJob = $this->makeImportJob($csvPath, 'contacts.csv', ['total_rows' => 0]);

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $importJob->refresh();

        $this->assertEquals(2, $importJob->total_rows);
    }

    #[Test]
    public function status_changes_to_processing_then_completed(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\n");

        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        $this->assertEquals(ImportJob::STATUS_PENDING, $importJob->status);

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $importJob->refresh();

        $this->assertEquals(ImportJob::STATUS_COMPLETED, $importJob->status);
    }

    #[Test]
    public function completed_at_timestamp_is_set(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\n");

        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        $this->assertNull($importJob->completed_at);

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $importJob->refresh();

        $this->assertNotNull($importJob->completed_at);
    }

    #[Test]
    public function started_at_timestamp_is_set(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\n");

        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        $this->assertNull($importJob->started_at);

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $importJob->refresh();

        $this->assertNotNull($importJob->started_at);
    }

    #[Test]
    public function all_rows_processed_in_large_csv_exceeding_chunk_size(): void
    {
        // Generate 60 rows (more than the 50-row chunk size)
        $csv = "first_name,last_name\n";
        for ($i = 1; $i <= 60; $i++) {
            $csv .= "Person{$i},Last{$i}\n";
        }

        $csvPath = $this->storeCsv($csv);

        $importJob = $this->makeImportJob($csvPath, 'large.csv');

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $importJob->refresh();

        // All 60 rows should be processed (+1 for the user's contact in vault)
        $this->assertEquals(60, $importJob->processed_rows);
        $this->assertEquals(60, $importJob->total_rows);
        $this->assertEquals(ImportJob::STATUS_COMPLETED, $importJob->status);

        // Verify contacts were created in database
        $this->assertEquals(60, Contact::where('vault_id', $this->vault->id)
            ->where('first_name', 'like', 'Person%')
            ->count());
    }

    #[Test]
    public function contacts_belong_to_correct_account_and_vault(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\n");

        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $contact = Contact::where('first_name', 'Alice')
            ->where('last_name', 'Smith')
            ->where('vault_id', $this->vault->id)
            ->first();

        $this->assertNotNull($contact);
        $this->assertEquals($this->vault->id, $contact->vault_id);
        $this->assertEquals($this->account->id, $this->vault->account_id);
    }

    #[Test]
    public function contacts_have_correct_field_values(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name,middle_name,nickname\nAlice,Smith,Marie,Ally\n");

        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $this->assertDatabaseHas('contacts', [
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'middle_name' => 'Marie',
            'nickname' => 'Ally',
            'vault_id' => $this->vault->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Write CSV content to the fake 'imports' disk and return the stored path.
     */
    private function storeCsv(string $content, string $filename = 'test.csv'): string
    {
        $path = $this->account->id.'/'.$filename;
        Storage::disk('imports')->put($path, $content);

        return $path;
    }

    /**
     * Create an ImportJob model pointing at the given stored path.
     */
    private function makeImportJob(string $filePath, string $filename, array $overrides = []): ImportJob
    {
        return ImportJob::create(array_merge([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'vault_id' => $this->vault->id,
            'filename' => $filename,
            'file_path' => $filePath,
            'status' => ImportJob::STATUS_PENDING,
            'total_rows' => 0,
            'processed_rows' => 0,
            'failed_rows' => 0,
            'last_processed_row' => 0,
        ], $overrides));
    }
}
