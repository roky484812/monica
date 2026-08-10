# Backend Assignment Implementation Plan

## Background Contact Import System for Monica CRM

---

## 📋 Overview

This document breaks down the backend assignment into small, manageable tasks organized by phases. Each phase contains specific, actionable tasks that can be completed independently.

---

## Phase 0: Setup & Environment Preparation ✅ COMPLETED

### Task 0.1: Repository Setup

- [x] Fork the Monica repository from https://github.com/monicahq/monica
- [x] Clone the forked repository to local machine
- [x] Create a new branch named `envobyte-assignment`
- [ ] Push the branch to remote repository (will do after implementation)

### Task 0.2: Local Development Environment

- [x] Install PHP 8.1+ and required extensions (PHP 8.4.24 installed)
- [x] Install MySQL 8.0+
- [x] Install Composer dependencies (`composer install`)
- [x] Copy `.env.example` to `.env`
- [x] Generate application key (`php artisan key:generate`)
- [x] Configure database connection in `.env`
- [x] Run database migrations (`php artisan migrate`)
- [x] Seed database if needed (`php artisan db:seed`)
- [x] Test the application runs (`php artisan serve`)

### Task 0.3: Queue Configuration

- [x] Configure queue driver in `.env` (database or redis) - Currently using 'sync', will configure for database
- [ ] Run queue migration if using database driver (will do in Phase 2)
- [x] Test queue worker runs (`php artisan queue:work`)
- [x] Configure queue connection settings

### Task 0.4: Testing Environment Setup

- [x] Create test database configuration (DB_TEST_DRIVER=sqlite)
- [x] Verify tests run successfully (`php artisan test`)
- [x] Review existing test structure and conventions
- [x] Set up PHPUnit configuration if needed

---

## Phase 1: Code Analysis & Understanding ✅ COMPLETED

### Task 1.1: Identify Existing Import Flow

- [x] Search for existing CSV import functionality in the codebase - NO CSV import exists
- [x] Locate the import controller or API endpoint - Found VCard/VCalendar importers only
- [x] Find import service/parser/action classes - Found Dav/Services/ImportVCard
- [x] Identify contact creation logic - Found CreateContact service
- [x] Document the current flow in notes

**Notes:**

- Monica uses VCard/VCalendar import through DAV protocol
- No existing CSV import functionality
- Contact creation uses `app/Domains/Contact/ManageContact/Services/CreateContact.php`
- All services extend BaseService and implement ServiceInterface

### Task 1.2: Study Contact Model & Relationships

- [x] Review `Contact` model structure - Located at app/Models/Contact.php
- [x] Understand contact validation rules - Defined in CreateContact service
- [x] Identify required fields for contact creation - first_name, last_name, vault_id, account_id, author_id
- [x] Review account_id and user_id relationships - Contact belongs to Vault, Vault belongs to Account
- [x] Document contact creation requirements

**Notes:**

- Contact model extends VCardResource
- Uses UUID for primary keys
- Requires: vault_id (UUID), account_id (UUID), author_id (UUID)
- Optional: first_name, last_name, middle_name, nickname, email, phone, etc.
- Soft deletes enabled

### Task 1.3: Study Authorization & Authentication

- [x] Review authentication middleware used in Monica - Uses Sanctum (auth:sanctum)
- [x] Understand account ownership and scoping patterns - Services validate vault/account ownership
- [x] Identify authorization conventions (policies/gates) - Uses permissions() method in services
- [x] Document auth patterns to follow

**Notes:**

- API routes use `auth:sanctum` middleware
- Services check: author_must_belong_to_account, vault_must_belong_to_account, author_must_be_vault_editor
- BaseService provides validation framework

### Task 1.4: Study API Response Conventions

- [x] Review existing API controllers - Found ApiController base, UserController, VaultController
- [x] Identify response structure patterns (resources/transformers) - Uses JsonRespondController trait
- [x] Understand error response formats - respondNotFound(), respondValidatorFailed(), etc.
- [x] Document API conventions to follow

