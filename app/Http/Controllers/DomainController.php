<?php

namespace App\Http\Controllers;

use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Services\DomainService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DomainController extends Controller
{
    public function __construct(
        private DomainService $domainService
    ) {}
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $currentUser = auth()->user();
        $domains = $this->domainService->getFilteredDomains($request, $currentUser);

        return Inertia::render('Domains/Index', [
            'domains' => DomainResource::collection($domains),
            'timezones' => $this->domainService->getAvailableTimezones(),
            'currencies' => $this->domainService->getAvailableCurrencies(),
            'countries' => $this->domainService->getAvailableCountries(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Domains/Create', [
            'timezones' => $this->domainService->getAvailableTimezones(),
            'currencies' => $this->domainService->getAvailableCurrencies(),
            'countries' => $this->domainService->getAvailableCountries(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->domainService->getCreationValidationRules());

        $domain = $this->domainService->createDomain($validated);

        return redirect()->route('domains.index', $domain)
            ->with('success', 'Domain created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Domain $domain)
    {
        $domain->load(['users']);

        return Inertia::render('Domains/Show', [
            'domain' => new DomainResource($domain),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Domain $domain)
    {
        return Inertia::render('Domains/Edit', [
            'domain' => new DomainResource($domain),
            'timezones' => $this->domainService->getAvailableTimezones(),
            'currencies' => $this->domainService->getAvailableCurrencies(),
            'countries' => $this->domainService->getAvailableCountries(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Domain $domain)
    {
        $validated = $request->validate($this->domainService->getUpdateValidationRules($domain));

        $this->domainService->updateDomain($domain, $validated);

        return redirect()->route('domains.index', $domain)
            ->with('success', 'Domain updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Domain $domain)
    {
        $this->domainService->deleteDomain($domain);

        return redirect()->route('domains.index')
            ->with('success', 'Domain deleted successfully.');
    }
}
