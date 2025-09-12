<?php

namespace App\Enums;

enum Role: string
{
    case ADMIN = 'admin';
    case MANAGER = 'manager';
    case CASHIER = 'cashier';

    public function label(): string
    {
        return match($this) {
            self::ADMIN   => 'Administrator',
            self::MANAGER => 'Manager',
            self::CASHIER => 'Cashier',
        };
    }
}