**Notes:**

- Controllers extend ApiController
- Use JsonRespondController trait for consistent responses
- Returns JSON with status codes
- Handles ModelNotFoundException, QueryException, ValidationException automatically

### Task 1.5: Document Analysis in README

- [x] Write "Existing Import Flow Analysis" section (will add to README later in Phase 14)
- [x] Explain how current import works
- [x] List components to be reused: CreateContact service, ApiController patterns
- [x] Document assumptions made

---

## Phase 2: Database Design & Migration ✅ COMPLETED

### Task 2.1: Design Import Jobs Table Schema

- [x] Create migration file for `import_jobs` table
- [x] Add `id` (primary key)
- [x] Add `account_id` (foreign key to accounts)
- [x] Add `user_id` (foreign key to users)
- [x] Add `filename` (string)
- [x] Add `file_path` (string)
- [x] Add `total_rows` (integer, default 0)
- [x] Add `processed_rows` (integer, default 0)
- [x] Add `failed_rows` (integer, default 0)
- [x] Add `status` (enum: pending, processing, completed, failed)
- [x] Add `failure_message` (text, nullable)
- [x] Add `started_at` (timestamp, nullable)
- [x] Add `completed_at` (timestamp, nullable)
- [x] Add `created_at` and `updated_at` timestamps
- [x] Add indexes on account_id, user_id, status
- [x] Document any additional columns added

### Task 2.2: Design Import Errors Table Schema

- [x] Create migration file for `import_errors` table
- [x] Add `id` (primary key)
- [x] Add `import_job_id` (foreign key to import_jobs)
- [x] Add `row_number` (integer)
- [x] Add `row_data` (json, to store original row data)
- [x] Add `error_message` (text)
- [x] Add `created_at` timestamp
- [x] Add index on import_job_id
- [x] Add cascade delete on import_job deletion

### Task 2.3: Run and Test Migrations

- [x] Run migrations (`php artisan migrate`)
- [x] Verify tables created successfully
- [x] Test rollback (`php artisan migrate:rollback`)
- [x] Run migrations again to ensure repeatability
- [x] Verify foreign key constraints work

---

## Phase 3: Model Creation ✅ COMPLETED

### Task 3.1: Create ImportJob Model

- [x] Generate model: `php artisan make:model ImportJob`
- [x] Define fillable fields
- [x] Define casts (status enum, timestamps)
- [x] Add relationship: `belongsTo(Account::class)`
- [x] Add relationship: `belongsTo(User::class)`
- [x] Add relationship: `hasMany(ImportError::class)`
- [x] Create accessor for `progress_pct` (calculated field)
- [x] Add status constants (PENDING, PROCESSING, COMPLETED, FAILED)
- [x] Add scope for filtering by status
- [x] Add scope for filtering by account

### Task 3.2: Create ImportError Model

- [x] Generate model: `php artisan make:model ImportError`
- [x] Define fillable fields
- [x] Define casts (row_data as array)
- [x] Add relationship: `belongsTo(ImportJob::class)`
- [x] Add ordering scope (by row_number)

### Task 3.3: Update Related Models

- [x] Add relationship to Account model if needed: `hasMany(ImportJob::class)`
- [x] Add relationship to User model if needed: `hasMany(ImportJob::class)`
- [x] Update Contact model if needed for tracking imports - Not needed for MVP

---

## Phase 4: File Storage Setup ✅ COMPLETED

### Task 4.1: Configure Storage for Import Files

- [x] Define storage disk for imports in `config/filesystems.php`
- [x] Create private storage directory for CSV files
- [x] Test file storage with sample upload
- [x] Add storage path to `.gitignore`

### Task 4.2: Create File Handling Service

