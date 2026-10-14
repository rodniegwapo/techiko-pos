<?php

namespace App\Services\Ai\Actions;

/** A checked change, ready to show on the confirm card and to run once the user confirms. */
final class PreparedAction
{
    /**
     * @param  array<string, mixed>  $input  What run() receives.
     * @param  list<array{label: string, value: string}>  $fields  What the confirm card lists.
     */
    public function __construct(
        public readonly string $title,
        public readonly array $input,
        public readonly array $fields,
        public readonly string $confirmLabel = 'Confirm',
    ) {}
}
