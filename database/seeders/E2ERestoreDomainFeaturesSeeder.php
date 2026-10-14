<?php

namespace Database\Seeders;

use App\Models\Domain;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Puts back the Finance / AI support switches E2EUserSeeder turned on for the e2e run, so running
 * the Playwright suite doesn't change what a super admin set. Run by tests/e2e/global-teardown.js.
 */
class E2ERestoreDomainFeaturesSeeder extends Seeder
{
    public static function backupPath(): string
    {
        return storage_path('app/e2e-domain-features.json');
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2ERestoreDomainFeaturesSeeder may only run in local or testing environments.');
        }

        $path = self::backupPath();
        if (! file_exists($path)) {
            return;
        }

        $saved = json_decode((string) file_get_contents($path), true) ?: [];

        foreach ($saved as $slug => $features) {
            $domain = Domain::query()->where('name_slug', $slug)->first();
            if (! $domain) {
                continue;
            }

            $settings = $domain->settings ?? [];
            if ($features === null) {
                unset($settings['features']);
            } else {
                $settings['features'] = $features;
            }
            $domain->update(['settings' => $settings]);
        }

        unlink($path);
    }
}
