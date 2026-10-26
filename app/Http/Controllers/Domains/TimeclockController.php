<?php

namespace App\Http\Controllers\Domains;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\TimeclockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The Clock in / out button on the POS: the person at the till enters their own PIN. */
class TimeclockController extends Controller
{
    public function __construct(private readonly TimeclockService $timeclock) {}

    public function punch(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'string', 'max:20'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $location = Helpers::getActiveLocation($domain, $validated['location_id'] ?? null);
        if (! $location || $location->domain !== $domain->name_slug) {
            throw ValidationException::withMessages(['location_id' => ['Pick a store first.']]);
        }

        return response()->json($this->timeclock->punch(
            $domain,
            $location,
            $validated['pin'],
            $request->user()->id.'|'.$request->ip(),
        ));
    }
}
