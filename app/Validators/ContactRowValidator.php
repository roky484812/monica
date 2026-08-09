<?php

namespace App\Validators;

use Illuminate\Support\Facades\Validator;

class ContactRowValidator
{
    /**
     * Validate a contact row from CSV.
     *
     * @return array{valid: bool, errors: array}
     */
    public function validate(array $rowData, int $rowNumber): array
    {
        $validator = Validator::make($rowData, $this->rules(), $this->messages());

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->all(),
            ];
        }

        return [
            'valid' => true,
            'errors' => [],
        ];
    }

    /**
     * Get validation rules for contact row.
     */
    private function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom validation messages.
     */
    private function messages(): array
    {
        return [
            'first_name.required' => 'First name is required.',
            'first_name.max' => 'First name must not exceed 255 characters.',
            'last_name.max' => 'Last name must not exceed 255 characters.',
            'middle_name.max' => 'Middle name must not exceed 255 characters.',
            'nickname.max' => 'Nickname must not exceed 255 characters.',
            'email.email' => 'Email must be a valid email address.',
            'email.max' => 'Email must not exceed 255 characters.',
            'phone.max' => 'Phone must not exceed 255 characters.',
        ];
    }

    /**
     * Sanitize row data (trim whitespace, convert empty strings to null).
     */
    public function sanitize(array $rowData): array
    {
        $sanitized = [];

        foreach ($rowData as $key => $value) {
            $trimmed = trim($value);
            $sanitized[$key] = $trimmed === '' ? null : $trimmed;
        }

        return $sanitized;
    }
}
