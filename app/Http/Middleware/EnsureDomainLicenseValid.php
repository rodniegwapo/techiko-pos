<?php

namespace App\Http\Middleware;

use App\Services\Licensing\OrganizationLicensingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDomainLicenseValid
{
    public function __construct(
        protected OrganizationLicensingService $licensing
    ) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('techiko-licensing.enforce_domain_license', false)) {
            return $next($request);
        }

        $domain = $request->route('domain');
        if ($domain === null) {
            return $next($request);
        }

        if (is_string($domain)) {
            $domain = \App\Models\Domain::where('name_slug', $domain)->firstOrFail();
        }

        $license = $this->licensing->currentUsableLicenseForDomain($domain);

        if (! $license?->isUsable()) {
            abort(403, 'No active license for this organization.');
        }

        return $next($request);
    }
}
