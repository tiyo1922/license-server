<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RenewLicenseRequest extends FormRequest
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
            'validity_type' => ['nullable', 'string', 'in:lifetime,custom'],
            'expires_at' => [
                'nullable',
                'required_if:validity_type,custom',
                'date',
                'after:now',
            ],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $validityType = $this->validityType();
            if ($validityType === 'custom' && ! $this->filled('expires_at')) {
                $validator->errors()->add('expires_at', 'The expiration date field is required when validity type is custom.');
            }
            if ($validityType === null) {
                $validator->errors()->add('validity_type', 'The validity type or expiration date is required.');
            }
        });
    }

    /**
     * Resolve the explicit validity type.
     */
    public function validityType(): ?string
    {
        if ($this->filled('validity_type')) {
            return (string) $this->input('validity_type');
        }

        // Backward compatibility: If validity_type is omitted but expires_at is present, infer 'custom'
        if ($this->filled('expires_at')) {
            return 'custom';
        }

        return null;
    }

    /**
     * Resolve the new expiration timestamp string for custom validity type, or null for lifetime.
     */
    public function newExpiresAt(): ?string
    {
        return $this->validityType() === 'custom' ? (string) $this->input('expires_at') : null;
    }
}
