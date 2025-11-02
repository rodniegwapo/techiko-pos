<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\Domain;
use App\Models\InventoryLocation;
use Illuminate\Support\Facades\DB;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'organization' => 'nullable|string|max:255',
            'country_code' => 'nullable|string|size:2',
            'timezone' => 'nullable|string|max:64',
        ]);

        DB::beginTransaction();

        try {
            // 1) Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'status' => 'inactive',
                'role_level' => 2, // Admin level by default for registrants
                'can_switch_locations' => true,
            ]);

            // 2) Create an organization (domain) in pending state
            $domainName = $request->input('organization') ?: (explode(' ', trim($request->name))[0] . " Organization");

            $domain = Domain::create([
                'name' => $domainName,
                'timezone' => $request->input('timezone') ?: 'Asia/Manila',
                'country_code' => strtoupper($request->input('country_code') ?: 'PH'),
                'is_active' => false, // Pending approval
            ]);

            // 3) Associate user with the new domain
            $user->update(['domain' => $domain->name_slug]);

            // 4) Assign Admin role via Spatie
            try {
                $user->assignRole('admin');
            } catch (\Throwable $e) {
                // optional: log missing role but don't fail registration
                // Log::warning('Role assignment failed: ' . $e->getMessage());
            }

            // 5) Create a default inventory location for the domain
            // Generate a unique code (max 10 chars, must be unique globally)
            $baseCode = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $domain->name_slug), 0, 5)) . '-MAIN';
            $locationCode = $baseCode;
            $counter = 1;
            
            // Ensure code uniqueness
            while (InventoryLocation::where('code', $locationCode)->exists()) {
                $suffix = str_pad((string)$counter, 2, '0', STR_PAD_LEFT);
                $locationCode = substr($baseCode, 0, 8) . $suffix;
                $counter++;
                
                // Safety check to prevent infinite loop
                if ($counter > 99) {
                    // Fallback to timestamp-based code if too many conflicts
                    $locationCode = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $domain->name_slug), 0, 3)) . '-' . substr(time(), -4);
                    break;
                }
            }

            InventoryLocation::create([
                'name' => $domainName . ' - Main Store',
                'code' => $locationCode,
                'type' => 'store',
                'address' => null,
                'contact_person' => $request->name,
                'phone' => null,
                'email' => $request->email,
                'is_active' => true,
                'is_default' => true,
                'domain' => $domain->name_slug,
                'notes' => 'Default location created during registration',
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->withErrors(['registration' => 'Registration failed. Please try again later.']);
        }

        // 5) Do NOT auto-login; send to a public thank-you page
        return redirect()->route('registration.thankyou');
    }
}
