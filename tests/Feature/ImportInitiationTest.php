<?php

namespace Tests\Feature;

use App\Jobs\ProcessContactImport;
use App\Models\Account;
use App\Models\ImportJob;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportInitiationTest extends TestCase
{
    use RefreshDatabase;

    protected Account $account;

    protected User $user;

    protected Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->account = Account::factory()->create();
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
        ]);
        $this->vault = Vault::factory()->create([
            'account_id' => $this->account->id,
        ]);

        // Create a contact for the user in the vault
        $userContact = \App\Models\Contact::factory()->create([
            'vault_id' => $this->vault->id,
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
        ]);

        // Attach user to vault with editor permission (required for imports)
        $this->vault->users()->attach($this->user->id, [
            'permission' => Vault::PERMISSION_EDIT,
            'contact_id' => $userContact->id,
        ]);

        // Configure storage
        Storage::fake('imports');
    }

    /** @test */
    public function authenticated_user_can_upload_csv()
    {
        Queue::fake();

        $csv = $this->createValidCsv();

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $csv,
                'vault_id' => $this->vault->id,
            ]);

        // Debug: print response if it fails
        if ($response->status() !== 201) {
            dump($response->json());
        }

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'filename',
                    'status',
                    'total_rows',
                    'processed_rows',
                    'failed_rows',
                    'progress_pct',
                    'created_at',
                ],
            ]);

        // Verify import job was created
        $this->assertDatabaseHas('import_jobs', [
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'vault_id' => $this->vault->id,
            'status' => ImportJob::STATUS_PENDING,
        ]);

        // Verify job was dispatched
        Queue::assertPushed(ProcessContactImport::class);
    }

    /** @test */
    public function unauthenticated_user_receives_401()
    {
        $csv = $this->createValidCsv();

        $response = $this->postJson('/api/imports', [
            'file' => $csv,
            'vault_id' => $this->vault->id,
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function non_csv_file_is_rejected()
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $file,
                'vault_id' => $this->vault->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /** @test */
    public function oversized_file_is_rejected()
    {
        // Create file larger than 10MB
        $file = UploadedFile::fake()->create('contacts.csv', 11000, 'text/csv');

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $file,
                'vault_id' => $this->vault->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /** @test */
    public function import_requires_vault_id()
    {
        $csv = $this->createValidCsv();

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $csv,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('vault_id');
    }

    /** @test */
    public function user_cannot_import_to_vault_from_different_account()
    {
        $otherAccount = Account::factory()->create();
        $otherVault = Vault::factory()->create([
            'account_id' => $otherAccount->id,
        ]);

        $csv = $this->createValidCsv();

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $csv,
                'vault_id' => $otherVault->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('vault_id');
    }

    /** @test */
    public function import_record_is_created_in_database()
    {
        Queue::fake();

        $csv = $this->createValidCsv();

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $csv,
                'vault_id' => $this->vault->id,
            ]);

        $response->assertStatus(201);

        $importJob = ImportJob::first();

        $this->assertNotNull($importJob);
        $this->assertEquals($this->account->id, $importJob->account_id);
        $this->assertEquals($this->user->id, $importJob->user_id);
        $this->assertEquals($this->vault->id, $importJob->vault_id);
        $this->assertEquals(ImportJob::STATUS_PENDING, $importJob->status);
        $this->assertStringContainsString('.csv', $importJob->filename);
    }

    /** @test */
    public function job_is_dispatched_to_queue()
    {
        Queue::fake();

        $csv = $this->createValidCsv();

        $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $csv,
                'vault_id' => $this->vault->id,
            ]);

        Queue::assertPushed(ProcessContactImport::class, function ($job) {
            return $job->importJob instanceof ImportJob;
        });
    }

    /** @test */
    public function response_has_correct_structure()
    {
        Queue::fake();

        $csv = $this->createValidCsv();

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $csv,
                'vault_id' => $this->vault->id,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'status' => ImportJob::STATUS_PENDING,
                    'total_rows' => 0,
                    'processed_rows' => 0,
                    'failed_rows' => 0,
                    'progress_pct' => 0,
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'filename',
                    'status',
                    'total_rows',
                    'processed_rows',
                    'failed_rows',
                    'progress_pct',
                    'started_at',
                    'completed_at',
                    'created_at',
                ],
            ]);
    }

    /**
     * Helper method to create a valid CSV file for testing.
     */
    protected function createValidCsv(): UploadedFile
    {
        $csvContent = "first_name,last_name,email,phone\n";
        $csvContent .= "John,Doe,john.doe@example.com,1234567890\n";
        $csvContent .= "Jane,Smith,jane.smith@example.com,0987654321\n";
        $csvContent .= "Bob,Johnson,bob.johnson@example.com,\n";

        $tmpPath = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tmpPath, $csvContent);

        return new UploadedFile($tmpPath, 'contacts.csv', 'text/csv', null, true);
    }
}
