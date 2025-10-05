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
            self::ADMIN   => 'administrator',
            self::MANAGER => 'manager',
            self::CASHIER => 'cashier',
        };
    }
}
