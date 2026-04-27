<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // Electron serves Laravel on http://127.0.0.1:<port>. `Window` defaults to url('/'),
        // which uses APP_URL from .env (e.g. http://techiko-pos.test). Loading that host fails in
        // the desktop shell and leaves a chrome-error page; further navigation then throws
        // "Unsafe attempt to load URL … from frame with URL chrome-error://…".
        if (config('nativephp-internal.running') && ! app()->runningInConsole()) {
            $root = rtrim(request()->getSchemeAndHttpHost(), '/');
            if ($root !== '') {
                config(['app.url' => $root]);
                URL::forceRootUrl($root);
            }
        }

        Window::open();
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}
