<?php

namespace Database\Seeders;

use App\Models\RegisterShift;
use App\Models\User;
use App\Support\Wallet\RegisterShiftService;
use Illuminate\Database\Seeder;

/**
 * Runs after every e2e run (tests/e2e/global-teardown.js): ends the shifts the run's setup opened
 * (E2ESalesSeeder tags them), so the demo accounts aren't left on shift in the dev database. Each
 * is ended the normal way, counted at its Expected. Shifts someone opened themselves aren't tagged
 * and are left alone.
 */
class E2EEndSetupShiftsSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(RegisterShiftService::class);

        RegisterShift::query()
            ->where('domain', E2ESalesSeeder::DOMAIN)
            ->where('status', RegisterShift::OPEN)
            ->where('notes', E2ESalesSeeder::SETUP_SHIFT_NOTE)
            ->get()
            ->each(function (RegisterShift $shift) use ($service) {
                $user = User::query()->find($shift->user_id);
                if ($user === null) {
                    return;
                }
                $service->end($shift, $user, ['counted_cash' => $service->figures($shift)['expected_cash'], 'notes' => 'Ended by the e2e test teardown']);
            });
    }
}
