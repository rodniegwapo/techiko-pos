<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Clocking in and out on the POS. Whoever stands at the till enters their own PIN: no PIN, no
 * punch, so nobody clocks in for someone else. A punch toggles: clock in when they are not on the
 * clock, clock out when they are.
 */
class TimeclockService
{
    /** Wrong PINs allowed per minute on one device before it makes them wait. */
    public const MAX_WRONG_TRIES = 5;

    public function __construct(private readonly UserPinService $pins) {}

    /**
     * @param  string  $deviceKey  Who is at the device (the signed-in till user + address): wrong tries count against it.
     * @return array{action: 'in'|'out', user: array{id: int, name: string}, at: string, minutes_today: int, worked_minutes: int|null, note: string|null}
     *
     * @throws ValidationException On a wrong PIN, or too many wrong tries.
     */
    public function punch(Domain $domain, InventoryLocation $location, string $pin, string $deviceKey): array
    {
        $limiterKey = 'timeclock:'.$deviceKey;
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_WRONG_TRIES)) {
            throw ValidationException::withMessages([
                'pin' => ['Too many wrong PINs. Wait a minute and try again.'],
            ]);
        }

        $user = $this->pins->userForPin($domain->name_slug, $pin);
        if (! $user) {
            RateLimiter::hit($limiterKey, 60);
            throw ValidationException::withMessages(['pin' => ['That PIN is not right.']]);
        }
        RateLimiter::clear($limiterKey);

        return DB::transaction(function () use ($domain, $location, $user) {
            $now = now();

            // Locked so two quick punches on two devices can't both clock the same person in.
            $open = TimeEntry::query()->forDomain($domain->name_slug)
                ->where('user_id', $user->id)->onTheClock()
                ->lockForUpdate()->latest('clock_in_at')->first();

            if ($open) {
                $open->update(['clock_out_at' => $now]);

                return $this->result('out', $user, $domain, $open->fresh(), $now);
            }

            // An earlier entry left open past a shift's length stays open for a manager to close;
            // it does not become this punch's clock-out.
            $stale = TimeEntry::query()->forDomain($domain->name_slug)->where('user_id', $user->id)->forgotten()->exists();

            $entry = TimeEntry::query()->create([
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'user_id' => $user->id,
                'clock_in_at' => $now,
            ]);

            return $this->result('in', $user, $domain, $entry, $now, $stale
                ? 'An earlier clock-in was never closed. Ask a manager to fix it on Timesheets.'
                : null);
        });
    }

    /** Minutes this person has worked today (the organization's day), counting a stretch still open. */
    public function minutesToday(User $user, Domain $domain, ?Carbon $now = null): int
    {
        $tz = $domain->timezone ?: 'Asia/Manila';
        $now ??= now();
        $start = $now->copy()->timezone($tz)->startOfDay()->utc();

        return (int) TimeEntry::query()->forDomain($domain->name_slug)
            ->where('user_id', $user->id)
            ->where('clock_in_at', '>=', $start)
            ->get()
            ->sum(fn (TimeEntry $e) => $e->clock_out_at
                ? $e->minutes()
                : ($e->isForgotten() ? 0 : (int) $e->clock_in_at->diffInMinutes($now)));
    }

    private function result(string $action, User $user, Domain $domain, TimeEntry $entry, Carbon $now, ?string $note = null): array
    {
        return [
            'action' => $action,
            'user' => ['id' => $user->id, 'name' => $user->name],
            'at' => $now->toIso8601String(),
            'minutes_today' => $this->minutesToday($user, $domain, $now),
            'worked_minutes' => $action === 'out' ? $entry->minutes() : null,
            'note' => $note,
        ];
    }
}
