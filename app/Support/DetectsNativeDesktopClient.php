<?php

namespace App\Support;

use Illuminate\Http\Request;

class DetectsNativeDesktopClient
{
    public static function matches(Request $request): bool
    {
        if (config('app.force_native_desktop_client')) {
            return true;
        }

        $needle = (string) config('app.native_desktop_ua_match', 'Electron');
        if ($needle === '') {
            return false;
        }

        return str_contains((string) $request->userAgent(), $needle);
    }
}