- [x] Create `App\Services\ImportFileService` class
- [x] Add method to store uploaded CSV file
- [x] Add method to generate unique filename
- [x] Add method to retrieve file from storage
- [x] Add method to delete file from storage
- [x] Add file validation logic (CSV only, max size)
- [ ] Add unit tests for file service (deferred to testing phase)

---

## Phase 5: CSV Import Validation ✅ COMPLETED

### Task 5.1: Create CSV Validator

- [x] Create `App\Services\CsvValidatorService` class
- [x] Add method to validate CSV structure
- [x] Add method to count total rows in CSV
- [x] Add method to validate CSV headers
- [x] Handle different CSV encodings (UTF-8, etc.)
- [ ] Add unit tests for CSV validation (deferred to testing phase)

### Task 5.2: Create Contact Row Validator

- [x] Create `App\Validators\ContactRowValidator` class
- [x] Add validation rules for contact name (required)
- [x] Add validation rules for email (optional, valid format)
- [x] Add validation rules for phone (optional, valid format)
- [x] Add validation for other contact fields
- [x] Return detailed error messages for each field
- [ ] Add unit tests for row validation (deferred to testing phase)

---

## Phase 6: Background Job Implementation ✅ COMPLETED

### Task 6.1: Create Import Job Class

- [x] Generate job: `php artisan make:job ProcessContactImport`
- [x] Implement `ShouldQueue` interface
- [x] Add constructor to accept ImportJob model
- [x] Configure queue connection and timeout
- [x] Configure retry attempts and backoff
- [x] Add job tags for monitoring

### Task 6.2: Implement Job Initialization Logic

- [x] Update import status to 'processing'
- [x] Set `started_at` timestamp
- [x] Open CSV file from storage
- [x] Count total rows and update `total_rows`
- [x] Handle file not found exception
- [x] Save import job record

### Task 6.3: Implement Chunk Processing Logic

- [x] Create method to read CSV in chunks (50 rows)
- [x] Loop through CSV file chunk by chunk
- [x] For each chunk, process rows individually
- [x] Update `processed_rows` after each chunk
- [x] Use Laravel's `LazyCollection` for memory efficiency (used CsvValidatorService)
- [x] Add logging for each chunk processed

### Task 6.4: Implement Row Processing Logic

- [x] Create method to process single row
- [x] Validate row using ContactRowValidator
- [x] If valid, create contact using existing Monica logic
- [x] If invalid, record error in import_errors table
- [x] Increment `failed_rows` for invalid rows
- [x] Use try-catch to isolate row failures
- [x] Continue to next row on failure

### Task 6.5: Implement Contact Creation Integration

- [x] Identify Monica's existing contact creation service/action
- [x] Call existing contact creation logic for valid rows
- [x] Pass authenticated user context
- [x] Pass account_id for proper scoping
- [x] Handle contact creation exceptions gracefully
- [x] Log successful contact creation

### Task 6.6: Implement Job Completion Logic

- [x] After all rows processed, update status to 'completed'
- [x] Set `completed_at` timestamp
- [x] Calculate final statistics
- [x] If no contacts created successfully, set status to 'failed' (handled by exception logic)
- [x] Save final import job record

### Task 6.7: Implement Job Failure Handling

- [x] Override `failed()` method
- [x] Update status to 'failed'
- [x] Record system-level failure message
- [x] Set `completed_at` timestamp
- [x] Log failure details
- [x] Save import job record

### Task 6.8: Implement Retry Safety Mechanism

- [x] Add unique constraint or tracking mechanism for processed rows (added last_processed_row column)
- [x] Implement idempotency check before creating contact (check duplicate by first_name + last_name)
- [x] Use database transactions for row processing (DB::transaction wraps each row)
- [x] Track last successfully processed row number (updated after each row)
- [x] On retry, skip already processed rows (resume from last_processed_row)
- [x] Document retry strategy in code comments (comprehensive documentation added)

---

## Phase 7: API Endpoint - Import Initiation ✅ COMPLETED

