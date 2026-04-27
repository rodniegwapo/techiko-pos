<?php

namespace App\Http\Controllers;

use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NativeDesktopHomeController extends Controller
{
    /**
     * NativePHP desktop: `/` is the app entry; send guests to login, authenticated users to the app home.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect(RouteServiceProvider::HOME);
        }

        return redirect()->route('login');
    }
}
