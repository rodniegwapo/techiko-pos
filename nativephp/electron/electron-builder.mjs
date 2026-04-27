import { existsSync } from 'fs';
import { exec } from 'child_process';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { getAppPath, resolveNativephpBuildPath } from './resolveNativephpBuildPath.mjs';

const appPath = getAppPath();
const electronPackageDir = dirname(fileURLToPath(import.meta.url));
const nativephpBuildPath = resolveNativephpBuildPath(process.env.NATIVEPHP_BUILD_PATH);

const appUrl = process.env.APP_URL;
const appId = process.env.NATIVEPHP_APP_ID;
const appName = process.env.NATIVEPHP_APP_NAME;
/** Set by `php artisan native:build` (Laravel). Plain `npm run build:win-*` should not set this. */
const isLaravelNativeBuild = /^(1|true)$/i.test(String(process.env.NATIVEPHP_BUILDING ?? ''));
const appAuthor = process.env.NATIVEPHP_APP_AUTHOR;
const fileName = process.env.NATIVEPHP_APP_FILENAME;
const appVersion = process.env.NATIVEPHP_APP_VERSION;
const appCopyright = process.env.NATIVEPHP_APP_COPYRIGHT;
const deepLinkProtocol = process.env.NATIVEPHP_DEEPLINK_SCHEME;
const updaterEnabled = process.env.NATIVEPHP_UPDATER_ENABLED === 'true';

// Azure signing configuration
const azureEndpoint = process.env.NATIVEPHP_AZURE_ENDPOINT;
const azureCertificateProfileName = process.env.NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME;
const azureCodeSigningAccountName = process.env.NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME;

// Since we do not copy the php executable here, we only need these for building
const isWindows = process.argv.includes('--win');
const isLinux = process.argv.includes('--linux');
const isDarwin = process.argv.includes('--mac');

let targetOs;

if (isWindows) {
    targetOs = 'win';
}

if (isLinux) {
    targetOs = 'linux';
}

if (isDarwin) {
    targetOs = 'mac';
}

let updaterConfig = {};

try {
    updaterConfig = process.env.NATIVEPHP_UPDATER_CONFIG;
    updaterConfig = JSON.parse(updaterConfig);
} catch (e) {
    updaterConfig = {};
}

if (isLaravelNativeBuild) {
    console.log('  • updater config', updaterConfig);
}

export default {
    appId: appId,
    productName: appName,
    copyright: appCopyright,
    directories: {
        buildResources: 'build',
        // Local `npm run build:win-*` (no NATIVEPHP_BUILDING): use `release/` (not `out/`—electron-vite already writes `out/main/index.js` there).
        // `php artisan native:build` sets NATIVEPHP_BUILDING and uses the project `dist/` path.
        output: isLaravelNativeBuild
            ? join(appPath, 'nativephp', 'electron', 'dist')
            : join(electronPackageDir, 'release'),
    },
    files: [
        '!**/.vscode/*',
        '!src/*',
        '!dist/*',
        '!release/*',
        '!electron.vite.config.{js,ts,mjs,cjs}',
        '!{.eslintignore,.eslintrc.cjs,.prettierignore,.prettierrc.yaml,dev-app-update.yml,CHANGELOG.md,README.md}',
        '!{.env,.env.*,.npmrc,pnpm-lock.yaml}',
    ],
    beforePack: async (context) => {
        let arch = {
            1: 'x64',
            3: 'arm64'
        }[context.arch];

        if (arch === undefined) {
            console.error('Cannot build PHP for unsupported architecture');
            process.exit(1);
        }

        console.log(`  • building php binary - exec php.js --${targetOs} --${arch}`);
        exec(`node php.js --${targetOs} --${arch}`);
    },
    afterSign: 'build/notarize.js',
    win: {
        executableName: fileName,
        ...(azureEndpoint && azureCertificateProfileName && azureCodeSigningAccountName
            ? {
                azureSignOptions: {
                    endpoint: azureEndpoint,
                    certificateProfileName: azureCertificateProfileName,
                    codeSigningAccountName: azureCodeSigningAccountName,
                },
            }
            : {
                // Without a cert / Azure, skip signtool and the winCodeSign 7z cache. That archive extracts
                // macOS symlinks and fails on Windows without "Developer Mode" or an elevated shell.
                signAndEditExecutable: false,
                verifyUpdateCodeSignature: false,
                signtoolOptions: { sign: null },
            }
        ),
    },
    nsis: {
        artifactName: appName + '-${version}-setup.${ext}',
        shortcutName: '${productName}',
        uninstallDisplayName: '${productName}',
        createDesktopShortcut: 'always',
    },
    // electron-builder requires schemes to be non-empty strings; omit when NATIVEPHP_DEEPLINK_SCHEME is unset
    ...(deepLinkProtocol
        ? {
            protocols: {
                name: deepLinkProtocol,
                schemes: [deepLinkProtocol],
            },
        }
        : {}),
    mac: {
        entitlementsInherit: 'build/entitlements.mac.plist',
        artifactName: appName + '-${version}-${arch}.${ext}',
        extendInfo: {
            NSCameraUsageDescription:
                "Application requests access to the device's camera.",
            NSMicrophoneUsageDescription:
                "Application requests access to the device's microphone.",
            NSDocumentsFolderUsageDescription:
                "Application requests access to the user's Documents folder.",
            NSDownloadsFolderUsageDescription:
                "Application requests access to the user's Downloads folder.",
        },
    },
    dmg: {
        artifactName: appName + '-${version}-${arch}.${ext}',
    },
    linux: {
        target: ['AppImage', 'deb'],
        maintainer: appUrl,
        category: 'Utility',
    },
    appImage: {
        artifactName: appName + '-${version}.${ext}',
    },
    npmRebuild: false,
    extraMetadata: {
        name: fileName,
        homepage: appUrl,
        version: appVersion,
        author: appAuthor,
    },
    extraResources: [
        {
            from: nativephpBuildPath,
            to: 'build',
            filter: [
                '**/*',
                '!{.git}',
                '!**/.git/**',
                '!**/node_modules/**',
                '!**/dist/**',
                '!**/win-unpacked/**',
                '!**/mac/**',
            ],
        },
    ],
    ...(() => {
        const extrasPath = join(appPath, 'extras');
        if (! existsSync(extrasPath)) {
            return {};
        }
        return {
            extraFiles: [
                {
                    from: extrasPath,
                    to: 'extras',
                    filter: ['**/*'],
                },
            ],
        };
    })(),
    ...updaterEnabled 
        ? { publish: updaterConfig } 
        : {}
};
