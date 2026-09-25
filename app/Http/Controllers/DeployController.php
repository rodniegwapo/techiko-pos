<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Hostinger's Git auto-deploy runs composer install in a build step that has
 * no network access to the site's own MySQL, so migrate/cache can't run as
 * composer scripts. This lets them be triggered over HTTP against the real
 * web server instead, once code is published.
 */
class DeployController extends Controller
{
    public function run(Request $request, string $token)
    {
        if (! hash_equals((string) config('app.deploy_token'), $token)) {
            abort(404);
        }

        $output = '';
        foreach (['migrate --force', 'storage:link --force', 'config:cache', 'route:cache', 'view:cache'] as $command) {
            $output .= "\$ artisan {$command}\n";
            Artisan::call($command);
            $output .= Artisan::output()."\n";
        }

        return response($output, 200, ['Content-Type' => 'text/plain']);
    }
}
