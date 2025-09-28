<?php

namespace App\Http\Requests;

use App\Models\Product\Product;
use App\Models\InventoryLocation;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ValidateStockRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'location_id' => 'nullable|exists:inventory_locations,id',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateStockAvailability($validator);
        });
    }

    /**
     * Validate stock availability for all items
     */
    protected function validateStockAvailability(Validator $validator): void
    {
        $items = $this->input('items', []);
        $locationId = $this->input('location_id');
        
        $location = $locationId 
            ? InventoryLocation::find($locationId)
            : InventoryLocation::getDefault();

        if (!$location) {
            $validator->errors()->add('location_id', 'No valid inventory location found.');
            return;
        }

        $inventoryService = app(InventoryService::class);
        
        // Prepare items for stock check
        $stockItems = collect($items)->map(function ($item) {
            return [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
            ];
        })->toArray();

        // Check stock availability
        $unavailableItems = $inventoryService->checkStockAvailability($stockItems, $location);

        if (!empty($unavailableItems)) {
            foreach ($unavailableItems as $index => $unavailableItem) {
                $validator->errors()->add(
                    "items.{$index}.quantity",
                    "Insufficient stock for {$unavailableItem['product_name']}. " .
                    "Available: {$unavailableItem['available_quantity']}, " .
                    "Requested: {$unavailableItem['requested_quantity']}"
                );
            }
        }
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'items.required' => 'At least one item is required.',
            'items.*.product_id.required' => 'Product is required for each item.',
            'items.*.product_id.exists' => 'Selected product does not exist.',
            'items.*.quantity.required' => 'Quantity is required for each item.',
            'items.*.quantity.min' => 'Quantity must be greater than 0.',
            'location_id.exists' => 'Selected location does not exist.',
        ];
    }
}