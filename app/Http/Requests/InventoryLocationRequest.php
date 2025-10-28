<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryLocationRequest extends FormRequest
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
        $locationId = $this->route('location')?->id;
        
        return [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:10|unique:inventory_locations,code,' . $locationId,
            'type' => 'required|in:store,warehouse,supplier,customer',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
