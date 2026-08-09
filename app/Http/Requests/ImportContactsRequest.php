<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportContactsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240', // 10 MB
            ],
            'vault_id' => [
                'required',
                'string',
                'exists:vaults,id',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please provide a CSV file to import.',
            'file.file' => 'The uploaded file is invalid.',
            'file.mimes' => 'Only CSV files are allowed.',
            'file.max' => 'The file size must not exceed 10 MB.',
            'vault_id.required' => 'Please specify a vault ID.',
            'vault_id.exists' => 'The specified vault does not exist.',
        ];
    }
}
