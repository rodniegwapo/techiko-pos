<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * What a product cost the business is for those who keep the books (admins and managers). A
 * cashier sees selling prices and stock, never cost, margin or stock value.
 */
class CostVisibility
{
    /** Signed in and allowed to see cost. Code running with no one signed in (jobs, console) sees it. */
    public static function allowed(): bool
    {
        $user = Auth::user();

        return $user === null || FinanceAccess::canManage($user);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function strip(array $data, array $fields): array
    {
        return self::allowed() ? $data : array_diff_key($data, array_flip($fields));
    }
}
