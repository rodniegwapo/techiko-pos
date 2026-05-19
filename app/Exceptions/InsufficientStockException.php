<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    /** @var array<int, array<string, mixed>> */
    private array $unavailableItems;

    /**
     * @param  array<int, array{product_id: int, product_name: string, requested_quantity: int, available_quantity: int, shortage: int}>  $unavailableItems
     */
    public function __construct(
        array $unavailableItems,
        string $message = 'Insufficient stock',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->unavailableItems = $unavailableItems;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUnavailableItems(): array
    {
        return $this->unavailableItems;
    }
}
