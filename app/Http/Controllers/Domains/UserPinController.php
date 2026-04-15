<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\User;
use App\Models\UserPin;
use App\Services\UserPinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserPinController extends Controller
{
    public function __construct(
        private UserPinService $userPinService
    ) {}

    /**
     * Create or update the POS PIN for a user in this domain.
     */
    public function update(Request $request, Domain $domain, User $user)
    {
        if ($user->domain !== $domain->name_slug) {
            abort(403, 'User does not belong to this domain');
        }

        $this->authorize('managePin', $user);

        $validated = $request->validate([
            'pin_code' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'pin_code_confirmation' => ['required', 'string', 'same:pin_code'],
        ]);

        if ($this->userPinService->pinTakenInDomain($domain, $user, $validated['pin_code'])) {
            throw ValidationException::withMessages([
                'pin_code' => [__('This PIN is already used by another user in this organization. Choose a different PIN.')],
            ]);
        }

        UserPin::updateOrCreate(
            ['user_id' => $user->id],
            [
                'pin_code' => Hash::make($validated['pin_code']),
                'active' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'PIN saved successfully.',
        ]);
    }
}
