<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ManualPaymentRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminManualPaymentsController extends Controller
{
    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isSuperUser(), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeSuper($request);

        $status = $request->query('status', ManualPaymentRequest::STATUS_PENDING);
        if (! in_array($status, [
            ManualPaymentRequest::STATUS_PENDING,
            ManualPaymentRequest::STATUS_APPROVED,
            ManualPaymentRequest::STATUS_REJECTED,
            'all',
        ], true)) {
            $status = ManualPaymentRequest::STATUS_PENDING;
        }

        $query = ManualPaymentRequest::query()
            ->with(['serviceTier', 'submittedByUser', 'reviewedByUser'])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('domain')) {
            $query->where('domain', 'like', '%'.$request->domain.'%');
        }

        $requests = $query->paginate(25)->withQueryString();

        return Inertia::render('Billing/ManualPayments/Index', [
            'requests' => $requests,
            'filters' => [
                'status' => $status,
                'domain' => $request->query('domain'),
            ],
        ]);
    }

    public function approve(Request $request, ManualPaymentRequest $manual_payment_request)
    {
        $this->authorizeSuper($request);

        abort_unless($manual_payment_request->isPending(), 422, 'Request is not pending.');

        $data = $request->validate([
            'reviewer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $manual_payment_request->update([
            'status' => ManualPaymentRequest::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'reviewer_note' => $data['reviewer_note'] ?? null,
        ]);

        $domain = Domain::query()->where('name_slug', $manual_payment_request->domain)->first();
        if ($domain) {
            $domain->update([
                'current_service_tier_id' => $manual_payment_request->service_tier_id,
                'subscription_started_at' => now(),
            ]);
        }

        return redirect()->back()->with('success', 'Payment approved.');
    }

    public function reject(Request $request, ManualPaymentRequest $manual_payment_request)
    {
        $this->authorizeSuper($request);

        abort_unless($manual_payment_request->isPending(), 422, 'Request is not pending.');

        $data = $request->validate([
            'reviewer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $manual_payment_request->update([
            'status' => ManualPaymentRequest::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'reviewer_note' => $data['reviewer_note'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Payment rejected.');
    }
}
