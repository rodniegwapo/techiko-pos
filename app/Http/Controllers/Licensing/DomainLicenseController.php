<?php

namespace App\Http\Controllers\Licensing;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Licensing\OrganizationLicensingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
class DomainLicenseController extends Controller
{
    public function __construct(
        private OrganizationLicensingService $licensing
    ) {
        $this->middleware(function ($request, $next) {
            if (! $request->user() || ! $request->user()->isSuperUser()) {
                abort(403);
            }

            return $next($request);
        });
    }

    public function store(Request $request, Domain $domain): RedirectResponse
    {
        $validated = $request->validate([
            'max_usages' => ['required', 'integer', 'min:1', 'max:10000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $license = $this->licensing->createAndActivateForDomain($domain, $validated);
        $plainKey = $license->license_key;

        return redirect()
            ->route('domains.show', $domain)
            ->with('success', 'Organization license created and activated.')
            ->with('new_license_key', $plainKey);
    }

    public function revokeUsage(Domain $domain, string $usage): RedirectResponse
    {
        $license = $this->licensing->primaryLicenseForDomain($domain);
        if (! $license) {
            return redirect()->route('domains.show', $domain)->with('error', 'No license for this organization.');
        }

        $u = $license->usages()->whereKey($usage)->first();
        if (! $u) {
            abort(404);
        }
        $u->revoke('revoked by administrator');

        return redirect()->route('domains.show', $domain)->with('success', 'Device activation revoked.');
    }
}
