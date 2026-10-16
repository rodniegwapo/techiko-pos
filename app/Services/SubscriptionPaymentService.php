<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Subscription payments and the next payment date they move (domains.subscription_ends_at).
 * A payment counts from the current due date, so a late payment keeps the billing day
 * (due Oct 15, paid Oct 20, next due Nov 15); without a due date it counts from the day it was paid.
 */
class SubscriptionPaymentService
{
    public function __construct(
        private readonly DomainSubscriptionService $subscriptions,
    ) {}

    /**
     * @param  array{service_tier_id: int, amount: float|string, months?: int, method: string, paid_on: string, reference_number?: ?string, bank_name?: ?string, note?: ?string, unlock?: bool, manual_payment_request_id?: ?int, paymongo_checkout_id?: ?int}  $data
     */
    public function record(Domain $domain, array $data, ?User $by): SubscriptionPayment
    {
        return DB::transaction(function () use ($domain, $data, $by) {
            /** @var Domain $domain */
            $domain = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $reference = isset($data['reference_number']) ? trim((string) $data['reference_number']) : null;

            if ($reference !== null && $reference !== '') {
                $duplicate = SubscriptionPayment::query()->active()
                    ->where('method', $data['method'])
                    ->where('reference_number', $reference)
                    ->first();
                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'reference_number' => 'This reference number was already recorded on '.$duplicate->paid_on->format('M j, Y').'.',
                    ]);
                }
            }

            $paidOn = Carbon::parse($data['paid_on'])->startOfDay();
            $months = (int) ($data['months'] ?? 1);
            $start = $domain->subscription_ends_at?->copy()->startOfDay() ?? $paidOn->copy();
            $end = $start->copy()->addMonthsNoOverflow($months);

            $payment = SubscriptionPayment::query()->create([
                'domain' => $domain->name_slug,
                'service_tier_id' => $data['service_tier_id'],
                'amount' => $data['amount'],
                'months' => $months,
                'method' => $data['method'],
                'reference_number' => $reference ?: null,
                'bank_name' => $data['bank_name'] ?? null,
                'paid_on' => $paidOn,
                'period_start' => $start,
                'period_end' => $end,
                'previous_ends_at' => $domain->subscription_ends_at,
                'note' => $data['note'] ?? null,
                'recorded_by' => $by?->id,
                'manual_payment_request_id' => $data['manual_payment_request_id'] ?? null,
                'paymongo_checkout_id' => $data['paymongo_checkout_id'] ?? null,
                // A super admin recorded or approved it; a PayMongo payment waits for their confirmation.
                'confirmed_at' => $data['method'] === 'paymongo' ? null : now(),
                'confirmed_by' => $data['method'] === 'paymongo' ? null : $by?->id,
            ]);

            $changes = ['subscription_ends_at' => $end];
            // Paying for another plan switches to it, starting the day it was paid.
            if ((int) $domain->current_service_tier_id !== (int) $data['service_tier_id']) {
                $this->subscriptions->grantServicingTier($domain->name_slug, (int) $data['service_tier_id']);
                $domain->refresh();
                $changes['subscription_started_at'] = $paidOn;
            } elseif (! $domain->subscription_started_at) {
                $changes['subscription_started_at'] = $paidOn;
            }
            if (! empty($data['unlock'])) {
                $changes['locked_at'] = null;
            }
            $domain->update($changes);

            return $payment;
        });
    }

    /** Undoes the latest payment (a mistake): it stays listed as voided and the next payment date goes back. */
    public function void(SubscriptionPayment $payment, User $by): void
    {
        DB::transaction(function () use ($payment, $by) {
            $latest = SubscriptionPayment::query()->active()->forDomain($payment->domain)->latest('id')->lockForUpdate()->first();
            if ($payment->voided_at !== null || $latest?->id !== $payment->id) {
                throw ValidationException::withMessages(['payment' => 'Only the latest payment can be voided.']);
            }

            $payment->update(['voided_at' => now(), 'voided_by' => $by->id]);
            Domain::query()->where('name_slug', $payment->domain)->update(['subscription_ends_at' => $payment->previous_ends_at]);
        });
    }

    /** The super admin checked a PayMongo payment arrived. Dates don't change (it already counted). */
    public function confirm(SubscriptionPayment $payment, User $by): void
    {
        if ($payment->needsConfirmation()) {
            $payment->update(['confirmed_at' => now(), 'confirmed_by' => $by->id]);
        }
    }

    /** @return array{due_on: string, amount: ?float, days_left: int}|null When the next payment is due, and how much the current plan costs. */
    public function nextPayment(Domain $domain): ?array
    {
        if (! $domain->subscription_ends_at) {
            return null;
        }
        $domain->loadMissing('currentServiceTier');

        return [
            'due_on' => $domain->subscription_ends_at->toDateString(),
            'amount' => $domain->currentServiceTier ? (float) $domain->currentServiceTier->amount : null,
            'days_left' => $domain->subscriptionEndsInDays(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function history(Domain $domain, int $limit = 20, bool $withVoided = true): array
    {
        return SubscriptionPayment::query()
            ->forDomain($domain->name_slug)
            ->when(! $withVoided, fn ($q) => $q->active())
            ->with(['serviceTier:id,name', 'recordedByUser:id,name'])
            ->latest('paid_on')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (SubscriptionPayment $payment) => $payment->toRow())
            ->all();
    }
}
