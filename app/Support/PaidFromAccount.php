<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * The bank or e-wallet account a payment left. Optional, and only kept when the payment was made by
 * bank transfer or e-wallet; any other method clears it.
 */
class PaidFromAccount
{
    public const METHODS = ['bank', 'ewallet'];

    /** @return list<mixed> */
    public static function rules(string $domainSlug): array
    {
        return [
            'nullable', 'integer',
            Rule::exists('financial_accounts', 'id')->where('domain', $domainSlug)->where('is_active', true),
        ];
    }

    public static function keep(?string $paymentMethod, mixed $accountId): ?int
    {
        return in_array($paymentMethod, self::METHODS, true) && $accountId ? (int) $accountId : null;
    }
}
