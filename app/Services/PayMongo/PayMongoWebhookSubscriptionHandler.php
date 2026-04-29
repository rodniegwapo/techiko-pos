<?php

namespace App\Services\PayMongo;

use App\Models\Domain;
use Carbon\Carbon;

class PayMongoWebhookSubscriptionHandler
{
    public function handleEventPayload(array $eventWrapper): void
    {
        $type = data_get($eventWrapper, 'data.attributes.type');
        if (! is_string($type)) {
            return;
        }

        if (! str_starts_with($type, 'subscription.')) {
            return;
        }

        $subscriptionNode = data_get($eventWrapper, 'data.attributes.data');
        if (! is_array($subscriptionNode)) {
            return;
        }

        $subscriptionId = $subscriptionNode['id'] ?? null;
        if (! is_string($subscriptionId)) {
            return;
        }

        $attrs = $subscriptionNode['attributes'] ?? null;
        if (! is_array($attrs)) {
            return;
        }

        $status = $attrs['status'] ?? null;
        if (! is_string($status)) {
            return;
        }

        $customerId = $attrs['customer_id'] ?? null;

        $domain = Domain::query()
            ->where('paymongo_subscription_id', $subscriptionId)
            ->first();

        if (! $domain && is_string($customerId)) {
            $domain = Domain::query()
                ->where('paymongo_customer_id', $customerId)
                ->first();
        }

        if (! $domain) {
            return;
        }

        $active = $status === 'active';

        $nextBilling = $attrs['next_billing_schedule'] ?? null;
        $periodEnd = null;
        if (is_numeric($nextBilling)) {
            $periodEnd = Carbon::createFromTimestamp($nextBilling);
        }

        $domain->update([
            'paymongo_subscription_id' => $subscriptionId,
            'subscription_status' => $status,
            'subscription_active' => $active,
            'subscription_current_period_end' => $periodEnd,
        ]);
    }
}
