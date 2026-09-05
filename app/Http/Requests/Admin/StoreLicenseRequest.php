<?php

namespace App\Http\Requests\Admin;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreLicenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'validity_type' => ['required', 'string', 'in:lifetime,custom'],
            'expires_at' => [
                'nullable',
                'required_if:validity_type,custom',
                'date',
                'after:now',
            ],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_email' => ['nullable', 'string', 'email', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Configure the validator instance with application active check.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $appId = $this->input('application_id');
            if ($appId && ! Application::where('id', $appId)->where('is_active', true)->exists()) {
                $validator->errors()->add('application_id', 'The selected application is inactive or does not exist.');
            }
        });
    }

    /**
     * Get processed attributes for license creation.
     *
     * @return array{customer_name: ?string, customer_email: ?string, notes: ?string, expires_at: ?string}
     */
    public function licenseData(): array
    {
        $isLifetime = $this->input('validity_type') === 'lifetime';

        return [
            'customer_name' => $this->filled('customer_name') ? trim((string) $this->input('customer_name')) : null,
            'customer_email' => $this->filled('customer_email') ? strtolower(trim((string) $this->input('customer_email'))) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
            'expires_at' => $isLifetime ? null : (string) $this->input('expires_at'),
        ];
    }
}
