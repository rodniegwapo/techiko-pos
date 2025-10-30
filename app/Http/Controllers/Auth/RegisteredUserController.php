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

        // 1) Create user
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'status' => 'inactive'
        ]);

        // 2) Create an organization (domain) in pending state
        $domainName = $request->input('organization') ?: (explode(' ', trim($request->name))[0] . " Organization");
        $domain = Domain::create([
            'name' => $domainName,
            'timezone' => $request->input('timezone') ?: 'Asia/Manila',
            'country_code' => strtoupper($request->input('country_code') ?: 'PH'),
            'is_active' => false, // Pending approval
        ]);

        $user->update(['domain' => $domain->name_slug]);

        // 4) Do NOT auto-login; send to a public thank-you page
        return redirect()->route('registration.thankyou');
    }
}
