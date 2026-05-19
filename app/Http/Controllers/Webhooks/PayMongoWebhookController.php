<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\PayMongo\PayMongoCheckoutFulfillmentService;
use App\Services\PayMongo\PayMongoSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayMongoWebhookController extends Controller
{
    public function handle(
        Request $request,
        PayMongoSignatureVerifier $verifier,
        PayMongoCheckoutFulfillmentService $fulfillment,
    ): JsonResponse {
        $rawBody = $request->getContent();

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($rawBody, true);
        if (! is_array($decoded)) {
            return response()->json(['error' => 'Invalid JSON'], 400);
        }

        $livemode = (bool) data_get($decoded, 'data.attributes.livemode', false);

        $signatureHeader = $request->header('Paymongo-Signature')
            ?? $request->header('paymongo-signature');

        if (! $verifier->verify($signatureHeader, $rawBody, $livemode)) {
            abort(401, 'Invalid webhook signature.');
        }

        logger('webhook received', ['decoded' => $decoded]);
        $eventType = data_get($decoded, 'data.attributes.type');
        $eventId = data_get($decoded, 'data.id');

        if ($eventType === 'payment.paid') {
            $paymentIntentId = data_get($decoded, 'data.attributes.data.attributes.payment_intent_id');
            $amount = (int) data_get($decoded, 'data.attributes.data.attributes.amount', 0);

            if (is_string($paymentIntentId) && $paymentIntentId !== '' && $amount > 0) {
                try {
                    $fulfillment->fulfillIfPending(
                        $paymentIntentId,
                        $amount,
                        is_string($eventId) ? $eventId : null,
                    );
                } catch (\Throwable $e) {
                    Log::error('PayMongo webhook fulfillment error', ['error' => $e->getMessage()]);

                    return response()->json(['error' => 'Processing failed'], 500);
                }
            }
        }

        return response()->json(['received' => true]);
    }
}
