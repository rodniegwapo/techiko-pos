<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UserPin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Clocking in and out on the POS. Whoever stands at the till enters their own PIN: no PIN, no
 * punch, so nobody clocks in for someone else. The keypad punch toggles: clock in when they are not
 * on the clock, clock out when they are. Opening or closing your drawer can clock you in or out too,
 * with your own PIN.
 */
class TimeclockService
{
    /** Wrong PINs allowed per minute on one device before it makes them wait. */
    public const MAX_WRONG_TRIES = 5;

    public function __construct(private readonly UserPinService $pins) {}

    /**
     * The keypad punch: whoever's PIN it is, clocked in if they are off the clock and out if they are on it.
     *
     * @param  string  $deviceKey  Who is at the device (the signed-in till user + address): wrong tries count against it.
     * @return array{action: 'in'|'out', user: array{id: int, name: string}, at: string, minutes_today: int, worked_minutes: int|null, note: string|null}
     *
     * @throws ValidationException On a wrong PIN, or too many wrong tries.
     */
    public function punch(Domain $domain, InventoryLocation $location, string $pin, string $deviceKey): array
    {
        $user = $this->verifyPin($domain, $pin, $deviceKey);

        return DB::transaction(fn () => $this->clockOut($domain, $user)
            ?? $this->clockIn($domain, $location, $user));
    }

    /**
     * Who the PIN belongs to. Wrong tries on one device are limited; a right one clears them.
     *
     * @throws ValidationException On a wrong PIN, or too many wrong tries.
     */
    public function verifyPin(Domain $domain, string $pin, string $deviceKey, string $field = 'pin', ?User $mustBe = null): User
    {
        $limiterKey = $this->limiterKey($deviceKey);
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_WRONG_TRIES)) {
            throw ValidationException::withMessages([
                $field => ['Too many wrong PINs. Wait a minute and try again.'],
            ]);
        }

        $user = $this->pins->userForPin($domain->name_slug, $pin);
        if (! $user) {
            RateLimiter::hit($limiterKey, 60);
            throw ValidationException::withMessages([$field => ['That PIN is not right.']]);
        }
        // Someone else's PIN is a wrong try too, and doesn't wipe the earlier ones.
        if ($mustBe && (int) $user->id !== (int) $mustBe->id) {
            RateLimiter::hit($limiterKey, 60);
            throw ValidationException::withMessages([$field => ["That PIN isn't yours."]]);
        }
        RateLimiter::clear($limiterKey);

        return $user;
    }

    /**
     * The PIN must be this user's own: someone else's PIN doesn't clock them in, and counts as a wrong try.
     *
     * @throws ValidationException
     */
    public function verifyOwnPin(Domain $domain, User $user, string $pin, string $deviceKey, string $field = 'pin'): void
    {
        $this->verifyPin($domain, $pin, $deviceKey, $field, $user);
    }

    /** Clock this person in. Run it in a transaction with whatever else must go together with it. */
    public function clockIn(Domain $domain, InventoryLocation $location, User $user): array
    {
        $now = now();

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
    }

    /** Clock this person out of their open entry; null when they are not on the clock. */
    public function clockOut(Domain $domain, User $user): ?array
    {
        // Locked so two quick punches on two devices can't both clock the same person in.
        $open = TimeEntry::query()->forDomain($domain->name_slug)
            ->where('user_id', $user->id)->onTheClock()
            ->lockForUpdate()->latest('clock_in_at')->first();
        if (! $open) {
            return null;
        }

        $now = now();
        $open->update(['clock_out_at' => $now]);

        return $this->result('out', $user, $domain, $open->fresh(), $now);
    }

    /**
     * Whether this person is on the clock (and since when), and whether they have a PIN to punch with.
     *
     * @return array{on_the_clock: bool, since: string|null, has_pin: bool}
     */
    public function statusFor(Domain $domain, User $user): array
    {
        $open = TimeEntry::query()->forDomain($domain->name_slug)
            ->where('user_id', $user->id)->onTheClock()
            ->latest('clock_in_at')->first();

        return [
            'on_the_clock' => $open !== null,
            'since' => $open?->clock_in_at?->toIso8601String(),
            'has_pin' => UserPin::query()->where('user_id', $user->id)->where('active', true)->exists(),
        ];
    }

    /** Minutes this person has worked today (the organization's day), counting a stretch still open. */
    public function minutesToday(User $user, Domain $domain, ?Carbon $now = null): int
    {
        $tz = $domain->timezone ?: 'Asia/Manila';
        $now ??= now();
        $start = $now->copy()->timezone($tz)->startOfDay()->timezone(config('app.timezone'));

        return (int) TimeEntry::query()->forDomain($domain->name_slug)
            ->where('user_id', $user->id)
            ->where('clock_in_at', '>=', $start)
            ->get()
            ->sum(fn (TimeEntry $e) => $e->clock_out_at
                ? $e->minutes()
                : ($e->isForgotten() ? 0 : (int) $e->clock_in_at->diffInMinutes($now)));
    }

    private function limiterKey(string $deviceKey): string
    {
        return 'timeclock:'.$deviceKey;
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
