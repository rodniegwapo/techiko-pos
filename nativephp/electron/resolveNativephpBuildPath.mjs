import { dirname, join, resolve, sep } from 'path';
import { fileURLToPath } from 'url';

export function getAppPath() {
    return process.env.APP_PATH
        ? resolve(process.env.APP_PATH)
        : resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
}

/**
 * Same path NativePHP's `php artisan native:build` uses (ElectronServiceProvider::buildPath).
 * Never use `nativephp/electron` (or a subpath like `dist`) — that packs node_modules and creates nested dist/.../dist/... in the NSIS output.
 */
export function resolveNativephpBuildPath(maybe) {
    const appPath = getAppPath();
    const vendorBuildPath = join(
        appPath,
        'vendor',
        'nativephp',
        'desktop',
        'resources',
        'build',
    );
    const electronProjectPath = resolve(appPath, 'nativephp', 'electron');

    let p =
        maybe != null && String(maybe).trim() !== '' ? resolve(String(maybe)) : resolve(vendorBuildPath);

    if (p === electronProjectPath || p.startsWith(electronProjectPath + sep)) {
        console.warn(
            `[NativePHP] NATIVEPHP_BUILD_PATH must not be the Electron project folder (got: ${p}). ` +
                `Using vendor path instead: ${vendorBuildPath}`,
        );
        p = resolve(vendorBuildPath);
    }

    return p;
}
