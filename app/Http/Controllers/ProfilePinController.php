<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\UserPin;
use App\Services\UserPinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Change my PIN: anyone can set or change their own POS PIN, so only they know it. An admin's
 * Set PIN on the Users page stays for new staff and forgotten PINs.
 */
class ProfilePinController extends Controller
{
    public function __construct(private readonly UserPinService $pins) {}

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $domain = $user->domain ? Domain::query()->where('name_slug', $user->domain)->first() : null;
        abort_if(! $domain, 404, 'You are not part of an organization.');

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'pin_code' => ['required', 'string', 'regex:/^\d{4,6}$/', 'confirmed'],
        ], [
            'pin_code.regex' => 'A PIN is 4 to 6 digits.',
        ]);

        if ($this->pins->pinTakenInDomain($domain, $user, $validated['pin_code'])) {
            throw ValidationException::withMessages([
                'pin_code' => [__('This PIN is already used by another user in this organization. Choose a different PIN.')],
            ]);
        }

        UserPin::updateOrCreate(
            ['user_id' => $user->id],
            ['pin_code' => Hash::make($validated['pin_code']), 'active' => true],
        );

        return back()->with('success', 'Your PIN is saved.');
    }
}
