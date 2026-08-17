<?php

namespace App\Services\PayMongo;

use App\Models\PaymongoCheckout;
use App\Models\PaymongoWebhookEventLog;
use App\Services\DomainSubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayMongoCheckoutFulfillmentService
{
    public function __construct(
        private DomainSubscriptionService $subscriptionService,
        private PayMongoHttpClient $client,
    ) {}

    /**
     * @param  ?string  $webhookEventId  Skips duplicate PayMongo event ids when set.
     * @return bool True when a checkout was fulfilled.
     */
    public function fulfillIfPending(?string $paymentIntentId, int $paidAmountCentavos, ?string $webhookEventId = null): bool
    {
        if (! is_string($paymentIntentId) || $paymentIntentId === '') {
            return false;
        }

        if (is_string($webhookEventId) && $webhookEventId !== '') {
            try {
                PaymongoWebhookEventLog::query()->create(['event_id' => $webhookEventId]);
            } catch (QueryException $e) {
                if (str_contains(strtolower($e->getMessage()), 'unique')) {
                    return false;
                }

                throw $e;
            }
        }

        return DB::transaction(function () use ($paymentIntentId, $paidAmountCentavos): bool {
            /** @var PaymongoCheckout|null $checkout */
            $checkout = PaymongoCheckout::query()
                ->where('payment_intent_id', $paymentIntentId)
                ->where('status', PaymongoCheckout::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if ($checkout === null) {
                return false;
            }

            if ($paidAmountCentavos !== $checkout->amount_centavos) {
                Log::warning('PayMongo amount mismatch — checkout not fulfilled', [
                    'payment_intent_id' => $paymentIntentId,
                    'checkout_amount' => $checkout->amount_centavos,
                    'paid_amount' => $paidAmountCentavos,
                ]);

                return false;
            }

            $this->subscriptionService->grantServicingTier(
                $checkout->domain,
                (int) $checkout->service_tier_id,
            );

            $checkout->update([
                'status' => PaymongoCheckout::STATUS_PAID,
                'paid_at' => now(),
                'failure_reason' => null,
            ]);

            return true;
        }, 3);
    }

    public function syncPaymentIntent(string $intentId): bool
    {
        /** @var PaymongoCheckout|null $checkout */
        $checkout = PaymongoCheckout::query()
            ->where('payment_intent_id', $intentId)
            ->where('status', PaymongoCheckout::STATUS_PENDING)
            ->first();

        if ($checkout === null) {
            return false;
        }

        try {
            $body = $this->client->get('payment_intents/'.$intentId);
        } catch (\Throwable $e) {
            Log::warning('PayMongo retrieve PI failed', ['id' => $intentId, 'error' => $e->getMessage()]);

            return false;
        }

        $status = data_get($body, 'data.attributes.status');
        if ($status !== 'succeeded') {
            return false;
        }

        $amount = (int) data_get($body, 'data.attributes.amount', 0);

        return $this->fulfillIfPending($intentId, $amount, null);
    }
}
