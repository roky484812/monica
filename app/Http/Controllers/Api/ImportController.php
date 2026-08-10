<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportContactsRequest;
use App\Http\Resources\ImportJobResource;
use App\Jobs\ProcessContactImport;
use App\Models\ImportJob;
use App\Models\Vault;
use App\Services\CsvValidatorService;
use App\Services\ImportFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImportController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Initiate Contact Import
     *
     * Upload a CSV file containing contact data and start a background import process.
     * The import runs asynchronously and you can track its progress using the returned job ID.
     *
     * @group Contact Import
     *
     * @bodyParam file file required The CSV file to import. Must be comma-delimited, UTF-8 encoded, with headers: first_name,last_name,middle_name,nickname,email,phone. Max size: 10MB. No-example
     * @bodyParam vault_id string required The UUID of the vault to import contacts into. You must have editor access. Example: 9d2e1f12-3456-7890-abcd-ef1234567890
     *
     * @response 201 {
     *   "message": "Import started successfully.",
     *   "data": {
     *     "id": "9d2e1f12-3456-7890-abcd-ef1234567890",
     *     "filename": "contacts.csv",
     *     "total_rows": 100,
     *     "processed_rows": 0,
     *     "failed_rows": 0,
     *     "status": "pending",
     *     "progress_pct": 0,
     *     "started_at": null,
     *     "completed_at": null,
     *     "created_at": "2026-08-10T07:30:00.000000Z",
     *     "updated_at": "2026-08-10T07:30:00.000000Z"
     *   }
     * }
     * @response 422 scenario="Invalid CSV structure" {
     *   "message": "CSV validation failed.",
     *   "errors": {
     *     "headers": ["Missing required header: first_name"]
     *   }
     * }
     * @response 403 scenario="No vault access" {
     *   "message": "You do not have access to this vault."
     * }
     * @response 422 scenario="Invalid vault" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "vault_id": ["The selected vault is invalid."]
     *   }
     * }
     */
    public function store(
        ImportContactsRequest $request,
        ImportFileService $fileService,
        CsvValidatorService $csvValidator
    ): JsonResponse {
        $user = Auth::user();
        $vaultId = $request->input('vault_id');

        // Verify vault belongs to user's account
        $vault = Vault::where('id', $vaultId)
            ->where('account_id', $user->account_id)
            ->first();

        if (! $vault) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'vault_id' => ['The selected vault is invalid.'],
                ],
            ], 422);
        }

        // Verify user has access to the vault
        if (! $vault->users()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'You do not have access to this vault.',
            ], 403);
        }

        try {
            // Store uploaded file
            $uploadedFile = $request->file('file');
            $fileInfo = $fileService->store($uploadedFile, $user->account_id);

            // Validate CSV structure
            $filePath = $fileService->path($fileInfo['file_path']);
            $validation = $csvValidator->validate($filePath);

            if (! $validation['valid']) {
                // Delete uploaded file if validation fails
                $fileService->delete($fileInfo['file_path']);

                return response()->json([
                    'message' => 'CSV validation failed.',
                    'errors' => $validation['errors'],
                ], 422);
            }

            // Create import job record
            $importJob = ImportJob::create([
                'account_id' => $user->account_id,
                'user_id' => $user->id,
                'vault_id' => $vaultId,
                'filename' => $fileInfo['filename'],
                'file_path' => $fileInfo['file_path'],
                'total_rows' => 0, // Will be updated by the job
                'processed_rows' => 0,
                'failed_rows' => 0,
                'status' => ImportJob::STATUS_PENDING,
            ]);

            // Dispatch background job
            ProcessContactImport::dispatch($importJob, $vaultId);

            return response()->json([
                'message' => 'Import started successfully.',
                'data' => new ImportJobResource($importJob),
            ], 201);
        } catch (\Exception $e) {
            // Clean up file if it was created
            if (isset($fileInfo)) {
                $fileService->delete($fileInfo['file_path']);
            }

            return response()->json([
                'message' => 'Import initiation failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Import Status
     *
     * Retrieve the current status and progress of an import job.
     * Poll this endpoint to track import progress in real-time.
     *
     * @group Contact Import
     *
     * @urlParam id string required The UUID of the import job. Example: 9d2e1f12-3456-7890-abcd-ef1234567890
     *
     * @response {
     *   "data": {
     *     "id": "9d2e1f12-3456-7890-abcd-ef1234567890",
     *     "filename": "contacts.csv",
     *     "total_rows": 100,
     *     "processed_rows": 75,
     *     "failed_rows": 5,
     *     "status": "processing",
     *     "progress_pct": 75,
     *     "started_at": "2026-08-10T07:30:05.000000Z",
     *     "completed_at": null,
     *     "created_at": "2026-08-10T07:30:00.000000Z",
     *     "updated_at": "2026-08-10T07:30:45.000000Z"
     *   }
     * }
     * @response 404 scenario="Import not found" {
     *   "message": "Import job not found."
     * }
     * @response 403 scenario="Not authorized" {
     *   "message": "You are not authorized to view this import job."
     * }
     */
    public function show(string $id): JsonResponse
    {
        $user = Auth::user();

        // Find import job by ID.
        $importJob = ImportJob::find($id);

        if (! $importJob) {
            return response()->json([
                'message' => 'Import job not found.',
            ], 404);
        }

        if ($importJob->account_id !== $user->account_id) {
            return response()->json([
                'message' => 'You are not authorized to view this import job.',
            ], 403);
        }

        return response()->json([
            'data' => new ImportJobResource($importJob),
        ]);
    }

    /**
     * Get Import Errors
     *
     * Retrieve a paginated list of errors that occurred during the import process.
     * Each error includes the row number, original data, and error message.
     *
     * @group Contact Import
     *
     * @urlParam id string required The UUID of the import job. Example: 9d2e1f12-3456-7890-abcd-ef1234567890
     *
     * @queryParam per_page integer Number of errors per page. Defaults to 50. Example: 10
     * @queryParam page integer Page number. Defaults to 1. Example: 1
     *
     * @response {
     *   "current_page": 1,
     *   "data": [
     *     {
     *       "id": "9d2e1f13-1234-5678-abcd-ef1234567890",
     *       "import_job_id": "9d2e1f12-3456-7890-abcd-ef1234567890",
     *       "row_number": 5,
     *       "row_data": {
     *         "first_name": "",
     *         "last_name": "Doe",
     *         "middle_name": "",
     *         "nickname": "",
     *         "email": "invalid-email",
     *         "phone": ""
     *       },
     *       "error_message": "First name is required., Email must be a valid email address.",
     *       "created_at": "2026-08-10T07:30:15.000000Z"
     *     }
     *   ],
     *   "first_page_url": "http://localhost:8000/api/imports/9d2e1f12-3456-7890-abcd-ef1234567890/errors?page=1",
     *   "from": 1,
     *   "last_page": 1,
     *   "last_page_url": "http://localhost:8000/api/imports/9d2e1f12-3456-7890-abcd-ef1234567890/errors?page=1",
     *   "links": [
     *     {
     *       "url": null,
     *       "label": "&laquo; Previous",
     *       "active": false
     *     },
     *     {
     *       "url": "http://localhost:8000/api/imports/9d2e1f12-3456-7890-abcd-ef1234567890/errors?page=1",
     *       "label": "1",
     *       "active": true
     *     },
     *     {
     *       "url": null,
     *       "label": "Next &raquo;",
     *       "active": false
     *     }
     *   ],
     *   "next_page_url": null,
     *   "path": "http://localhost:8000/api/imports/9d2e1f12-3456-7890-abcd-ef1234567890/errors",
     *   "per_page": 10,
     *   "prev_page_url": null,
     *   "to": 1,
     *   "total": 1
     * }
     * @response 404 scenario="Import not found" {
     *   "message": "Import job not found."
     * }
     * @response 403 scenario="Not authorized" {
     *   "message": "You are not authorized to view this import job."
     * }
     */
    public function errors(string $id, Request $request): JsonResponse
    {
        $user = Auth::user();

        // Find import job
        $importJob = ImportJob::find($id);

        if (! $importJob) {
            return response()->json([
                'message' => 'Import job not found.',
            ], 404);
        }

        if ($importJob->account_id !== $user->account_id) {
            return response()->json([
                'message' => 'You are not authorized to view this import job.',
            ], 403);
        }

        // Get errors with pagination
        $perPage = $request->input('per_page', 50);
        $errors = $importJob->errors()
            ->orderedByRow()
            ->paginate($perPage);

        return response()->json($errors);
    }
}