### Task 7.1: Create Import Request Validation

- [x] Generate request: `php artisan make:request ImportContactsRequest`
- [x] Add authorization check (authenticated user)
- [x] Add validation rule for CSV file upload
- [x] Validate file type (CSV only)
- [x] Validate file size (max limit)
- [x] Return custom error messages

### Task 7.2: Create Import API Controller

- [x] Generate controller: `php artisan make:controller Api/ImportController`
- [x] Add constructor with middleware for authentication
- [x] Ensure account scoping is applied

### Task 7.3: Implement Import Initiation Endpoint

- [x] Create `store()` method in ImportController
- [x] Validate request using ImportContactsRequest
- [x] Get authenticated user and account
- [x] Store uploaded CSV file using ImportFileService
- [x] Count rows in CSV (set total_rows to 0 initially)
- [x] Create ImportJob record with 'pending' status
- [x] Dispatch ProcessContactImport job
- [x] Return 201 response with import job data

### Task 7.4: Create API Resource for ImportJob

- [x] Generate resource: `php artisan make:resource ImportJobResource`
- [x] Map ImportJob fields to response structure
- [x] Include calculated `progress_pct` field
- [x] Format timestamps properly
- [x] Exclude sensitive fields (file_path)

### Task 7.5: Register Import Initiation Route

- [x] Add POST route `/api/imports` in `routes/api.php`
- [x] Apply authentication middleware
- [x] Point to ImportController@store
- [x] Test route is registered: `php artisan route:list`

---

## Phase 8: API Endpoint - Progress Tracking ✅ COMPLETED

### Task 8.1: Create Import Policy

- [x] Generate policy: NOT NEEDED - authorization handled inline in controller
- [x] Add `view()` method to check ownership - DONE inline
- [x] Check import belongs to user's account - DONE inline
- [x] Register policy in AuthServiceProvider - NOT NEEDED

### Task 8.2: Implement Progress Tracking Endpoint

- [x] Create `show()` method in ImportController
- [x] Accept `$id` parameter
- [x] Find ImportJob by ID
- [x] Authorize using account ownership check
- [x] Return 404 if not found or unauthorized
- [x] Return ImportJobResource with current state
- [x] Calculate and include progress_pct

### Task 8.3: Register Progress Tracking Route

- [x] Add GET route `/api/imports/{id}` in `routes/api.php`
- [x] Apply authentication middleware
- [x] Point to ImportController@show
- [x] Test route is registered

### Task 8.4: BONUS - Implement Error Retrieval Endpoint

- [x] Create `errors()` method in ImportController
- [x] Add GET route `/api/imports/{id}/errors` with pagination
- [x] Return paginated errors for the import job

---

## Phase 9: Testing - Import Initiation ✅ COMPLETED

### Task 9.1: Create Import Initiation Test

- [x] Generate test: `php artisan make:test ImportInitiationTest`
- [x] Use RefreshDatabase trait
- [x] Create test: authenticated user can upload CSV
- [x] Create test: unauthenticated user receives 401
- [x] Create test: non-CSV file is rejected
- [x] Create test: import record is created in database
- [x] Create test: job is dispatched to queue
- [x] Create test: 201 response with correct structure

### Task 9.2: Create File Upload Test Helper

- [x] Create method to generate test CSV file
- [x] Use Laravel's `UploadedFile::fake()` for testing
- [x] Create CSV with valid contact data
- [x] Create CSV with invalid contact data - Partially done (will expand in Phase 11)
- [x] Reuse helper across test files

---

## Phase 10: Testing - Background Processing

### Task 10.1: Create Background Processing Test

- [ ] Generate test: `php artisan make:test ContactImportProcessingTest`
- [ ] Use RefreshDatabase trait
- [ ] Create test: valid CSV rows create contacts
- [ ] Create test: processed_rows is updated correctly
- [ ] Create test: total_rows is set correctly
- [ ] Create test: status changes to 'processing' then 'completed'
- [ ] Create test: completed_at timestamp is set

