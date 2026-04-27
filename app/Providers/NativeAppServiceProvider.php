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
