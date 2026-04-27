<?php

namespace App\Services\Licensing;

use App\Models\Domain;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;

class OrganizationLicensingService
{
    /**
     * Create and persist a license for a domain (organization) with a new license key.
     */
    public function issueLicenseForDomain(
        Domain $domain,
        int $maxSeats = 5,
        ?\DateTimeInterface $expiresAt = null
    ): License {
        $expiresAt ??= now()->addYear();

        $offline = config('licensing.offline_token', []);
        $offline['enabled'] = true;

        return License::createWithKey([
            'licensable_type' => Domain::class,
            'licensable_id' => (string) $domain->getKey(),
            'status' => LicenseStatus::Active,
            'expires_at' => $expiresAt,
            'max_usages' => $maxSeats,
            'activated_at' => now(),
            'meta' => [
                'offline_token' => $offline,
            ],
        ]);
    }

    public function currentUsableLicenseForDomain(Domain $domain): ?License
    {
        return $domain->licenses()
            ->whereIn('status', [LicenseStatus::Active, LicenseStatus::Grace])
            ->orderByDesc('activated_at')
            ->first();
    }
}
