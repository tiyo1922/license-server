<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreApplicationApiKeyRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * Get processed attributes for API key creation.
     *
     * @return array{name: string, expires_at: ?string}
     */
    public function apiKeyData(): array
    {
        return [
            'name' => trim((string) $this->input('name')),
            'expires_at' => $this->filled('expires_at') ? (string) $this->input('expires_at') : null,
        ];
    }
}