### Task 10.2: Test Chunk Processing

- [ ] Create test CSV with more than 50 rows
- [ ] Verify all rows are processed
- [ ] Verify processed_rows updates incrementally
- [ ] Verify memory usage stays reasonable

### Task 10.3: Test Contact Creation Integration

- [ ] Verify contacts appear in database
- [ ] Verify contacts belong to correct account
- [ ] Verify contacts have correct user_id
- [ ] Verify all contact fields are saved correctly

---

## Phase 11: Testing - Error Isolation

### Task 11.1: Create Error Isolation Test

- [ ] Generate test: `php artisan make:test ImportErrorIsolationTest`
- [ ] Use RefreshDatabase trait
- [ ] Create CSV with mix of valid and invalid rows
- [ ] Create test: invalid row is recorded in import_errors
- [ ] Create test: error contains row_number
- [ ] Create test: error contains error_message
- [ ] Create test: error contains row_data

### Task 11.2: Test Continued Processing After Error

- [ ] Create CSV with invalid row in middle
- [ ] Verify rows before error are processed
- [ ] Verify rows after error are processed
- [ ] Verify failed_rows count is correct
- [ ] Verify processed_rows includes failed rows

### Task 11.3: Test Various Validation Failures

- [ ] Test missing required name field
- [ ] Test invalid email format
- [ ] Test invalid phone format
- [ ] Test unsupported field values
- [ ] Verify each produces appropriate error message

### Task 11.4: Test Import Status with Partial Failures

- [ ] Create CSV with some invalid rows
- [ ] Verify status is 'completed' (not 'failed')
- [ ] Verify failed_rows and processed_rows are correct
- [ ] Test import with all invalid rows sets status to 'failed'

---

## Phase 12: Testing - Retry Safety

### Task 12.1: Create Retry Safety Test

- [ ] Generate test: `php artisan make:test ImportRetryTest`
- [ ] Use RefreshDatabase trait
- [ ] Create import job with partial progress
- [ ] Simulate job retry
- [ ] Verify contacts are not duplicated
- [ ] Verify processed_rows remains accurate

### Task 12.2: Test Transaction Rollback Scenario

- [ ] Simulate failure after contact creation
- [ ] Verify transaction rollback prevents partial state
- [ ] Verify retry can process row successfully
- [ ] Document behavior in test comments

### Task 12.3: Document Retry Strategy

- [ ] Add comments in test explaining retry mechanism
- [ ] Document what happens on crash
- [ ] Document how duplicates are prevented
- [ ] Document any remaining limitations

---

## Phase 13: Progress Tracking Tests

### Task 13.1: Create Progress Tracking Test

- [ ] Generate test: `php artisan make:test ImportProgressTest`
- [ ] Use RefreshDatabase trait
- [ ] Create test: progress endpoint requires authentication
- [ ] Create test: user can only view own account's imports
- [ ] Create test: progress_pct is calculated correctly
- [ ] Create test: returns 404 for non-existent import
- [ ] Create test: returns 403 for unauthorized access

### Task 13.2: Test Progress During Processing

- [ ] Create import with partial progress
- [ ] Call progress endpoint
- [ ] Verify processed_rows and total_rows match
- [ ] Verify progress_pct is accurate
- [ ] Verify status reflects current state

---

## Phase 14: Documentation - README

### Task 14.1: Write Setup Instructions

- [ ] Document prerequisites (PHP, MySQL, Composer)
- [ ] Document clone and branch setup
- [ ] Document environment configuration
- [ ] Document database setup
- [ ] Document queue configuration
- [ ] Document running the application

### Task 14.2: Write Existing Flow Analysis

- [ ] Describe how existing import worked
- [ ] List controllers/services analyzed
- [ ] Explain contact creation flow
- [ ] List components reused
- [ ] Document any modifications made

