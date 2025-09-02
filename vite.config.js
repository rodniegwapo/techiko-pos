import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
    resolve: {
        alias: {
            '@assets': path.resolve(__dirname, './resources/assets'),
            '@': path.resolve(__dirname, './resources/js')
        }
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    build: {
        emptyOutDir: false
    },
    css: {
        preprocessorOptions: {
          less: {
            javascriptEnabled: true,
            modifyVars: {
              'font-family': 'Inter var, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
            }
          }
        }
      }
      
});
