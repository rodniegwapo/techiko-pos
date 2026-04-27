import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { defineConfig, externalizeDepsPlugin } from 'electron-vite';

// Laravel app root: nativephp/electron -> ../..
const appPath =
    process.env.APP_PATH || join(dirname(fileURLToPath(import.meta.url)), '..', '..');

export default defineConfig({
    main: {
        build: {
            rollupOptions: {
                plugins: [
                    {
                        name: 'watch-external',
                        buildStart() {
                            this.addWatchFile(
                                join(appPath, 'app', 'Providers', 'NativeAppServiceProvider.php')
                            );
                        }
                    }
                ]
            },
        },
        plugins: [externalizeDepsPlugin()]
    }
});
