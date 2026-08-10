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

class ImportRetryTest extends TestCase
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
    public function retry_does_not_duplicate_contacts(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\nBob,Jones\n");
        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        // Run the job once, then simulate a retry by resetting status and dispatching again.
        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $importJob->refresh();
        $this->assertEquals(2, $importJob->processed_rows);
        $this->assertDatabaseCount('contacts', 3); // original user contact + 2 imported

        $importJob->update(['status' => ImportJob::STATUS_PROCESSING]);
        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $contactCount = Contact::where('vault_id', $this->vault->id)
            ->whereIn('first_name', ['Alice', 'Bob'])
            ->count();

        $this->assertEquals(2, $contactCount);
    }

    #[Test]
    public function retry_updates_processed_rows_without_duplicates(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\nBob,Jones\n");
        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        // Simulate that the first row was already processed on a previous run.
        Contact::factory()->create([
            'vault_id' => $this->vault->id,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'listed' => true,
        ]);

        $importJob->update([
            'processed_rows' => 1,
            'last_processed_row' => 1,
            'failed_rows' => 0,
            'total_rows' => 2,
            'status' => ImportJob::STATUS_PROCESSING,
        ]);

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $importJob->refresh();
        $this->assertEquals(2, $importJob->processed_rows);
        $this->assertEquals(ImportJob::STATUS_COMPLETED, $importJob->status);
        $this->assertEquals(2, Contact::where('vault_id', $this->vault->id)
            ->whereIn('first_name', ['Alice', 'Bob'])
            ->count());
    }

    private function storeCsv(string $content, string $filename = 'test.csv'): string
    {
        $path = $this->account->id.'/'.$filename;
        Storage::disk('imports')->put($path, $content);

        return $path;
    }

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
