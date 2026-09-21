<?php

namespace App\Http\Controllers;

use App\Helpers;
use App\Http\Resources\VoidLogResource;
use App\Models\Domain;
use App\Models\VoidLog;
use Illuminate\Http\Request;

class VoidLogController extends Controller
{
    /**
     * The void log, for one organization or — for a super user on the global page — for all of them.
     */
    public function index(Request $request, ?Domain $domain = null)
    {
        $range = Helpers::getDateRange($request->start_date, $request->end_date);
        $perPage = min(max((int) $request->input('per_page', 10), 1), 100);

        $data = VoidLog::query()
            // A void belongs to the organization whose sale it was struck from. Without this the
            // organization's own page listed every other organization's voids alongside its own.
            ->when($domain, fn ($query) => $query->whereHas(
                'saleItem.sale',
                fn ($sale) => $sale->where('domain', $domain->name_slug)
            ))
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->when($range && $request->input('start_date'), function ($query) use ($range) {
                $query->whereBetween('created_at', $range);
            })
            ->with([
                'approver:id,name',
                'user:id,name',
                'saleItem.product:id,name',
                // invoice_number is what the page shows as the sale reference.
                'saleItem.sale:id,domain,invoice_number',
            ])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return inertia('VoidLog/Index', [
            'items' => VoidLogResource::collection($data),
            'filters' => [
                'search' => $request->search,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
            ],
            'isGlobalView' => ! $domain,
        ]);
    }
}
