<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\FinanceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Timesheets for managers: who worked when, per person per day, with the hours to pay. A manager
 * can fix a forgotten clock-out or add a missed shift; every change keeps who made it and why.
 * Admins see every store; a manager sees their own.
 */
class TimesheetController extends Controller
{
    /** The longest range one page or export covers. */
    private const MAX_DAYS = 93;

    public function index(Request $request, Domain $domain)
    {
        $filters = $this->filters($request, $domain);
        $tz = $this->timezone($domain);
        $entries = $this->entries($domain, $filters)->get();

        $people = $entries->groupBy('user_id')->map(function ($rows) use ($tz) {
            $days = $rows->groupBy(fn (TimeEntry $e) => $e->clock_in_at->copy()->timezone($tz)->toDateString())
                ->map(fn ($dayRows, $date) => [
                    'date' => $date,
                    'minutes' => $dayRows->sum(fn (TimeEntry $e) => $e->minutes()),
                    'entries' => $dayRows->sortBy('clock_in_at')->map(fn (TimeEntry $e) => $this->present($e, $tz))->values(),
                ])
                ->sortKeys()->values();

            return [
                'user_id' => $rows->first()->user_id,
                'name' => $rows->first()->user?->name ?? 'Removed user',
                'minutes' => $days->sum('minutes'),
                'days' => $days,
            ];
        })->sortBy(fn ($p) => mb_strtolower($p['name']))->values();

        // Whoever is still on the clock or left without clocking out, whatever dates are shown.
        $open = TimeEntry::query()->forDomain($domain->name_slug)->open()
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->with(['user:id,name', 'location:id,name'])->orderBy('clock_in_at')->get();

        $restricted = FinanceAccess::restrictedLocationId($request->user());

        return Inertia::render('Timesheets/Index', [
            'filters' => [
                'from' => $filters['from']->toDateString(),
                'to' => $filters['to']->toDateString(),
                'location_id' => $filters['location_id'],
            ],
            'people' => $people,
            'totalMinutes' => $people->sum('minutes'),
            'onTheClock' => $open->reject(fn (TimeEntry $e) => $e->isForgotten())->map(fn (TimeEntry $e) => $this->present($e, $tz))->values(),
            'forgotten' => $open->filter(fn (TimeEntry $e) => $e->isForgotten())->map(fn (TimeEntry $e) => $this->present($e, $tz))->values(),
            'locations' => InventoryLocation::query()->forDomain($domain->name_slug)->active()
                ->when($restricted, fn ($q, $id) => $q->whereKey($id))
                ->orderBy('name')->get(['id', 'name', 'type']),
            'canPickAllStores' => $restricted === null,
            'staff' => User::query()->where('domain', $domain->name_slug)->orderBy('name')->get(['id', 'name']),
            'timezone' => $tz,
            'staleAfterHours' => TimeEntry::STALE_AFTER_HOURS,
        ]);
    }

