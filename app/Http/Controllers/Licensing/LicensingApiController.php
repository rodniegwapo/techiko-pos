<?php

namespace App\Http\Controllers\Licensing;

use App\Http\Controllers\Controller;
use App\Services\Licensing\OrganizationLicensingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicensingApiController extends Controller
{
    public function __construct(
        private OrganizationLicensingService $licensing
    ) {}

    public function registerDevice(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->isSuperUser()) {
            return response()->json(['message' => 'Domain user required.'], 403);
        }

        $domain = $this->licensing->resolveDomainForUser($user);
        if (! $domain) {
            return response()->json(['message' => 'No organization context.'], 422);
        }

        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'max:512'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $license = $this->licensing->primaryLicenseForDomain($domain);
        if (! $license || ! $license->isUsable()) {
            return response()->json(['message' => 'No active license for this organization.'], 403);
        }

        try {
            $usage = $this->licensing->registerDevice($license, $validated['fingerprint'], [
                'name' => $validated['device_name'] ?? 'device',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'license_uid' => $license->uid,
            'usage_id' => (string) $usage->getKey(),
        ]);
    }

    public function issueOfflineToken(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->isSuperUser()) {
            return response()->json(['message' => 'Domain user required.'], 403);
        }

        $domain = $this->licensing->resolveDomainForUser($user);
        if (! $domain) {
            return response()->json(['message' => 'No organization context.'], 422);
        }

        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'max:512'],
            'ttl_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $license = $this->licensing->primaryLicenseForDomain($domain);
        if (! $license || ! $license->isUsable()) {
            return response()->json(['message' => 'No active license for this organization.'], 403);
        }

        $usage = $this->licensing->findUsageByFingerprint($license, $validated['fingerprint']);
        if (! $usage) {
            return response()->json(['message' => 'Device not registered. Call register-device first.'], 422);
        }

        $ttl = (int) ($validated['ttl_days'] ?? 7);
        $token = $this->licensing->issueOfflineToken($license, $usage, $ttl);

        return response()->json([
            'token' => $token,
            'ttl_days' => $ttl,
        ]);
    }
}
