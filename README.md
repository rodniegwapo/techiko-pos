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

---

## techiko-pos

### Organization licensing (offline / desktop)

The server uses [Laravel Licensing](https://github.com/masterix21/laravel-licensing) to attach **per-organization** licenses to `App\Models\Domain` (seats, expiry, PASETO offline tokens). This system controls **entitlement to offline features and the desktop app**, not user authentication (Spatie roles and web login are unchanged).

**Operations**

- Enable the PHP `gmp` extension in production and development where possible. The package and PASETO stack expect it; without it, some installs use composer with `--ignore-platform-req=ext-gmp` only to satisfy install-time checks, but the runtime may still need GMP.
- After `composer install` and `php artisan migrate`, generate a root key and a signing key (see the package [documentation](https://github.com/masterix21/laravel-licensing)):
  - `php artisan licensing:keys:make-root`
  - `php artisan licensing:keys:issue-signing --kid=signing-key-1`
- Set `LICENSING_KEY_PASSPHRASE` in `.env` (and in deployment secrets) so non-interactive key commands and tests can run. **Never commit private key material.**
- Super users create or manage licenses on the **Domains → Show** screen. Domain users with a usable license can use the **Profile** “offline app” section and call the Sanctum API: `POST /api/licensing/register-device` and `POST /api/licensing/offline-token` (see `routes/api.php`).
- Set `OFFLINE_INSTALLER_URL` in `.env` to a public URL for the built desktop installer; it is read from `config/offline.php` for the profile download link.

**Local: offline installer download (smoke test)**

- Put built installers under `public/installers/` (a committed `local-test.txt` placeholder is there for dev).
- In `.env`, set `OFFLINE_INSTALLER_URL` to an **absolute** URL with the same origin you use in the browser (match `APP_URL`), e.g. `http://techiko-pos.test/installers/local-test.txt` on Laragon or `http://127.0.0.1:8000/installers/local-test.txt` with `php artisan serve`.
- Run `php artisan config:clear` after changing `.env` if config is cached.
- Sign in as a user whose organization has a **usable** offline license (seats) so the Profile “offline app” section shows the download button, then confirm the link opens in a new tab and returns 200.
- In production, point `OFFLINE_INSTALLER_URL` at your real `.exe` (or CDN URL); you can ignore or replace the placeholder file.

**Desktop client (optional next step)**

Verify issued PASETO tokens inside the [NativePHP](https://nativephp.com) / Electron app using the **public** material exported for clients (per the package docs, e.g. `php artisan licensing:keys:export` where applicable) or the companion patterns from the [laravel-licensing](https://github.com/masterix21/laravel-licensing) project. A separate npm package name may differ by registry; follow the package README for client-side verification of offline tokens.

**Tests**

- PHPUnit sets `LICENSING_KEY_PASSPHRASE` in `phpunit.xml`. Tests that issue offline tokens also run `licensing:keys:make-root` and `licensing:keys:issue-signing` for an isolated key chain.

### Running the desktop app (NativePHP)

This project uses [NativePHP](https://nativephp.com) with an Electron shell under `nativephp/electron/`. If NativePHP is already set up in this repo, use the following to run the desktop app in development.

**Prerequisites**

- PHP and Composer, Node.js, and a configured `.env` (see `.env.example`).
- Optional: desktop-related env keys and `config/nativephp.php` (app id, version, etc.).

**Install project dependencies (from the project root)**

```bash
composer install
npm install
```

For **packaging** a Windows/macOS/Linux installer, also install the Electron app dependencies (Node 22+ is required per `nativephp/electron/package.json`):

```bash
cd nativephp/electron
npm install
cd ../..
```

**Run in development (recommended)**

Starts the NativePHP runner and Vite together:

```bash
composer run native:dev
```

For guests, `/` in the Electron shell redirects to the login page; in a normal browser it shows the marketing homepage.

**Run with two terminals**

- Terminal 1: `php artisan native:run`
- Terminal 2: `npm run dev`

**Build a distributable**

From `nativephp/electron` (after `npm install` there):

| Platform   | Command              |
| ---------- | -------------------- |
| Windows x64 | `npm run build:win-x64` |
| macOS arm64 | `npm run build:mac-arm64` |
| macOS x64  | `npm run build:mac-x64` |
| Linux x64  | `npm run build:linux-x64` |

**Output directory:** `php artisan native:build` writes under `nativephp/electron/dist` (NativePHP sets `NATIVEPHP_BUILDING`). A plain **`npm run build:win-x64`** (without that env) uses **`nativephp/electron/release`** (installers and `win-unpacked` live there), not `out/`—**electron-vite** already compiles the main process to **`out/main/index.js`**. That avoids a locked `dist\win-unpacked` and avoids wiping the Vite `out/` tree. **`APP_PATH`** defaults to the Laravel project root. See [`nativephp/electron/electron-builder.mjs`](nativephp/electron/electron-builder.mjs) and [electron-builder](https://www.electron.build/) for details.

You can also run `npm run build:win-x64` (or `build:mac-*` / `build:linux-*`) from the **repository root**; it delegates to `nativephp/electron` (dependencies must be installed there first).

**Production-like builds** should use `php artisan native:build win` (or `all`, etc.): that sets `NATIVEPHP_BUILD_PATH`, `NATIVEPHP_BUILDING`, and other env vars and copies the Laravel app into the build. Running only `npm run build:win-x64` is supported for local iteration: `NATIVEPHP_BUILD_PATH` and `php.js` default to `vendor/nativephp/desktop/resources/build` (same as NativePHP’s `native:build`), and [`electron-builder.mjs`](nativephp/electron/electron-builder.mjs) no longer packages the whole Electron folder (which used to create nested `dist/.../dist/...` trees). If you need a clean `dist/` on Windows, close File Explorer under `nativephp/electron/dist`, stop any running packaged app, then run `cd nativephp/electron && npm run clean:dist` (retries on file locks) or delete `dist` manually—automated `rimraf` during the build was removed because DLLs such as `dxcompiler.dll` are often locked (EPERM). If **`NATIVEPHP_BUILD_PATH` is set to `nativephp/electron` or a path inside it** (e.g. by mistake), the resolver falls back to `vendor/nativephp/desktop/resources/build` and logs a warning; do not point that variable at the Electron app root.

**Further reading:** [NativePHP documentation](https://nativephp.com/docs) for `native:run`, building, and deployment.

**Troubleshooting `composer run native:dev` (Windows)**

- If you see `Command failed: 'npm run dev' (exit code 1)` from the NativePHP side, the Electron package under `nativephp/electron` must run: `php.js` (PHP binary unzip), a built TypeScript `electron-plugin` (`#plugin` import), and a `MAIN_VITE_NATIVEPHP_BUILD_PATH` that matches the unzip path. This repo syncs those defaults in [`nativephp/electron/php.js`](nativephp/electron/php.js) and [`nativephp/electron/electron.vite.config.mjs`](nativephp/electron/electron.vite.config.mjs). Run `cd nativephp/electron && npm run plugin:build` if you see unresolved `#plugin` (the `dev` script runs `predev` to build the plugin when possible).
- The embedded runtime uses the PHP zip from `vendor/nativephp/php-bin` (8.3/8.4 on Windows). If your system PHP is older (e.g. 8.2) than the shipped zips, the app picks the newest available pack and may log a short warning; matching PHP 8.3+ in Laragon is ideal.
- **Windows `winCodeSign` / symbolic link / 7-Zip errors:** Unsigned local builds set `signtoolOptions.sign: null` and `signAndEditExecutable: false` in [`nativephp/electron/electron-builder.mjs`](nativephp/electron/electron-builder.mjs) so the packager does not need the `winCodeSign` cache (extracting that archive on Windows can require symlink privileges). For **production signing**, set the Azure Trusted Signing env vars as in NativePHP’s docs. If a broken cache remains, remove `%LOCALAPPDATA%\electron-builder\Cache\winCodeSign` and rebuild.