### Task 14.3: Write Implementation Approach

- [ ] Describe overall architecture
- [ ] Explain database design decisions
- [ ] Describe job processing strategy
- [ ] Explain error isolation approach
- [ ] Document file storage approach

### Task 14.4: Write Assumptions & Limitations

- [ ] List assumptions made about Monica's structure
- [ ] Document any limitations in implementation
- [ ] Explain trade-offs made
- [ ] Describe edge cases not fully handled

### Task 14.5: Write Retry Safety Explanation

- [ ] Explain what happens on job crash
- [ ] Describe duplicate prevention mechanism
- [ ] Document transaction strategy
- [ ] List remaining retry limitations
- [ ] Provide examples of retry scenarios

### Task 14.6: Answer Technical Questions

- [ ] Question 1: Detecting stuck imports (processing too long)
- [ ] Question 2: Allowing user to cancel running import
- [ ] Question 3: Handling duplicate file uploads
- [ ] Question 4: Metrics to monitor for import system
- [ ] Keep answers concise and practical

### Task 14.7: Write Test Instructions

- [ ] Document test command: `php artisan test`
- [ ] Document how to run specific test suites
- [ ] Document test database setup if needed
- [ ] Document expected test output
- [ ] Document any special test configurations

### Task 14.8: Add API Documentation

- [ ] Document POST /api/import endpoint
- [ ] Document GET /api/import/{id} endpoint
- [ ] Include example requests
- [ ] Include example responses
- [ ] Document error responses

---

## Phase 15: Code Quality & Refinement

### Task 15.1: Code Style & Conventions

- [ ] Run PHP CS Fixer or Laravel Pint: `./vendor/bin/pint`
- [ ] Ensure consistent indentation and formatting
- [ ] Follow PSR-12 coding standards
- [ ] Follow Monica's existing code style

### Task 15.2: Add Code Comments

- [ ] Add PHPDoc blocks to all classes
- [ ] Add PHPDoc blocks to all public methods
- [ ] Add inline comments for complex logic
- [ ] Document method parameters and return types

### Task 15.3: Refactor for Readability

- [ ] Extract complex logic into private methods
- [ ] Ensure single responsibility per method
- [ ] Keep controllers thin
- [ ] Move business logic to services
- [ ] Use descriptive variable names

### Task 15.4: Error Handling Review

- [ ] Ensure all exceptions are caught appropriately
- [ ] Add meaningful error messages
- [ ] Log errors at appropriate levels
- [ ] Return user-friendly error responses

### Task 15.5: Security Review

- [ ] Ensure file uploads are validated
- [ ] Ensure files stored in non-public location
- [ ] Verify authentication on all endpoints
- [ ] Verify authorization checks work correctly
- [ ] Check for SQL injection vulnerabilities
- [ ] Sanitize user input

---

## Phase 16: Integration Testing

### Task 16.1: End-to-End Test

- [ ] Create full workflow test: upload → process → check progress
- [ ] Test with realistic CSV file (100+ rows)
- [ ] Verify entire flow works together
- [ ] Test with concurrent imports

### Task 16.2: Manual Testing

- [ ] Test file upload via API client (Postman/Insomnia)
- [ ] Monitor queue worker processing: `php artisan queue:work --verbose`
- [ ] Check progress endpoint during processing
- [ ] Verify contacts created in database
- [ ] Verify import_errors recorded correctly

### Task 16.3: Edge Case Testing

- [ ] Test with empty CSV file
- [ ] Test with CSV containing only headers
- [ ] Test with very large CSV (1000+ rows)
- [ ] Test with malformed CSV
- [ ] Test with special characters in data
- [ ] Test with different CSV delimiters

---

## Phase 17: Performance Optimization

### Task 17.1: Memory Usage Optimization

- [ ] Verify CSV reading uses LazyCollection or streaming
- [ ] Check memory usage during large file processing
- [ ] Optimize chunk size if needed
- [ ] Add memory limit checks

