<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Domain;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CustomerController extends Controller
{
    /** 1–100 per page; anything else (missing, 0, "abc") falls back to 15. */
    private function perPage(Request $request): int
    {
        $perPage = filter_var($request->input('per_page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 15;

        return min($perPage, 100);
    }

    /**
     * Display a listing of customers for the domain.
     */
    public function index(Request $request, Domain $domain)
    {
        $query = Customer::query()
            ->where('domain', $domain->name_slug)
            ->when($request->search, function ($query, $search) {
                // Use Searchable trait on Customer: supports name, email, phone, etc.
                return $query->search($search);
            })
            ->when($request->tier, function ($query, $tier) {
                return $query->where('tier', $tier);
            })
            // Enrolled means the member holds points, the rule the loyalty figures already use.
            ->when($request->loyalty_status, function ($query, $status) {
                return $status === 'enrolled'
                    ? $query->where('loyalty_points', '>', 0)
                    : $query->where('loyalty_points', '<=', 0);
            })
            ->when($request->date_range, function ($query, $range) {
                $since = match ($range) {
                    '7_days' => now()->subDays(7),
                    '30_days' => now()->subDays(30),
                    '3_months' => now()->subMonths(3),
                    '1_year' => now()->subYear(),
                    default => null,
                };

                return $since ? $query->where('created_at', '>=', $since) : $query;
            });

        $customers = $query->latest()->paginate($this->perPage($request));

        return Inertia::render('Customers/Index', [
            'items' => CustomerResource::collection($customers),
            'filters' => $request->only(['search', 'tier', 'loyalty_status', 'date_range']),
            'currentDomain' => $domain,
            'isGlobalView' => false,
        ]);
    }

    /**
     * Store a newly created customer for the domain.
     */
    public function store(Request $request, Domain $domain)
    {
        $validated = $request->validate($this->rules($domain));

        $validated['domain'] = $domain->name_slug;
        Customer::create($validated);

        return redirect()->back()->with('success', 'Customer created successfully');
    }

    /**
     * An email only has to be free within the organization: two organizations may both know a
     * customer by the same address.
     *
     * @return array<string, mixed>
     */
    private function rules(Domain $domain, ?Customer $customer = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => [
                'nullable', 'email',
                Rule::unique('customers', 'email')
                    ->where('domain', $domain->name_slug)
                    ->ignore($customer?->id),
            ],
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
        ];
    }

    /**
     * Update the specified customer for the domain.
     */
    public function update(Request $request, Domain $domain, Customer $customer)
    {
        // Ensure customer belongs to this domain
        if ($customer->domain !== $domain->name_slug) {
            abort(403, 'Customer does not belong to this domain');
        }

        $validated = $request->validate($this->rules($domain, $customer));

        $customer->update($validated);

        return $this->respond($request, 'Customer updated successfully');
    }

    /**
     * The page saves over Inertia, which needs a redirect it can follow, while axios callers want
     * the JSON they asked for.
     */
    private function respond(Request $request, string $message)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Remove the specified customer from the domain.
     */
    public function destroy(Request $request, Domain $domain, Customer $customer)
    {
        // Ensure customer belongs to this domain
        if ($customer->domain !== $domain->name_slug) {
            abort(403, 'Customer does not belong to this domain');
        }

        // Check if customer has sales
        if ($customer->sales()->count() > 0) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete customer with existing sales',
                ], 422);
            }

            return redirect()->back()->with('error', 'Cannot delete customer with existing sales');
        }

        $customer->delete();

        return $this->respond($request, 'Customer deleted successfully');
    }
}
