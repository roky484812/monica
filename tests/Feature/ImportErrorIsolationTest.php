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
use Tests\TestCase;

class ImportErrorIsolationTest extends TestCase
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

    /** @test */
    public function invalid_rows_are_recorded_and_processing_continues(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name,email\n,Smith,invalid@example.com\nAlice,Johnson,alice@example.com\n");

        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        ProcessContactImport::dispatchSync($importJob, $this->vault->id);

        $importJob->refresh();

        $this->assertEquals(2, $importJob->processed_rows);
        $this->assertEquals(1, $importJob->failed_rows);
        $this->assertEquals(ImportJob::STATUS_COMPLETED, $importJob->status);

        $this->assertDatabaseHas('contacts', [
            'first_name' => 'Alice',
            'last_name' => 'Johnson',
            'vault_id' => $this->vault->id,
        ]);

        $this->assertDatabaseMissing('contacts', [
            'first_name' => null,
            'last_name' => 'Smith',
            'vault_id' => $this->vault->id,
        ]);

        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $importJob->id,
            'row_number' => 1,
        ]);

        $error = \App\Models\ImportError::where('import_job_id', $importJob->id)
            ->where('row_number', 1)
            ->first();

        $this->assertNotNull($error);
        $this->assertStringContainsString('First name is required', $error->error_message);
        $this->assertEquals(['first_name' => null, 'last_name' => 'Smith', 'email' => 'invalid@example.com'], $error->row_data);
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
