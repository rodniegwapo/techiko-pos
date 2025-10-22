<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryLocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Add authorization logic if needed
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:10', 'unique:inventory_locations,code'],
            'type' => ['required', Rule::in(['store', 'warehouse', 'supplier', 'customer'])],
            'address' => ['nullable', 'string', 'max:1000'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        // Add domain validation for global view
        if ($this->has('domain') && $this->domain) {
            $rules['domain'] = ['required', 'string', 'exists:domains,name_slug'];
        }

        return $rules;
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'location name',
            'code' => 'location code',
            'type' => 'location type',
            'address' => 'address',
            'contact_person' => 'contact person',
            'phone' => 'phone number',
            'email' => 'email address',
            'is_active' => 'active status',
            'is_default' => 'default status',
            'notes' => 'notes',
            'domain' => 'domain',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The location name is required.',
            'code.required' => 'The location code is required.',
            'code.unique' => 'This location code is already in use.',
            'type.required' => 'Please select a location type.',
            'type.in' => 'The selected location type is invalid.',
            'email.email' => 'Please enter a valid email address.',
            'domain.required' => 'Please select a domain.',
            'domain.exists' => 'The selected domain is invalid.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
            'is_default' => $this->boolean('is_default', false),
            'code' => strtoupper($this->code ?? ''),
        ]);
    }
}