    public function export(Request $request, Domain $domain): StreamedResponse
    {
        $filters = $this->filters($request, $domain);
        $tz = $this->timezone($domain);
        $query = $this->entries($domain, $filters);

        $filename = sprintf(
            'timesheets-%s-%s-%s.csv',
            preg_replace('/[^a-zA-Z0-9_-]+/', '-', $domain->name_slug),
            $filters['from']->toDateString(),
            $filters['to']->toDateString(),
        );

        return response()->streamDownload(function () use ($query, $tz) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['person', 'date', 'clock_in', 'clock_out', 'hours', 'store', 'status', 'notes']);

            foreach ($query->lazy(500) as $entry) {
                $in = $entry->clock_in_at->copy()->timezone($tz);
                fputcsv($out, [
                    $entry->user?->name,
                    $in->toDateString(),
                    $in->format('H:i'),
                    $entry->clock_out_at?->copy()->timezone($tz)->format('H:i'),
                    $entry->clock_out_at ? number_format($entry->minutes() / 60, 2, '.', '') : '',
                    $entry->location?->name,
                    $entry->clock_out_at ? ($entry->edited_by ? 'edited' : '') : ($entry->isForgotten() ? 'forgot to clock out' : 'on the clock'),
                    $entry->notes,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A shift a manager types in: someone forgot to clock in, or the till was down. */
    public function store(Request $request, Domain $domain): RedirectResponse
    {
        $slug = $domain->name_slug;
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('domain', $slug)],
            'location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')->where('domain', $slug)],
            'clock_in_at' => ['required', 'date_format:Y-m-d H:i'],
            'clock_out_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $this->assertOwnStore($request, (int) $data['location_id']);

        [$in, $out] = $this->times($domain, $data);
        $this->assertNoOverlap($slug, (int) $data['user_id'], $in, $out, null);

        DB::transaction(function () use ($request, $slug, $data, $in, $out) {
            $entry = TimeEntry::query()->create([
                'domain' => $slug,
                'location_id' => $data['location_id'],
                'user_id' => $data['user_id'],
                'clock_in_at' => $in,
                'clock_out_at' => $out,
                'notes' => $data['notes'] ?? null,
                'edited_by' => $request->user()->id,
                'edited_at' => now(),
            ]);
            $entry->edits()->create([
                'edited_by' => $request->user()->id,
                'reason' => $data['reason'],
                'before' => null,
                'after' => $this->snapshot($entry),
            ]);
        });

        return back()->with('success', 'Shift added to the timesheet.');
    }

    /** Fix the times of a shift (most often a forgotten clock-out). */
    public function update(Request $request, Domain $domain, TimeEntry $timeEntry): RedirectResponse
    {
        abort_unless($timeEntry->domain === $domain->name_slug, 404);
        $this->assertOwnStore($request, (int) $timeEntry->location_id);

        $data = $request->validate([
            'clock_in_at' => ['required', 'date_format:Y-m-d H:i'],
            'clock_out_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        [$in, $out] = $this->times($domain, $data);
        $this->assertNoOverlap($domain->name_slug, (int) $timeEntry->user_id, $in, $out, $timeEntry->id);

        DB::transaction(function () use ($request, $timeEntry, $data, $in, $out) {
            $before = $this->snapshot($timeEntry);
            $timeEntry->update([
                'clock_in_at' => $in,
                'clock_out_at' => $out,
                'notes' => $data['notes'] ?? $timeEntry->notes,
                'edited_by' => $request->user()->id,
                'edited_at' => now(),
            ]);
            $timeEntry->edits()->create([
                'edited_by' => $request->user()->id,
                'reason' => $data['reason'],
                'before' => $before,
                'after' => $this->snapshot($timeEntry->fresh()),
            ]);
        });

        return back()->with('success', 'Timesheet updated.');
    }

    /** @return array{from: Carbon, to: Carbon, location_id: ?int} Days in the organization's time zone. */
    private function filters(Request $request, Domain $domain): array
    {
        $tz = $this->timezone($domain);
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')->where('domain', $domain->name_slug)],
        ]);

        $today = now()->timezone($tz);
        $from = isset($validated['from']) ? Carbon::parse($validated['from'], $tz) : $today->copy()->startOfWeek();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'], $tz) : $today->copy()->endOfWeek();
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => ['The end date is before the start date.']]);
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => ['Pick a range of '.self::MAX_DAYS.' days or fewer.']]);
        }

        // A manager sees their own store only, whatever the address says.
        $locationId = FinanceAccess::restrictedLocationId($request->user()) ?? ($validated['location_id'] ?? null);

        return ['from' => $from, 'to' => $to, 'location_id' => $locationId ? (int) $locationId : null];
    }

    private function entries(Domain $domain, array $filters)
    {
        return TimeEntry::query()->forDomain($domain->name_slug)
            ->whereBetween('clock_in_at', [$filters['from']->copy()->utc(), $filters['to']->copy()->utc()])
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->with(['user:id,name', 'location:id,name'])
            ->orderBy('clock_in_at');
    }

    private function present(TimeEntry $entry, string $tz): array
    {
        $in = $entry->clock_in_at->copy()->timezone($tz);
        $out = $entry->clock_out_at?->copy()->timezone($tz);

        return [
            'id' => $entry->id,
            'user_id' => $entry->user_id,
            'user' => $entry->user?->name,
            'location_id' => $entry->location_id,
            'location' => $entry->location?->name,
            'clock_in_at' => $in->format('Y-m-d H:i'),
            'clock_out_at' => $out?->format('Y-m-d H:i'),
            'minutes' => $entry->minutes(),
            'open' => $entry->clock_out_at === null,
            'forgotten' => $entry->isForgotten(),
            'edited' => $entry->edited_by !== null,
            'notes' => $entry->notes,
        ];
    }

    /** @return array{0: Carbon, 1: ?Carbon} The typed times as UTC moments, checked. */
    private function times(Domain $domain, array $data): array
    {
        $tz = $this->timezone($domain);
        $in = Carbon::createFromFormat('Y-m-d H:i', $data['clock_in_at'], $tz)->utc();
        $out = ! empty($data['clock_out_at']) ? Carbon::createFromFormat('Y-m-d H:i', $data['clock_out_at'], $tz)->utc() : null;

        if ($in->gt(now()->addMinute())) {
            throw ValidationException::withMessages(['clock_in_at' => ['A shift cannot start in the future.']]);
        }
        if ($out) {
            if ($out->lte($in)) {
                throw ValidationException::withMessages(['clock_out_at' => ['Clock out must be after clock in.']]);
            }
            if ($out->gt(now()->addMinute())) {
                throw ValidationException::withMessages(['clock_out_at' => ['A shift cannot end in the future.']]);
            }
            if ($in->diffInHours($out) > TimeEntry::MAX_HOURS) {
                throw ValidationException::withMessages(['clock_out_at' => ['A shift is at most '.TimeEntry::MAX_HOURS.' hours. Split it into two days.']]);
            }
        }

        return [$in, $out];
    }

    private function assertNoOverlap(string $slug, int $userId, Carbon $in, ?Carbon $out, ?int $exceptId): void
    {
        $end = $out ?? now();

        $clash = TimeEntry::query()->forDomain($slug)->where('user_id', $userId)
            ->when($exceptId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->where('clock_in_at', '<', $end)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('clock_out_at')->where('clock_out_at', '>', $in))
                ->orWhere(fn ($q) => $q->onTheClock()))
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages(['clock_in_at' => ['That overlaps another shift of this person.']]);
        }
    }

    /** A manager works within their own store. */
    private function assertOwnStore(Request $request, int $locationId): void
    {
        $restricted = FinanceAccess::restrictedLocationId($request->user());
        if ($restricted !== null && $restricted !== $locationId) {
            throw ValidationException::withMessages(['location_id' => ['You can only change your own store\'s timesheet.']]);
        }
    }

    private function snapshot(TimeEntry $entry): array
    {
        return [
            'clock_in_at' => $entry->clock_in_at->toIso8601String(),
            'clock_out_at' => $entry->clock_out_at?->toIso8601String(),
            'notes' => $entry->notes,
        ];
    }

    private function timezone(Domain $domain): string
    {
        return $domain->timezone ?: 'Asia/Manila';
    }
}
