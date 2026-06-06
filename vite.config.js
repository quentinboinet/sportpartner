import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import symfonyPlugin from 'vite-plugin-symfony'
import { resolve } from 'path'

export default defineConfig({
    plugins: [
        vue(),
        symfonyPlugin(),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'assets/js'),
        },
    },
    build: {
        rollupOptions: {
            input: {
                app: './assets/js/app.js',
                css: './assets/css/app.css',
            },
        },
    },
})
