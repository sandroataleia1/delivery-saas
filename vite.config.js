import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Figtree', {
                    weights: [400, 500, 600, 700],
                }),
            ],
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
    ],
    resolve: { alias: { '@': new URL('./resources/js', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1') } },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
