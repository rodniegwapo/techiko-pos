<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
# techiko-pos

## Requirements

- **PHP 8.3+** with extensions: **openssl**, **sodium**, **zip**, and **gmp** (required by [laravel-licensing](https://github.com/masterix21/laravel-licensing) / PASETO; enable `extension=gmp` in `php.ini`).
- **Node.js 22+** for [NativePHP Desktop](https://nativephp.com/docs/desktop/2/getting-started/installation) builds and dev tooling.

## Offline SQLite (runtime DB switch)

When `RUNTIME_DB_SWITCH_ENABLED=true`, middleware probes `ONLINE_HEALTHCHECK_URL`, or **`{APP_URL}/health`** when that is blank, caches the outcome, and switches Laravel’s **default** DB connection between `mysql` (`RUNTIME_DB_ONLINE_CONNECTION`) and `offline_sqlite` (`config/database.php`). See **`config/runtime_database.php`** and **`.env.example`**.

**Session driver:** Prefer **`SESSION_DRIVER=file`** while the default DB can flip. If you stay on **`SESSION_DRIVER=database`**, pin **`SESSION_CONNECTION`** to a **fixed** connection that never switches, or session lookups can silently miss rows after a mode flip. The app also **auto-pins** `session.connection` to **`RUNTIME_DB_ONLINE_CONNECTION`** when the runtime switch is enabled, the session driver is `database`, and **`SESSION_CONNECTION`** is unset (fully-offline installs with no MySQL should still use **file** sessions).

### 419 Page Expired on login (CSRF)

Laravel **419** after `POST /login` usually means the **CSRF token** no longer matches the **session** (cookie).

1. **Same host as `APP_URL`:** Open the app at the same origin Ziggy/Inertia uses. If you browse `http://techiko-pos.test` but **`.env`** has **`APP_URL=http://localhost`**, the POST may go to `localhost` while the session cookie was set for `techiko-pos.test` → **419**. Set **`APP_URL`** to the URL you actually use, then run **`php artisan config:clear`**.
2. **`SESSION_SECURE_COOKIE`:** On plain **`http://`**, use **`false`** (or use HTTPS everywhere).
3. **`SESSION_DOMAIN`:** Leave empty for typical local dev unless you know the correct domain pattern.
4. **Runtime DB switch + `SESSION_DRIVER=database`:** Without a stable session connection, mode flips break sessions—use **`SESSION_DRIVER=file`** or set **`SESSION_CONNECTION`** (see [`.env.example`](.env.example) and above).

**Offline lifecycle:** Migrate the local file explicitly: `php artisan migrate --database=offline_sqlite` (seed optional users if you require offline username/password parity). Ensuring **`storage/app/offline/`** stays writable matters for packaged NativePHP builds. Syncing catalogue or sales history between MySQL and SQLite is a separate undertaking.

When the switch is enabled, Inertia receives **`db_mode`** (`online` | `offline`) for UI messaging (`HandleInertiaRequests`).

Development helpers (`RUNTIME_DB_FORCE_ONLINE` / `RUNTIME_DB_FORCE_OFFLINE`) apply only when `APP_DEBUG`/local **`or`** `RUNTIME_DB_ALLOW_FORCE_FLAGS=true` (README / `.env.example`).

## Laravel licensing (server + client)

This app can act as the licensing authority using [masterix21/laravel-licensing](https://github.com/masterix21/laravel-licensing). Optional offline validation uses [laravel-licensing-client](https://github.com/masterix21/laravel-licensing-client).

1. Copy variables from `.env.example` (`LICENSING_KEY_PASSPHRASE`, `LICENSING_SERVER_URL`, `LICENSING_ENFORCE_DOMAINS`).
2. Generate key material (development example; protect production keys outside the repo):

   ```bash
   php artisan licensing:keys:make-root
   php artisan licensing:keys:issue-signing --kid=your-key-id
   ```

3. **Per-domain enforcement** is off by default. Set `LICENSING_ENFORCE_DOMAINS=true` and attach the `license.domain` middleware to routes that receive a `{domain}` route parameter when you want unlicensed organizations blocked (see `App\Http\Middleware\EnsureDomainLicenseValid`).

4. Licenses attach to the `Domain` model (`licenses()` morph); use `App\Services\Licensing\OrganizationLicensingService` to issue keys for an organization.

## NativePHP Desktop

```bash
composer install
php artisan native:run
php artisan native:build
```

When the embedded app runs, `NATIVEPHP_RUNNING` is set and the app root URL is aligned to the local server from the request (see `App\Providers\NativeAppServiceProvider`). Public marketing pages (`/`, `/about`, and related routes) are **not** registered in the desktop build; `GET /` redirects guests to **`/desktop/login`** and signed-in users to the **global** dashboard route (`/dashboard`) for routing purposes (`App\Http\Controllers\NativeDesktopHomeController`). The browser build keeps full marketing routes as before.

**Desktop sign-in** uses dedicated `web` routes in `routes/desktop.php` (`App\Http\Controllers\Desktop\DesktopAuthController`) and the Inertia page `resources/js/Pages/Desktop/DesktopLogin.vue`. The standard **web** login at `/login` remains for the browser. **`/desktop/*` routes return 404 unless** NativePHP is running (`config('nativephp-internal.running')`), PHPUnit boots with **`TEST_NATIVE_DESKTOP_ROUTES=1`**, or **`APP_ENV=local`** (typical Laragon / local browser testing). Super users are blocked from the desktop sign-in when running under NativePHP; use the **web** app for administrator access. Organization users are redirected to the **domain dashboard** after `POST /desktop/login`, and the global `/dashboard` URL redirects to `/domains/{slug}/dashboard` on desktop. `App\Http\Middleware\RedirectNativeGlobalDashboardToDomain` enforces this for the global dashboard route.

**Session + CSRF (bundled desktop):** The desktop login page sends Laravel’s session CSRF token on **`POST /desktop/login`** using the **`X-CSRF-TOKEN`** header (`csrf_token()` shared via Inertia props and mirrored in `app.blade.php`’s `<meta name="csrf-token">`). Avoid relying on **`GET /sanctum/csrf-cookie`** here; Sanctum stateful domains must match your browser host when using that endpoint (common mismatch when `APP_URL` uses `localhost` but you browse `*.test`).

**API and Sanctum:** **`POST /desktop/login`** establishes a normal **web session**. Existing **`api/*`** routes that use `auth:sanctum` with `EnsureFrontendRequestsAreStateful` continue to authenticate from that session for first-party same-origin requests. Thin-client scaffolding: **`POST /api/desktop/login`** (JSON) validates the same eligibility rules (`App\Support\Desktop\DesktopPostLoginValidator`) and returns a **Bearer** Sanctum personal access token; it does **not** keep the browser session authenticated.

For production-packaged desktops, **`APP_URL`** is the canonical public site URL for links/email; **`LICENSING_SERVER_URL`** (see `config/licensing-client.php`) should point at your licensing API base—often the same HTTPS host as production when validating online—and is **not** a MySQL connection (the app continues to use `DB_*` on the server only).

To inspect the desktop route table locally: `NATIVEPHP_RUNNING=1 php artisan route:list` (Windows: `set NATIVEPHP_RUNNING=1` then `php artisan route:list`).

Do not commit `out/`, `release/`, or `*.asar` build outputs (see `.gitignore`).
