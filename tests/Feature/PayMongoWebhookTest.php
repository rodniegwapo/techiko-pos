<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\PaymongoCheckout;
use App\Models\PaymongoWebhookEventLog;
use App\Models\ServiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayMongoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private static function signPaymongoWebhook(string $rawBody, bool $livemode, string $secret): string
    {
        $t = (string) time();
        $signed = hash_hmac('sha256', $t.'.'.$rawBody, $secret);
        $key = $livemode ? 'li' : 'te';

        return sprintf('t=%s,%s=%s', $t, $key, $signed);
    }

    /** @return array{0: Domain, 1: ServiceTier} */
    private function seedDomainAndTier(): array
    {
        $tier = ServiceTier::query()->create([
            'slug' => 'pro',
            'name' => 'Pro',
            'amount' => 1000,
            'sort_order' => 0,
            'is_active' => true,
            'max_products' => 100,
            'max_users' => 10,
        ]);

        $domain = Domain::query()->create([
            'name' => 'Acme Co',
            'name_slug' => 'acme',
        ]);

        return [$domain, $tier];
    }

    /** @param  array<string, mixed>  $overrides */
    private function webhookPayload(string $intentId, int $amountCentavos, string $eventId, bool $livemode = false, array $overrides = []): array
    {
        return array_replace_recursive([
            'data' => [
                'id' => $eventId,
                'attributes' => [
                    'type' => 'payment.paid',
                    'livemode' => $livemode,
                    'data' => [
                        'attributes' => [
                            'payment_intent_id' => $intentId,
                            'amount' => $amountCentavos,
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    public function test_valid_signature_grants_servicing_tier_once(): void
    {
        config(['paymongo.webhook_secret' => 'whsec_test_secret']);

        [$domain, $tier] = $this->seedDomainAndTier();

        PaymongoCheckout::query()->create([
            'payment_intent_id' => 'pi_grant_once',
            'domain' => $domain->name_slug,
            'service_tier_id' => $tier->id,
            'amount_centavos' => 100000,
            'status' => PaymongoCheckout::STATUS_PENDING,
            'billing_email' => null,
            'initiated_by' => null,
            'paid_at' => null,
            'failure_reason' => null,
            'expires_at' => null,
        ]);

        $bodyArr = $this->webhookPayload('pi_grant_once', 100000, 'evt_grant_once');
        $raw = json_encode($bodyArr, JSON_THROW_ON_ERROR);

        $sig = self::signPaymongoWebhook($raw, false, 'whsec_test_secret');

        $this->call(
            method: 'POST',
            uri: '/webhooks/paymongo',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PAYMONGO_SIGNATURE' => $sig,
            ],
            content: $raw,
        )->assertOk()->assertJson(['received' => true]);

        $domain->refresh();

        $this->assertSame($tier->id, (int) $domain->current_service_tier_id);
        $this->assertNotNull($domain->subscription_started_at);

        $checkout = PaymongoCheckout::query()->where('payment_intent_id', 'pi_grant_once')->firstOrFail();

        $this->assertSame(PaymongoCheckout::STATUS_PAID, $checkout->status);
        $this->assertNotNull($checkout->paid_at);
        $this->assertDatabaseHas((new PaymongoWebhookEventLog)->getTable(), [
            'event_id' => 'evt_grant_once',
        ]);
    }

    public function test_repeated_event_is_idempotent(): void
    {
        config(['paymongo.webhook_secret' => 'whsec_test_secret']);

        [$domain, $tier] = $this->seedDomainAndTier();

        PaymongoCheckout::query()->create([
            'payment_intent_id' => 'pi_idem',
            'domain' => $domain->name_slug,
            'service_tier_id' => $tier->id,
            'amount_centavos' => 5000,
            'status' => PaymongoCheckout::STATUS_PENDING,
            'billing_email' => null,
            'initiated_by' => null,
            'paid_at' => null,
            'failure_reason' => null,
            'expires_at' => null,
        ]);

        $bodyArr = $this->webhookPayload('pi_idem', 5000, 'evt_idem_twice');

        foreach (range(1, 3) as $i) {
            $raw = json_encode($bodyArr, JSON_THROW_ON_ERROR);
            $sig = self::signPaymongoWebhook($raw, false, 'whsec_test_secret');
            $this->call(
                method: 'POST',
                uri: '/webhooks/paymongo',
                server: [
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_PAYMONGO_SIGNATURE' => $sig,
                ],
                content: $raw,
            )->assertOk();
        }

        $this->assertSame(1, PaymongoWebhookEventLog::query()->where('event_id', 'evt_idem_twice')->count());
        $this->assertSame(1, PaymongoCheckout::query()->where('payment_intent_id', 'pi_idem')->where('status', PaymongoCheckout::STATUS_PAID)->count());

        $domain->refresh();

        $this->assertSame($tier->id, (int) $domain->current_service_tier_id);
        $started = $domain->subscription_started_at?->timestamp;
        $this->assertIsInt($started);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        config(['paymongo.webhook_secret' => 'whsec_test_secret']);

        [$domain, $tier] = $this->seedDomainAndTier();

        PaymongoCheckout::query()->create([
            'payment_intent_id' => 'pi_bad_sig',
            'domain' => $domain->name_slug,
            'service_tier_id' => $tier->id,
            'amount_centavos' => 100,
            'status' => PaymongoCheckout::STATUS_PENDING,
            'billing_email' => null,
            'initiated_by' => null,
            'paid_at' => null,
            'failure_reason' => null,
            'expires_at' => null,
        ]);

        $bodyArr = $this->webhookPayload('pi_bad_sig', 100, 'evt_bad_sig');
        $raw = json_encode($bodyArr, JSON_THROW_ON_ERROR);

        $sigWrong = self::signPaymongoWebhook($raw, false, 'wrong_secret');

        $this->call(
            method: 'POST',
            uri: '/webhooks/paymongo',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PAYMONGO_SIGNATURE' => $sigWrong,
            ],
            content: $raw,
        )->assertStatus(401);

        $domain->refresh();

        $this->assertNull($domain->current_service_tier_id);
    }

    public function test_amount_mismatch_does_not_fulfill(): void
    {
        config(['paymongo.webhook_secret' => 'whsec_test_secret']);

        [$domain, $tier] = $this->seedDomainAndTier();

        PaymongoCheckout::query()->create([
            'payment_intent_id' => 'pi_amt_bad',
            'domain' => $domain->name_slug,
            'service_tier_id' => $tier->id,
            'amount_centavos' => 100000,
            'status' => PaymongoCheckout::STATUS_PENDING,
            'billing_email' => null,
            'initiated_by' => null,
            'paid_at' => null,
            'failure_reason' => null,
            'expires_at' => null,
        ]);

        $bodyArr = $this->webhookPayload('pi_amt_bad', 99999, 'evt_amt_bad');
        $raw = json_encode($bodyArr, JSON_THROW_ON_ERROR);

        $sig = self::signPaymongoWebhook($raw, false, 'whsec_test_secret');

        $this->call(
            method: 'POST',
            uri: '/webhooks/paymongo',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PAYMONGO_SIGNATURE' => $sig,
            ],
            content: $raw,
        )->assertOk();

        $domain->refresh();

        $this->assertNull($domain->current_service_tier_id);

        $checkout = PaymongoCheckout::query()->where('payment_intent_id', 'pi_amt_bad')->firstOrFail();

        $this->assertSame(PaymongoCheckout::STATUS_PENDING, $checkout->status);

        $this->assertDatabaseHas((new PaymongoWebhookEventLog)->getTable(), [
            'event_id' => 'evt_amt_bad',
        ]);
    }
}
