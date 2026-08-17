<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\SharedProduct;
use App\Models\SharedProductSuggestion;
use App\Support\BarcodeNormalizer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SharedProductSuggestionController extends Controller
{
    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isSuperUser(), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeSuper($request);

        $status = $request->query('status', SharedProductSuggestion::STATUS_PENDING);
        $allowed = [
            SharedProductSuggestion::STATUS_PENDING,
            SharedProductSuggestion::STATUS_ACCEPTED,
            SharedProductSuggestion::STATUS_REJECTED,
            'all',
        ];
        if (! in_array($status, $allowed, true)) {
            $status = SharedProductSuggestion::STATUS_PENDING;
        }

        $query = SharedProductSuggestion::query()
            ->with(['submittedByUser:id,name']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $suggestions = $query->latest()->paginate(25)->withQueryString();

        return Inertia::render('Catalog/SharedSuggestions/Index', [
            'suggestions' => $suggestions,
            'statusFilter' => $status,
        ]);
    }

    public function accept(Request $request, SharedProductSuggestion $shared_product_suggestion)
    {
        $this->authorizeSuper($request);

        if ($shared_product_suggestion->status !== SharedProductSuggestion::STATUS_PENDING) {
            return redirect()->back()->with('error', 'Suggestion has already been processed.');
        }

        $barcode = BarcodeNormalizer::normalize($shared_product_suggestion->barcode);
        $snapshot = $shared_product_suggestion->snapshot ?? [];

        SharedProduct::updateOrCreate(
            ['barcode' => $barcode],
            [
                'name' => $snapshot['name'] ?? ('Product '.$barcode),
                'description' => $snapshot['description'] ?? null,
                'category_label' => $snapshot['category_label'] ?? null,
                'sold_type' => $snapshot['sold_type'] ?? null,
                'representation_type' => $snapshot['representation_type'] ?? null,
                'representation' => $snapshot['representation'] ?? null,
            ]
        );

        $shared_product_suggestion->update([
            'status' => SharedProductSuggestion::STATUS_ACCEPTED,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        return redirect()->back()->with('success', 'Suggestion accepted and catalog updated.');
    }

    public function reject(Request $request, SharedProductSuggestion $shared_product_suggestion)
    {
        $this->authorizeSuper($request);

        if ($shared_product_suggestion->status !== SharedProductSuggestion::STATUS_PENDING) {
            return redirect()->back()->with('error', 'Suggestion has already been processed.');
        }

        $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $shared_product_suggestion->update([
            'status' => SharedProductSuggestion::STATUS_REJECTED,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'rejection_reason' => $request->input('rejection_reason'),
        ]);

        return redirect()->back()->with('success', 'Suggestion rejected.');
    }
}
