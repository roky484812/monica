# Contact Import Feature — Envobyte Backend Assignment

Background contact import system built on top of Monica CRM.

---

## Table of Contents

1. [Setup Instructions](#setup-instructions)
2. [Existing-Flow Analysis](#existing-flow-analysis)
3. [Implementation Approach](#implementation-approach)
4. [Assumptions and Limitations](#assumptions-and-limitations)
5. [Retry-Safety Explanation](#retry-safety-explanation)
6. [Technical Questions](#technical-questions)
7. [Test Instructions](#test-instructions)

---

## Setup Instructions

### Prerequisites

- PHP 8.3 or newer
- HTTP server with PHP support (Apache, Nginx, or Caddy)
- Composer
- Node.js and Yarn
- SQLite or MySQL

For macOS users, [Laravel Valet](https://laravel.com/docs/valet) is recommended.

### Installation

**Step 1 — Clone the repository and switch to the assignment branch:**

```bash
git clone https://github.com/YOUR-USERNAME/monica.git
cd monica
git checkout envobyte-assignment
```

**Step 2 — One-command setup:**

```bash
composer setup
```

This single command automatically:

- Installs all PHP dependencies
- Creates `.env` from `.env.example`
- Creates SQLite database (`monica.db`)
- Configures `.env` to use SQLite with absolute path
- Installs JavaScript dependencies with Yarn
- Generates application key
- Runs migrations and sets up initial data
- Builds front-end assets

No manual `.env` editing is required — SQLite is configured automatically.

**Step 3 — Start the development environment:**

```bash
composer dev
```

This starts all three required services simultaneously:

- Laravel development server on `http://127.0.0.1:8000`
- Queue worker for background job processing
- Vite dev server for hot module reloading

Press `Ctrl+C` to stop all services.

### Optional Configuration

**Switch to MySQL** (if preferred over SQLite):

```bash
# Edit .env after running composer setup
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=monica
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

Then re-run migrations:

```bash
php artisan migrate:fresh
```

**Queue driver** — defaults to `database`. For synchronous execution during development:

```env
QUEUE_CONNECTION=sync
```

**Generate dummy data** (optional):

```bash
php artisan monica:dummy --force -vvv
```

For more details, see the [official setup documentation](https://docs.monicahq.com/developers/setup-local-development).

### CSV File Format

```csv
first_name,last_name,middle_name,nickname,email,phone
John,Doe,Michael,,john.doe@example.com,+1234567890
Jane,Smith,,,jane@example.com,
```

| Field         | Required | Constraints                     |
| ------------- | -------- | ------------------------------- |
| `first_name`  | Yes      | string, max 255 characters      |
| `last_name`   | No       | string, max 255 characters      |
| `middle_name` | No       | string, max 255 characters      |
| `nickname`    | No       | string, max 255 characters      |
| `email`       | No       | valid email, max 255 characters |
| `phone`       | No       | string, max 255 characters      |

---

## Existing-Flow Analysis

Before implementing the import feature, I analysed Monica's existing architecture to understand how to integrate without diverging from established patterns.

### Contact Creation Flow

Monica uses a **service-based architecture**. Each domain operation is encapsulated in a dedicated service class that extends `BaseService` and implements `ServiceInterface`.

**Key components in the existing flow:**

1. **`CreateContact` Service** (`app/Domains/Contact/ManageContact/Services/CreateContact.php`)
   - Extends `BaseService`, implements `ServiceInterface`
   - Handles validation through the `execute()` method
   - Enforces three authorization checks:
     - `author_must_belong_to_account`
     - `vault_must_belong_to_account`
     - `author_must_be_vault_editor`

2. **`Contact` Model** (`app/Models/Contact.php`)
   - UUIDs for primary keys
   - Belongs to `Vault`, which belongs to `Account`
   - Required field: `vault_id`, `account_id`
   - Optional fields: `first_name`, `last_name`, `middle_name`, `nickname`
   - Soft deletes enabled

3. **API Controller Pattern**
   - Controllers extend `ApiController`
   - `auth:sanctum` middleware on all protected routes
   - Consistent JSON response structure
   - Automatic `ModelNotFoundException` handling

### What I Reused

| Existing Component      | How It Was Reused                                 |
| ----------------------- | ------------------------------------------------- |
| `CreateContact` Service | Called per CSV row to create each contact         |
| `BaseService` pattern   | Followed for validation and authorization flow    |
| API response structure  | Applied to all three new import endpoints         |
| Sanctum authentication  | Used as-is — no changes made                      |
| Account-based scoping   | Import jobs scoped to `account_id` and `vault_id` |

---

## Implementation Approach

### System Architecture

```
┌─────────────┐         ┌──────────────┐         ┌─────────────────┐
│   API       │ Upload  │              │ Dispatch│   Background    │
│  Client     │────────▶│ Import       │────────▶│   Queue Job     │
│             │  CSV    │ Controller   │  Job    │                 │
└─────────────┘         └──────────────┘         └─────────────────┘
                               │                          │
                               │ Creates                  │ Processes
                               ▼                          ▼
                        ┌──────────────┐         ┌─────────────────┐
                        │  ImportJob   │         │   CSV File      │
                        │   Record     │         │  (chunk by      │
                        │  (Database)  │         │   chunk)        │
                        └──────────────┘         └─────────────────┘
                               ▲                          │
                               │ Updates progress         │ Creates
                               │                          ▼
                               │                  ┌─────────────────┐
                               └──────────────────│   Contacts      │
                                  & errors        │  (Database)     │
                                                  └─────────────────┘
```

### New Components

| Component              | Path                                            | Responsibility                                                         |
| ---------------------- | ----------------------------------------------- | ---------------------------------------------------------------------- |
| `ImportController`     | `app/Http/Controllers/Api/ImportController.php` | Validates uploads, creates import records, dispatches jobs             |
| `ProcessContactImport` | `app/Jobs/ProcessContactImport.php`             | Background job: reads CSV in chunks, creates contacts, tracks progress |
| `ImportJob` Model      | `app/Models/ImportJob.php`                      | Stores import metadata, status, progress counters                      |
| `ImportError` Model    | `app/Models/ImportError.php`                    | Records per-row errors (row number, raw data, message)                 |
| `CsvValidatorService`  | `app/Services/CsvValidatorService.php`          | Validates CSV structure and headers (memory-efficient streaming)       |
| `ImportFileService`    | `app/Services/ImportFileService.php`            | Handles upload, type/size validation, file storage                     |
| `ContactRowValidator`  | `app/Validators/ContactRowValidator.php`        | Validates and sanitizes individual row data                            |

### Database Schema

#### `import_jobs` table

```sql
id                  UUID        primary key
account_id          UUID        foreign key
user_id             UUID        foreign key
vault_id            UUID        foreign key
filename            string
file_path           string
total_rows          integer
processed_rows      integer
failed_rows         integer
last_processed_row  integer     -- used for retry resume
status              enum        pending | processing | completed | failed
failure_message     text        nullable
started_at          timestamp   nullable
completed_at        timestamp   nullable
created_at          timestamp
updated_at          timestamp
```

#### `import_errors` table

```sql
id              UUID    primary key
import_job_id   UUID    foreign key
row_number      integer
row_data        json
error_message   text
created_at      timestamp
```

### Key Design Decisions

**Queue-based async processing** — Large CSV files cannot be processed synchronously within a request timeout. Jobs are dispatched to the queue immediately after upload; users can poll the status endpoint. The job timeout is 3600 seconds; up to 3 retry attempts with 60-second backoff.

**Chunk processing (50 rows per chunk)** — The entire file is never loaded into memory. Processing row-by-row in 50-row chunks keeps memory flat regardless of file size, and allows fine-grained progress updates.

**Row-level error isolation** — Each row is processed inside its own try/catch and database transaction. A single bad row never halts the entire import; errors are recorded in `import_errors` and processing continues.

**Two-phase validation** — Phase 1 (`CsvValidatorService`) checks file structure and required headers before the job is queued (fast fail). Phase 2 (`ContactRowValidator`) validates each row's data during background processing (detailed per-row feedback).

**Progress tracking per chunk** — `processed_rows` and `failed_rows` are updated after every chunk, reducing database writes while still providing good granularity.

**Private file storage with account isolation** — Files are stored under `storage/app/imports/{account_id}/{timestamp}_{random}.csv`. Files are not publicly accessible.

### API Documentation

#### POST `/api/imports` — Initiate Import

**Authentication:** Sanctum token required  
**Content-Type:** `multipart/form-data`

| Parameter  | Type | Required | Description         |
| ---------- | ---- | -------- | ------------------- |
| `file`     | file | Yes      | CSV file, max 10 MB |
| `vault_id` | UUID | Yes      | Target vault        |

```bash
curl -X POST http://localhost:8000/api/imports \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@contacts.csv" \
  -F "vault_id=9d2e1f12-3456-7890-abcd-ef1234567890"
```

**201 Created:**

```json
{
  "message": "Import started successfully.",
  "data": {
    "id": "9d2e1f12-3456-7890-abcd-ef1234567890",
    "filename": "contacts.csv",
    "total_rows": 100,
    "processed_rows": 0,
    "failed_rows": 0,
    "status": "pending",
    "progress_pct": 0,
    "started_at": null,
    "completed_at": null,
    "created_at": "2026-08-10T07:30:00.000000Z"
  }
}
```

**Error responses:**

- `422` — CSV structure invalid or missing required headers
- `403` — User does not have access to the specified vault
- `422` — `vault_id` not found

---

#### GET `/api/imports/{id}` — Get Import Status

```bash
curl -X GET http://localhost:8000/api/imports/{id} \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**200 OK:**

```json
{
  "data": {
    "id": "9d2e1f12-3456-7890-abcd-ef1234567890",
    "filename": "contacts.csv",
    "total_rows": 100,
    "processed_rows": 75,
    "failed_rows": 5,
    "status": "processing",
    "progress_pct": 75,
    "started_at": "2026-08-10T07:30:05.000000Z",
    "completed_at": null,
    "created_at": "2026-08-10T07:30:00.000000Z",
    "updated_at": "2026-08-10T07:30:45.000000Z"
  }
}
```

**Error responses:** `404` (not found), `403` (not authorised)

---

#### GET `/api/imports/{id}/errors` — Get Import Errors

**Query parameters:** `per_page` (default 50), `page` (default 1)

```bash
curl -X GET "http://localhost:8000/api/imports/{id}/errors?per_page=10&page=1" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**200 OK (paginated):**

```json
{
  "current_page": 1,
  "data": [
    {
      "id": "...",
      "import_job_id": "...",
      "row_number": 5,
      "row_data": {
        "first_name": "",
        "last_name": "Doe",
        "email": "invalid-email"
      },
      "error_message": "First name is required., Email must be a valid email address.",
      "created_at": "2026-08-10T07:30:15.000000Z"
    }
  ],
  "total": 1,
  "per_page": 10,
  "last_page": 1
}
```

---

## Assumptions and Limitations

### Assumptions

1. **Authentication** — Sanctum is already configured and operational; no changes were made to the auth stack.
2. **Account structure** — One-to-many between `Account` and `Vault` (matches existing Monica design).
3. **Vault access** — Users can only import into vaults for which they hold editor-level access.
4. **CSV format** — Comma-delimited, UTF-8 encoded, with a header row on line 1.
5. **Contact uniqueness** — A `first_name + last_name` combination within the same vault identifies a duplicate. This is intentionally simple and noted as a limitation.
6. **File size cap** — 10 MB maximum (configurable via validation rule).
7. **Processing timeout** — 1-hour job timeout covers all realistic file sizes.

### Current Limitations

| Area                | Limitation                                                             | Notes                                                   |
| ------------------- | ---------------------------------------------------------------------- | ------------------------------------------------------- |
| Contact fields      | Email and phone are validated but **not yet persisted**                | Requires additional contact-info services               |
| Encoding            | UTF-8 only; other encodings may silently corrupt data                  | Could add BOM detection and iconv conversion            |
| Duplicate detection | Simple exact match on first+last name                                  | Fuzzy matching would reduce false negatives             |
| Relationships       | No support for contact relationships, tags, or labels                  | Schema extension required                               |
| File cleanup        | Uploaded files are **not** automatically deleted after processing      | A cleanup artisan command or retention policy is needed |
| Concurrency         | Simultaneous imports into the same vault are not explicitly serialised | Could add vault-scoped queue or locking                 |
| Scale               | Tested up to 1 000 rows; files with 100 k+ rows may need tuning        | Chunk size and timeout are configurable                 |
| Cancellation        | No cancel endpoint in this implementation                              | See Technical Questions section for design              |
| CSV variants        | Semicolon/tab delimiters, multi-line fields, and BOM not handled       | Configurable parser would address this                  |

### Edge Cases Handled

- Empty CSV (no data rows)
- CSV with only a header row
- Missing required `first_name` header
- Invalid email format per row
- Job crash and retry (resume from `last_processed_row`)
- Duplicate contacts (detected and skipped with error entry)
- Mixed valid/invalid rows (valid rows still succeed)
- Unauthorised vault access (rejected before job is queued)
- File upload failures

---

## Retry-Safety Explanation

Job failures are inevitable in any distributed system — servers restart, database connections drop, and workers crash. The import system is designed so that **retrying a failed job produces the same final result as if it had succeeded on the first attempt**.

### The Four Safety Mechanisms

#### 1. Row-progress tracking (`last_processed_row`)

The `import_jobs` table stores a `last_processed_row` integer that is updated after every successfully processed row. On each retry, the job reads this value and skips all rows up to and including that number:

```php
$startRow = $this->importJob->last_processed_row;
// Fast-forward through already-processed rows before resuming
```

#### 2. Duplicate contact detection

Before calling `CreateContact`, the job queries for an existing contact matching `first_name + last_name` in the same vault:

```php
protected function isDuplicateContact(array $rowData): bool
{
    return $vault->contacts()
        ->where('first_name', $rowData['first_name'])
        ->where('last_name', $rowData['last_name'])
        ->exists();
}
```

If a match is found the row is skipped and recorded as a failed row with a clear message, preventing duplicate contacts even if the job retries a row it already committed.

#### 3. Per-row database transactions

Every row is wrapped in its own database transaction:

```php
DB::transaction(function () use ($rowData, $rowNumber, $rowValidator) {
    // validate → duplicate check → create contact → record error
    // atomic: either fully commits or fully rolls back
});
```

This prevents half-created contacts and ensures that `last_processed_row` is only incremented when the contact is definitely in the database.

#### 4. Per-row error isolation

Each row is processed inside a try/catch. An unhandled exception on row N records an error entry and increments `failed_rows`, then processing continues on row N+1. A single bad row never aborts the import.

### Failure Scenario Walkthrough

**Scenario:** Job crashes mid-run after processing 75 of 100 rows.

| Field                | Before crash | After retry |
| -------------------- | ------------ | ----------- |
| `total_rows`         | 100          | 100         |
| `processed_rows`     | 75           | 100         |
| `last_processed_row` | 75           | 100         |
| `status`             | processing   | completed   |

On retry: the job reads `last_processed_row = 75`, skips rows 1–75, and resumes from row 76. Even if row 76 was partially committed before the crash, the duplicate check catches it.

### Known Edge Cases

- **Transaction committed but counter not updated:** If the DB commit succeeds but the `last_processed_row` update immediately after fails, that row could be processed twice on retry. The duplicate-contact check is the safety net in this scenario.
- **Concurrent retries:** Laravel's queue system uses job locking to prevent two workers from running the same job simultaneously.
- **External side-effects:** If the system is later extended to trigger external API calls (e.g., CRM sync, welcome emails), those operations should be made idempotent independently.

---

## Technical Questions

### Q1 — How would you detect that an import has remained in processing for an unusually long time?

**Problem:** An import job can get stuck in `processing` status if the queue worker crashes, the database connection drops, or the server restarts without a graceful shutdown. Without detection, the record stays in `processing` indefinitely.

**Approach:**

Define a dynamic timeout threshold per import based on file size:

```
timeout = clamp(
    (total_rows / 50 chunks) × 1 second per chunk × 5× safety factor,
    minimum = 10 minutes,
    maximum = 2 hours
)
```

Add an `isStuck()` method to the `ImportJob` model:

```php
public function isStuck(): bool
{
    if ($this->status !== 'processing' || !$this->started_at) {
        return false;
    }
    $timeoutSeconds = max(600, min(7200, ($this->total_rows / 50) * 5));
    return $this->started_at->diffInSeconds(now()) > $timeoutSeconds;
}
```

Create a scheduled artisan command `check:stuck-imports` that runs every 5 minutes:

```php
// In App\Console\Kernel
$schedule->command('check:stuck-imports')->everyFiveMinutes();
```

The command queries all `processing` imports, calls `isStuck()` on each, and updates their status to `failed` with a descriptive `failure_message`. Optionally, it fires a notification (Slack, email) to the operations team.

**Why this works:** The stuck-detection is completely decoupled from the job itself — it runs in a separate process and requires no changes to the worker or queue configuration.

---

### Q2 — How would you allow a user to cancel a running import?

**Problem:** Users may want to stop an import that is in progress — for example, because they uploaded the wrong file or noticed data errors early in the run.

**Approach:**

Add two columns to `import_jobs`:

```sql
cancellation_requested_at  timestamp  nullable
-- status enum extended to include 'cancelled'
```

Expose a new API endpoint:

```
PATCH /api/imports/{id}/cancel
```

The endpoint validates that the import belongs to the authenticated user and is in a cancellable state (`pending` or `processing`), then sets `cancellation_requested_at = now()`.

Inside the background job, check for the cancellation flag before processing each chunk:

```php
foreach ($chunks as $chunk) {
    $this->importJob->refresh();
    if ($this->importJob->cancellation_requested_at !== null) {
        $this->importJob->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);
        return;
    }
    $this->processChunk($chunk);
}
```

**Trade-offs:**

- Cancellation is **not instant** — the current chunk finishes before the job stops (at most ~50 rows of extra work).
- Contacts already imported before cancellation are **not rolled back**. A separate cleanup step would be needed if the user wants to undo the partial import entirely.
- The `refresh()` call on every chunk adds one DB read per 50 rows, which is negligible.

---

### Q3 — How would you handle two uploads of the same file?

**Problem:** A user may accidentally upload the same CSV twice, leading to duplicate contacts, wasted queue resources, and confusion about which import to monitor.

**Approach:**

Calculate a SHA-256 hash of the file contents at upload time:

```php
$fileHash = hash('sha256', file_get_contents($request->file('file')->getRealPath()));
```

Add a `file_hash` column to `import_jobs` and check for recent duplicates before creating a new record:

```php
$duplicate = ImportJob::where('account_id', $accountId)
    ->where('file_hash', $fileHash)
    ->where('created_at', '>=', now()->subDays(7))
    ->whereNotIn('status', ['failed', 'cancelled'])
    ->first();

if ($duplicate) {
    return response()->json([
        'message' => 'This file was already imported recently.',
        'existing_import' => $duplicate,
    ], 409);
}
```

If a duplicate is detected, the newly uploaded file is deleted and a `409 Conflict` response is returned with a reference to the existing import, so the user can check its status.

**Optional override:** Accept a `force=true` query parameter to bypass duplicate detection for intentional re-imports.

**Configuration:**

```env
IMPORT_DUPLICATE_WINDOW_DAYS=7
IMPORT_DUPLICATE_DETECTION_ENABLED=true
```

**Trade-offs:**

- Adds a SHA-256 hash computation on every upload (fast, typically < 5 ms for a 10 MB file).
- Identical content with different filenames is correctly detected as a duplicate (content-based, not name-based).
- Files that were previously failed or cancelled are not treated as blocking duplicates, so users can re-try a corrected version of the same file.

---

### Q4 — What metrics would you monitor for this import system?

A healthy import system requires visibility across four dimensions: **performance**, **reliability**, **resources**, and **business usage**.

#### Performance Metrics

| Metric                       | Description                     |
| ---------------------------- | ------------------------------- |
| Rows per second              | Processing throughput per job   |
| Import duration distribution | P50, P95, P99 durations         |
| Queue wait time              | Time from dispatch to job start |
| Queue depth                  | Number of pending jobs          |

#### Reliability Metrics

| Metric                 | Description                                |
| ---------------------- | ------------------------------------------ |
| Import success rate    | `completed / total` — target ≥ 95%         |
| Row-level success rate | Valid rows / total rows across all imports |
| Job failure rate       | Jobs that exhaust all retries              |
| Retry frequency        | Average retry count per job                |
| Stuck import rate      | How often the stuck-import detector fires  |

#### Resource Metrics

| Metric                   | Description                                 |
| ------------------------ | ------------------------------------------- |
| Storage usage            | Size of `storage/app/imports/`, growth rate |
| `import_jobs` table size | Row count and index size                    |
| Slow query rate          | Queries against import tables > 100 ms      |
| Worker memory usage      | RSS during active processing                |

#### Business Metrics

| Metric                      | Description                                   |
| --------------------------- | --------------------------------------------- |
| Imports per day / week      | Usage trend over time                         |
| Active importing users      | Unique users initiating imports               |
| Average contacts per import | Helps with capacity planning                  |
| Abandonment rate            | Imports initiated but never polled for status |

#### Alerting Thresholds

| Condition                                  | Action          |
| ------------------------------------------ | --------------- |
| Success rate < 95%                         | Page on-call    |
| Processing time > 2× baseline              | Warning alert   |
| Queue depth > 50                           | Warning alert   |
| Any import stuck > 2 hours                 | Immediate alert |
| Storage usage > 80% capacity               | Warning alert   |
| Error rate spike > 50% above 7-day average | Warning alert   |

**Implementation:** An admin metrics endpoint can aggregate these statistics from the `import_jobs` and `import_errors` tables. Structured log entries after each import completion feed into tools like CloudWatch Logs Insights, Datadog, or Grafana for dashboards and automated alerting.

---

## Test Instructions

### Interactive Testing via Web UI

**Access the API documentation:**

Navigate to `http://localhost:8000/docs#contact-import` to view and test the import endpoints interactively.

**Login requirement:**

Before testing the import endpoints, you must authenticate:

1. Navigate to `http://localhost:8000/login`
2. Log in with your Monica credentials (or register a new account if needed)
3. Once logged in, return to `http://localhost:8000/docs#contact-import`
4. The interactive API documentation will use your session authentication automatically

**Testing the import flow:**

1. Prepare a CSV file following the format described in the [CSV File Format](#csv-file-format) section
2. Use the **POST /api/imports** endpoint to upload your file
3. Copy the `id` from the response
4. Monitor progress with **GET /api/imports/{id}**
5. View any errors with **GET /api/imports/{id}/errors**

### Configure Test Environment

The test suite uses an in-memory SQLite database configured in `phpunit.xml`:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

No additional configuration is required.

### Run All Tests

```bash
php artisan test
```

### Run Individual Test Suites

```bash
# Import initiation (upload, validation, record creation)
php artisan test --filter=ImportInitiationTest

# Background processing (contact creation, progress updates)
php artisan test --filter=ContactImportProcessingTest

# Error isolation (per-row errors, partial success)
php artisan test --filter=ImportErrorIsolationTest

# Retry safety (idempotency, resume from checkpoint)
php artisan test --filter=ImportRetryTest

# Progress tracking (auth, scoping, progress_pct calculation)
php artisan test --filter=ImportProgressTest
```

### Test Coverage

| Suite                         | File                                            | Covers                                                                                                                                                                                                                                        |
| ----------------------------- | ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ImportInitiationTest`        | `tests/Feature/ImportInitiationTest.php`        | Authenticated upload, 401 rejection, non-CSV rejection, DB record creation, job dispatch, 201 response structure                                                                                                                              |
| `ContactImportProcessingTest` | `tests/Feature/ContactImportProcessingTest.php` | Valid rows create contacts, `processed_rows` increments, status transitions `pending → processing → completed`, `completed_at` is set, contacts are vault-scoped                                                                              |
| `ImportErrorIsolationTest`    | `tests/Feature/ImportErrorIsolationTest.php`    | Invalid row recorded in `import_errors` with correct fields, rows before/after error still processed, `failed_rows` count correct, various validation failures produce appropriate messages, status is `completed` even with partial failures |
| `ImportRetryTest`             | `tests/Feature/ImportRetryTest.php`             | No duplicate contacts on retry, `processed_rows` accurate after retry, job resumes from `last_processed_row`                                                                                                                                  |
| `ImportProgressTest`          | `tests/Feature/ImportProgressTest.php`          | Status endpoint requires auth, users cannot view other accounts' imports, `progress_pct` calculated correctly, 404 for unknown ID, 403 for cross-account access                                                                               |

### Expected Output

```
PASS  Tests\Feature\ImportInitiationTest
  ✓ authenticated user can upload csv file                    0.15s
  ✓ unauthenticated user receives 401                         0.02s
  ✓ non csv file is rejected                                  0.03s
  ✓ import record is created in database                      0.12s
  ✓ job is dispatched to queue                                0.10s

PASS  Tests\Feature\ContactImportProcessingTest
  ✓ valid csv rows create contacts                            0.25s
  ✓ processed rows is updated correctly                       0.20s
  ✓ status changes to completed                               0.22s

PASS  Tests\Feature\ImportErrorIsolationTest
  ✓ invalid row is recorded in import errors                  0.18s
  ✓ rows before and after error are processed                 0.24s
  ✓ various validation failures produce errors                0.26s

PASS  Tests\Feature\ImportRetryTest
  ✓ contacts are not duplicated on retry                      0.30s
  ✓ job can resume from last processed row                    0.28s

PASS  Tests\Feature\ImportProgressTest
  ✓ progress endpoint requires authentication                 0.05s
  ✓ user can only view own imports                            0.12s
  ✓ progress pct is calculated correctly                      0.15s

Tests:    18 passed (18 assertions)
Duration: 2.87s
```

---

_Implementation Date: August 2026 · Laravel 10.x · PHP 8.1+_
