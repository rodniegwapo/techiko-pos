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

**Desktop sign-in** uses dedicated `web` routes in `routes/desktop.php` (`App\Http\Controllers\Desktop\DesktopAuthController`) and the Inertia page `resources/js/Pages/Desktop/DesktopLogin.vue`. The standard **web** login at `/login` remains for the browser. Super users are blocked from the desktop sign-in when running under NativePHP; use the **web** app for administrator access. Organization users are redirected to the **domain dashboard** after `POST /desktop/login`, and the global `/dashboard` URL redirects to `/domains/{slug}/dashboard` on desktop. `App\Http\Middleware\RedirectNativeGlobalDashboardToDomain` enforces this for the global dashboard route.

**API and Sanctum:** `POST /desktop/login` establishes a normal **web session** (same as Breeze). Existing `api/*` routes that use `auth:sanctum` with `EnsureFrontendRequestsAreStateful` continue to authenticate from that session for first-party (same-origin) clients. For raw HTTP clients, call `GET /sanctum/csrf-cookie` first, then post credentials with credentials and the CSRF header, as in the Laravel + Sanctum SPA documentation.

To inspect the desktop route table locally: `NATIVEPHP_RUNNING=1 php artisan route:list` (Windows: `set NATIVEPHP_RUNNING=1` then `php artisan route:list`).

Do not commit `out/`, `release/`, or `*.asar` build outputs (see `.gitignore`).