### Task 17.2: Database Query Optimization

- [ ] Add database indexes where needed
- [ ] Use eager loading to prevent N+1 queries
- [ ] Batch database updates where possible
- [ ] Monitor query performance

### Task 17.3: Queue Performance

- [ ] Configure appropriate queue timeout
- [ ] Configure retry attempts and backoff
- [ ] Consider queue priorities if needed
- [ ] Test job failure scenarios

---

## Phase 18: Git Commit History

### Task 18.1: Create Logical Commits

- [ ] Commit 1: Add import_jobs and import_errors migrations
- [ ] Commit 2: Create ImportJob and ImportError models
- [ ] Commit 3: Create file storage service
- [ ] Commit 4: Create CSV validation service
- [ ] Commit 5: Implement ProcessContactImport job
- [ ] Commit 6: Add import initiation endpoint
- [ ] Commit 7: Add progress tracking endpoint
- [ ] Commit 8: Add import initiation tests
- [ ] Commit 9: Add processing and error isolation tests
- [ ] Commit 10: Add retry safety tests
- [ ] Commit 11: Add progress tracking tests
- [ ] Commit 12: Update README with documentation

### Task 18.2: Write Meaningful Commit Messages

- [ ] Use imperative mood ("Add" not "Added")
- [ ] Keep subject line under 50 characters
- [ ] Add detailed description in commit body
- [ ] Reference relevant files or components

---

## Phase 19: Optional Bonus Features

### Task 19.1: Import Cancellation (Optional)

- [ ] Add `cancelled` status to import_jobs
- [ ] Create PATCH /api/import/{id}/cancel endpoint
- [ ] Add cancellation flag check in job processing
- [ ] Stop processing when cancellation detected
- [ ] Add tests for cancellation
- [ ] Document in README

### Task 19.2: Error CSV Export (Optional)

- [ ] Create GET /api/import/{id}/errors/download endpoint
- [ ] Generate CSV from import_errors table
- [ ] Include row_number, row_data, error_message
- [ ] Return as downloadable file
- [ ] Add tests
- [ ] Document in README

### Task 19.3: Duplicate File Detection (Optional)

- [ ] Add `file_hash` column to import_jobs
- [ ] Calculate SHA256 hash of uploaded file
- [ ] Check for existing import with same hash
- [ ] Return warning or prevent duplicate upload
- [ ] Add tests
- [ ] Document in README

### Task 19.4: Enhanced Idempotency (Optional)

- [ ] Add processed row tracking table
- [ ] Record each successfully processed row
- [ ] Check before creating contact
- [ ] Add comprehensive retry tests
- [ ] Document enhanced mechanism

### Task 19.5: Additional Tests (Optional)

- [ ] Add test for concurrent imports
- [ ] Add test for queue timeout scenario
- [ ] Add test for large file memory usage
- [ ] Add test for various CSV encodings
- [ ] Add test for different date formats

---

## Phase 20: Final Review & Submission

### Task 20.1: Run All Tests

- [ ] Run full test suite: `php artisan test`
- [ ] Ensure all tests pass
- [ ] Review test coverage
- [ ] Fix any failing tests

### Task 20.2: Code Review Checklist

- [ ] All requirements implemented
- [ ] All tests passing
- [ ] README complete and accurate
- [ ] Code follows Laravel conventions
- [ ] No sensitive data in commits
- [ ] No debug code left in
- [ ] Error handling is robust

### Task 20.3: Documentation Review

- [ ] README setup instructions work
- [ ] All technical questions answered
- [ ] API endpoints documented
- [ ] Test instructions clear
- [ ] Retry strategy explained
- [ ] Assumptions documented

### Task 20.4: Repository Preparation

- [ ] Remove any local configuration files
- [ ] Ensure .gitignore is correct
- [ ] Push all commits to GitHub
- [ ] Verify branch name is `envobyte-assignment`
- [ ] Make repository public
- [ ] Test clone from GitHub works

