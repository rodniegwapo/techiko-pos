<?php

namespace App\Services\Ai\Actions;

/**
 * A change the user can fill in from a form in the chat ("Name:", "Price (₱):"…) when they ask for
 * it without the details. The server builds the form, so it is the same every time and names this
 * business's real choices.
 */
interface HasInputTemplate
{
    /** Shown above the form, e.g. "Add product". */
    public function templateTitle(): string;

    /** @return list<array{label: string, required: bool, hint: ?string}> */
    public function template(ActionContext $context): array;
}
