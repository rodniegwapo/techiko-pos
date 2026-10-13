<?php

namespace App\Services\Ai\Actions;

/** How a confirmed change went. */
final class ActionResult
{
    /**
     * @param  array<string, list<string>>  $errors  Validation errors by field.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $errors = [],
    ) {}

    public static function done(string $message): self
    {
        return new self(true, $message);
    }

    /** @param  array<string, list<string>>  $errors */
    public static function failed(string $message, array $errors = []): self
    {
        return new self(false, $message, $errors);
    }
}
