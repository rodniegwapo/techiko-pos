<?php

namespace App\Services\PayMongo;

use App\Models\Domain;
use App\Models\PaymongoCheckout;
use App\Models\ServiceTier;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PayMongoQrPhCheckoutService
{
    public function __construct(
        private PayMongoHttpClient $client
    ) {}

    /**
     * @return array{
     *     checkout: PaymongoCheckout,
     *     qr_image_data_url: string|null,
     *     payment_intent_status: string,
     * }
     */
    public function startCheckout(Domain $domain, ServiceTier $tier, User $user): array
    {
        if (! $tier->is_active) {
            throw ValidationException::withMessages(['service_tier_id' => [__('This plan is not available.')]]);
        }

        $amountCentavos = (int) round((float) $tier->amount * 100);
        if ($amountCentavos < 1) {
            throw ValidationException::withMessages(['service_tier_id' => [__('Invalid plan amount.')]]);
        }

        $piPayload = [
            'data' => [
                'attributes' => [
                    'amount' => $amountCentavos,
                    'currency' => 'PHP',
                    'payment_method_allowed' => ['qrph'],
                    'capture_type' => 'automatic',
                    'description' => 'Servicing: '.$tier->name.' ('.$domain->name_slug.')',
                    'metadata' => [
                        'domain_slug' => $domain->name_slug,
                        'service_tier_id' => (string) $tier->id,
                    ],
                ],
            ],
        ];

        try {
            $pi = $this->client->post('payment_intents', $piPayload);
        } catch (RuntimeException $e) {
            Log::warning('PayMongo create PI failed', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'paymongo' => [__('Payment could not be started. Check PayMongo configuration.')],
            ]);
        }

        $piId = data_get($pi, 'data.id');
        $clientKey = data_get($pi, 'data.attributes.client_key');
        if (! is_string($piId)) {
            Log::warning('PayMongo PI response missing id', ['body' => $pi]);

            throw ValidationException::withMessages([
                'paymongo' => [__('Unexpected PayMongo response. Try again.')],
            ]);
        }

        $email = is_string($user->email) && $user->email !== ''
            ? $user->email
            : ('billing+'.$domain->name_slug.'@example.invalid');

        $pmPayload = [
            'data' => [
                'attributes' => [
                    'type' => 'qrph',
                    'billing' => [
                        'name' => $user->name ?: 'Subscriber',
                        'email' => $email,
                        'phone' => '',
                    ],
                ],
            ],
        ];

        try {
            $pm = $this->client->post('payment_methods', $pmPayload);
        } catch (RuntimeException $e) {
            Log::warning('PayMongo PM failed', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'paymongo' => [__('QR Ph setup failed.')],
            ]);
        }

        $pmId = data_get($pm, 'data.id');
        if (! is_string($pmId)) {
            Log::warning('PayMongo PM response missing id', ['body' => $pm]);

            throw ValidationException::withMessages([
                'paymongo' => [__('Unable to create QR Ph payment method.')],
            ]);
        }

        try {
            $attach = $this->client->post("payment_intents/{$piId}/attach", [
                'data' => [
                    'attributes' => [
                        'payment_method' => $pmId,
                    ],
                ],
            ]);
        } catch (RuntimeException $e) {
            Log::warning('PayMongo attach failed', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'paymongo' => [__('Unable to prepare QR payment.')],
            ]);
        }

        $status = data_get($attach, 'data.attributes.status', '');
        $imageUrl = data_get($attach, 'data.attributes.next_action.code.image_url');
        if (! is_string($imageUrl) || $imageUrl === '') {
            $imageUrl = null;
        }

        $checkout = PaymongoCheckout::query()->create([
            'payment_intent_id' => $piId,
            'client_key' => is_string($clientKey) ? $clientKey : null,
            'domain' => $domain->name_slug,
            'service_tier_id' => $tier->id,
            'amount_centavos' => $amountCentavos,
            'status' => PaymongoCheckout::STATUS_PENDING,
            'billing_email' => $user->email,
            'initiated_by' => $user->id,
            'expires_at' => now()->addMinutes(30),
        ]);

        return [
            'checkout' => $checkout->fresh(),
            'qr_image_data_url' => $imageUrl,
            'payment_intent_status' => is_string($status) ? $status : '',
        ];
    }
}
