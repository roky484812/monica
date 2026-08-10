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
     * Initiate a new contact import.
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
     * Get the status of a specific import job.
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
     * Get errors for a specific import job.
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
