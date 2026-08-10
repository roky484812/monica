<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\ImportJob;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportProgressTest extends TestCase
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
    public function progress_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/imports/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(401);
    }

    /** @test */
    public function user_can_view_their_own_import_progress(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\n");
        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        $response = $this->actingAs($this->user)
            ->getJson("/api/imports/{$importJob->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $importJob->id,
                    'status' => ImportJob::STATUS_PENDING,
                    'total_rows' => 0,
                    'processed_rows' => 0,
                    'failed_rows' => 0,
                    'progress_pct' => 0,
                ],
            ]);
    }

    /** @test */
    public function progress_endpoint_returns_404_for_missing_import(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/imports/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }

    /** @test */
    public function user_cannot_view_import_from_other_account(): void
    {
        $otherAccount = Account::factory()->create();
        $otherUser = User::factory()->create(['account_id' => $otherAccount->id]);
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\n");
        $importJob = $this->makeImportJob($csvPath, 'contacts.csv');

        $response = $this->actingAs($otherUser)
            ->getJson("/api/imports/{$importJob->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function progress_endpoint_returns_current_processing_state(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\nBob,Jones\n");
        $importJob = $this->makeImportJob($csvPath, 'contacts.csv', [
            'total_rows' => 2,
            'processed_rows' => 1,
            'failed_rows' => 0,
            'status' => ImportJob::STATUS_PROCESSING,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/imports/{$importJob->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => ImportJob::STATUS_PROCESSING,
                    'total_rows' => 2,
                    'processed_rows' => 1,
                    'failed_rows' => 0,
                    'progress_pct' => 50,
                ],
            ]);
    }

    /** @test */
    public function progress_pct_is_calculated_correctly(): void
    {
        $csvPath = $this->storeCsv("first_name,last_name\nAlice,Smith\nBob,Jones\n");
        $importJob = $this->makeImportJob($csvPath, 'contacts.csv', [
            'total_rows' => 2,
            'processed_rows' => 1,
            'failed_rows' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/imports/{$importJob->id}");

        $response->assertStatus(200)
            ->assertJson(['data' => ['progress_pct' => 50]]);
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
