<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer as the credits page lists them: what they are allowed, what they owe, what is left and
 * how much of it is already late.
 *
 * `overdue_amount` and `available_credit` are worked out by the controller before this runs, since
 * both need the customer's credit transactions.
 */
class CreditCustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'credit_enabled' => (bool) $this->credit_enabled,
            'credit_limit' => (float) $this->credit_limit,
            'credit_balance' => (float) $this->credit_balance,
            'credit_terms_days' => $this->credit_terms_days,
            'available_credit' => (float) ($this->available_credit ?? 0),
            'overdue_amount' => (float) ($this->overdue_amount ?? 0),
            'created_at' => $this->created_at,
        ];
    }
}
