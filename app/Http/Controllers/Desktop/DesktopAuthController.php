<?php

namespace App\Http\Controllers\Desktop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Desktop\DesktopLoginRequest;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class DesktopAuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Desktop/DesktopLogin', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    public function store(DesktopLoginRequest $request): RedirectResponse|JsonResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = auth()->user();

        $redirectResponse = $user->domain
            ? redirect()->route('domains.dashboard', ['domain' => $user->domain])
            : redirect()->intended(RouteServiceProvider::HOME);

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => $redirectResponse->getTargetUrl(),
            ]);
        }

        return $redirectResponse;
    }
}
