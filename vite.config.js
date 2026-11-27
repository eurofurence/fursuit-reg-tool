import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    assetsInclude: ['**/*.TTF'],
    server: {
        watch: {
            ignored: [
                '**/.direnv/**',
                '.devenv.*',
                'flake.*'
            ]
        }
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
            detectTls: false,
            assets: ['resources/assets/**'],
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
