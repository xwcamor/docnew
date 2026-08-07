import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
        tailwindcss(),
    ],
    resolve: {
        alias: {
            // La carpeta real es `resources/js/Utils` (U mayúscula) pero 28
            // archivos la importan como `@/utils/...`. En Windows da igual; en
            // Linux (el droplet) Vite corta el build con "Could not load
            // .../resources/js/utils/severity". Este alias va ANTES que '@'
            // para que gane el prefijo más largo y las dos grafías resuelvan al
            // mismo archivo, sin tocar los 28 imports.
            '@/utils': path.resolve(__dirname, 'resources/js/Utils'),
            '@': path.resolve(__dirname, 'resources/js'),
            'ziggy-js': path.resolve(__dirname, 'vendor/tightenco/ziggy'),
        },
    },
});
