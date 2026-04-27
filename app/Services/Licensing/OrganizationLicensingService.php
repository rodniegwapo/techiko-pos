<?php

namespace App\Services\Licensing;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Collection;
use LucaLongo\Licensing\Facades\Licensing;
use LucaLongo\Licensing\Enums\UsageStatus;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;

class OrganizationLicensingService
{
    public function resolveDomainForUser(User $user): ?Domain
    {
        if ($user->isSuperUser() || ! $user->domain) {
            return null;
        }

        return Domain::query()->where('name_slug', $user->domain)->first();
    }

    public function primaryLicenseForDomain(Domain $domain): ?License
    {
        /** @var License|null */
        return $domain->licenses()->orderByDesc('created_at')->first();
    }

    /**
     * @return array{usable: bool, days_until_expiration: int|null, seats_used: int, seats_max: int|null, status: string|null, license_id: string|null}
     */
    public function summaryForDomain(Domain $domain): array
    {
        $license = $this->primaryLicenseForDomain($domain);

        if (! $license) {
            return [
                'usable' => false,
                'days_until_expiration' => null,
                'seats_used' => 0,
                'seats_max' => null,
                'status' => null,
                'license_id' => null,
            ];
        }

        $seatsUsed = $license->usages()
            ->where('status', UsageStatus::Active)
            ->count();

        return [
            'usable' => $license->isUsable(),
            'days_until_expiration' => $license->daysUntilExpiration(),
            'seats_used' => $seatsUsed,
            'seats_max' => $license->max_usages,
            'status' => $license->status->value,
            'license_id' => $license->id,
        ];
    }

    /**
     * @param  array{max_usages: int, expires_at?: \DateTimeInterface|string|null}  $data
     */
    public function createAndActivateForDomain(Domain $domain, array $data): License
    {
        $expires = isset($data['expires_at']) && $data['expires_at'] !== null && $data['expires_at'] !== ''
            ? \Carbon\Carbon::parse($data['expires_at'])
            : now()->addYear();

        $license = License::createWithKey([
            'licensable_type' => Domain::class,
            'licensable_id' => $domain->getKey(),
            'max_usages' => (int) $data['max_usages'],
            'expires_at' => $expires,
        ]);

        $license->activate();

        return $license->fresh();
    }

    public function registerDevice(
        License $license,
        string $fingerprint,
        array $metadata = []
    ): LicenseUsage {
        if (! $license->isUsable()) {
            throw new \InvalidArgumentException('License is not usable for device registration.');
        }

        return Licensing::register($license, $fingerprint, $metadata);
    }

    public function issueOfflineToken(License $license, LicenseUsage $usage, int $ttlDays = 7): string
    {
        return Licensing::issueToken($license, $usage, ['ttl_days' => $ttlDays]);
    }

    public function findUsageByFingerprint(License $license, string $fingerprint): ?LicenseUsage
    {
        /** @var LicenseUsage|null */
        return $license->usages()
            ->where('usage_fingerprint', $fingerprint)
            ->where('status', UsageStatus::Active)
            ->first();
    }

    /**
     * @return Collection<int, LicenseUsage>
     */
    public function listActiveUsages(License $license): Collection
    {
        return $license->usages()
            ->where('status', UsageStatus::Active)
            ->orderBy('registered_at')
            ->get();
    }
}
