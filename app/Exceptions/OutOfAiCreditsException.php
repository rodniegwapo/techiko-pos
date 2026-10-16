<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * The organization has used this month's AI credits (see AiCreditService::assertHasCredits).
 * Not a RuntimeException, so the AI controllers' "AI failed" handling (502) doesn't catch it.
 * billing_url is set when a plan has more credits and the user can open the plans.
 */
class OutOfAiCreditsException extends Exception
{
    public function __construct(string $message, public readonly ?string $billingUrl = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'out_of_credits' => true,
            'billing_url' => $this->billingUrl,
        ], fn ($value) => $value !== null), 402);
    }
}
