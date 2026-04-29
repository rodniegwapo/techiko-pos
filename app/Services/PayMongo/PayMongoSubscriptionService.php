<?php

namespace App\Services\PayMongo;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\Client\RequestException;

class PayMongoSubscriptionService
{
    public function __construct(
        private PayMongoClient $client
    ) {}

    /**
     * Create PayMongo customer + subscription; persist IDs on the domain. Returns parsed subscription payload for the UI.
     *
     * @return array{subscription_id: string, status: ?string, raw: array}
     *
     * @throws RequestException
     */
    public function startSubscription(Domain $domain, User $user): array
    {
        $planId = config('paymongo.plan_id');
        if (! $planId) {
            throw new \InvalidArgumentException('PAYMONGO_PLAN_ID is not configured.');
        }

        if (! $domain->paymongo_customer_id) {
            $this->createPayMongoCustomer($domain, $user);
        }

        $body = [
            'data' => [
                'attributes' => [
                    'customer_id' => $domain->paymongo_customer_id,
                    'plan_id' => $planId,
                ],
            ],
        ];

        $json = $this->client->post('v1/subscriptions', $body);

        $subscriptionId = data_get($json, 'data.id');
        $status = data_get($json, 'data.attributes.status');

        if (! $subscriptionId) {
            throw new \RuntimeException('PayMongo did not return a subscription id.');
        }

        $domain->update([
            'paymongo_subscription_id' => $subscriptionId,
            'subscription_status' => $status,
            'subscription_active' => $status === 'active',
        ]);

        return [
            'subscription_id' => $subscriptionId,
            'status' => $status,
            'raw' => $json,
        ];
    }

    private function createPayMongoCustomer(Domain $domain, User $user): void
    {
        $name = trim($user->name ?? '') ?: $domain->name;
        $parts = preg_split('/\s+/', $name, 2);
        $first = $parts[0] ?? 'Organization';
        $last = $parts[1] ?? $domain->name_slug;

        $email = $user->email ?: $domain->name_slug.'@billing.example';

        $body = [
            'data' => [
                'attributes' => [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'default_device' => 'email',
                    'metadata' => [
                        'domain_name_slug' => (string) $domain->name_slug,
                        'domain_id' => (string) $domain->id,
                    ],
                ],
            ],
        ];

        $json = $this->client->post('v1/customers', $body);
        $customerId = data_get($json, 'data.id');
        if (! $customerId) {
            throw new \RuntimeException('PayMongo did not return a customer id.');
        }

        $domain->update([
            'paymongo_customer_id' => $customerId,
        ]);
        $domain->refresh();
    }
}
