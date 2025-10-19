<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Http\Request;

class DomainService
{
    /**
     * Get filtered domains
     */
    public function getFilteredDomains(Request $request, $currentUser = null)
    {
        $query = Domain::with(['users']);

        // Super users can see all domains, others see only their domain
        if ($currentUser && !$currentUser->isSuperUser() && $currentUser->domain_id) {
            $query->where('id', $currentUser->domain_id);
        }

        // Apply search using Searchable trait
        if ($request->input('search')) {
            $query->search($request->input('search'));
        }

        // Apply status filter
        if ($request->input('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        // Apply country filter
        if ($request->input('country')) {
            $query->where('country_code', $request->input('country'));
        }

        return $query->orderBy('name')->paginate(15);
    }

    /**
     * Get domain by slug
     */
    public function getDomainBySlug(string $slug): ?Domain
    {
        return Domain::where('name_slug', $slug)
                    ->where('is_active', true)
                    ->first();
    }

    /**
     * Create a new domain
     */
    public function createDomain(array $data): Domain
    {
        return Domain::create($data);
    }

    /**
     * Update domain
     */
    public function updateDomain(Domain $domain, array $data): Domain
    {
        $domain->update($data);
        return $domain;
    }

    /**
     * Delete domain
     */
    public function deleteDomain(Domain $domain): bool
    {
        return $domain->delete();
    }

    /**
     * Get validation rules for creating domain
     */
    public function getCreationValidationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'name_slug' => 'required|string|max:255|unique:domains,name_slug',
            'timezone' => 'required|string|max:50',
            'country_code' => 'required|string|size:2',
            'currency_code' => 'required|string|size:3',
            'date_format' => 'required|string|max:20',
            'time_format' => 'required|string|in:12h,24h',
            'language_code' => 'required|string|max:5',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get validation rules for updating domain
     */
    public function getUpdateValidationRules(Domain $domain): array
    {
        return [
            'name' => 'required|string|max:255',
            'name_slug' => 'required|string|max:255|unique:domains,name_slug,' . $domain->id,
            'timezone' => 'required|string|max:50',
            'country_code' => 'required|string|size:2',
            'currency_code' => 'required|string|size:3',
            'date_format' => 'required|string|max:20',
            'time_format' => 'required|string|in:12h,24h',
            'language_code' => 'required|string|max:5',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get available timezones
     */
    public function getAvailableTimezones(): array
    {
        return [
            'Asia/Manila' => 'Manila (Philippines)',
            'Asia/Tokyo' => 'Tokyo (Japan)',
            'America/New_York' => 'New York (USA)',
            'Europe/London' => 'London (UK)',
            'Europe/Paris' => 'Paris (France)',
            'Asia/Shanghai' => 'Shanghai (China)',
            'Asia/Singapore' => 'Singapore',
            'Australia/Sydney' => 'Sydney (Australia)',
        ];
    }

    /**
     * Get available currencies
     */
    public function getAvailableCurrencies(): array
    {
        return [
            'PHP' => 'Philippine Peso (₱)',
            'USD' => 'US Dollar ($)',
            'JPY' => 'Japanese Yen (¥)',
            'EUR' => 'Euro (€)',
            'GBP' => 'British Pound (£)',
            'CNY' => 'Chinese Yuan (¥)',
            'SGD' => 'Singapore Dollar (S$)',
            'AUD' => 'Australian Dollar (A$)',
        ];
    }

    /**
     * Get available countries
     */
    public function getAvailableCountries(): array
    {
        return [
            'PH' => 'Philippines',
            'US' => 'United States',
            'JP' => 'Japan',
            'GB' => 'United Kingdom',
            'FR' => 'France',
            'CN' => 'China',
            'SG' => 'Singapore',
            'AU' => 'Australia',
        ];
    }
}