### Task 20.5: Submission

- [ ] Get repository URL
- [ ] Verify README displays correctly on GitHub
- [ ] Submit repository link via provided form
- [ ] Prepare for technical review interview

---

## 🎯 Success Criteria Checklist

### Mandatory Requirements

- [ ] Import initiation endpoint (POST /api/import)
- [ ] Import progress endpoint (GET /api/import/{id})
- [ ] Import jobs table with all required columns
- [ ] Import errors table for per-row failures
- [ ] Background queued job for processing
- [ ] Chunk processing (50 rows per chunk)
- [ ] Memory-efficient CSV reading
- [ ] Per-row error isolation (one failure doesn't stop import)
- [ ] Progress tracking (processed_rows, failed_rows, total_rows)
- [ ] Retry safety mechanism
- [ ] Authentication and authorization
- [ ] Automated tests (initiation, processing, errors, retry)
- [ ] Complete README with all sections
- [ ] Technical questions answered

### Quality Standards

- [ ] Follows Monica's existing architecture
- [ ] Follows Laravel conventions
- [ ] Controllers are thin
- [ ] Business logic in services
- [ ] Proper validation and error handling
- [ ] Tests verify meaningful behavior
- [ ] Clean Git commit history
- [ ] Code is readable and maintainable

---

## 📚 Resources & References

### Monica CRM

- Repository: https://github.com/monicahq/monica
- Documentation: Check Monica's docs for setup and architecture

### Laravel Documentation

- Queue Jobs: https://laravel.com/docs/queues
- File Storage: https://laravel.com/docs/filesystem
- Validation: https://laravel.com/docs/validation
- Testing: https://laravel.com/docs/testing
- Authorization: https://laravel.com/docs/authorization

### Testing

- PHPUnit: https://phpunit.de/documentation.html
- Laravel HTTP Tests: https://laravel.com/docs/http-tests
- Database Testing: https://laravel.com/docs/database-testing

---

## ⏱️ Estimated Time per Phase

| Phase | Description               | Estimated Time |
| ----- | ------------------------- | -------------- |
| 0     | Setup & Environment       | 1 hour         |
| 1     | Code Analysis             | 1.5 hours      |
| 2-3   | Database & Models         | 1 hour         |
| 4-5   | File Storage & Validation | 1 hour         |
| 6     | Background Job            | 2 hours        |
| 7-8   | API Endpoints             | 1.5 hours      |
| 9-13  | Testing                   | 2 hours        |
| 14    | Documentation             | 1 hour         |
| 15-17 | Quality & Performance     | 1 hour         |
| 18-20 | Git & Submission          | 0.5 hours      |

**Total Estimated Time: ~12 hours** (includes buffer for debugging and refinement)

---

## 💡 Tips for Success

1. **Start with code analysis** - Don't write code until you understand Monica's structure
2. **Test as you go** - Write tests alongside implementation, not at the end
3. **Commit frequently** - Small, logical commits make better history
4. **Read Monica's conventions** - Follow their patterns for consistency
5. **Keep controllers thin** - Business logic goes in services/actions
6. **Document assumptions** - Write down decisions as you make them
7. **Test retry scenarios** - This is a critical requirement
8. **Use database transactions** - Protect data integrity
9. **Memory matters** - Don't load entire CSV into memory
10. **Ask questions in README** - Document trade-offs and limitations

---

## 🚨 Common Pitfalls to Avoid

1. Processing CSV in HTTP request (must be queued)
2. Loading entire CSV into memory
3. Stopping import on first error (must continue)
4. Creating duplicate contacts on retry
5. Missing authentication/authorization checks
6. Not updating progress incrementally
7. Counting contacts to determine progress (use import record)
8. Skipping tests
9. Poor commit messages
10. Incomplete README documentation

---

**Good luck with the assignment! Follow each phase step-by-step for best results.**
