<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Services\Licensing\OrganizationLicensingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDomainLicenseValid
{
    public function __construct(
        private OrganizationLicensingService $licensing
    ) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        if (method_exists($user, 'isSuperUser') && $user->isSuperUser()) {
            return $next($request);
        }

        $domain = $request->route('domain');
        if (! $domain instanceof Domain) {
            return $next($request);
        }

        $license = $this->licensing->primaryLicenseForDomain($domain);
        if (! $license || ! $license->isUsable()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'A valid organization license is required for this action.',
                ], 403);
            }

            return redirect()
                ->route('domains.dashboard', ['domain' => $domain->name_slug])
                ->with('error', 'Your organization does not have an active offline/desktop license. Contact your administrator.');
        }

        return $next($request);
    }
}
