import tailwindcss from "@tailwindcss/vite";
import laravel from "laravel-vite-plugin";
import { defineConfig, lazyPlugins } from "vite-plus";

export default defineConfig({
    lint: {
        options: {
            denyWarnings: true,
        },
    },
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                "resources/css/app.css",
                /* @chisel-passkeys */
                "resources/js/passkeys.js",
                /* @end-chisel-passkeys */
            ],
            refresh: true,
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                "**/.agents/**",
                "**/.claude/**",
                "**/.cursor/**",
                "**/.junie/**",
                "**/storage/framework/views/**",
                "**/vendor/**",
            ],
        },
    },
});
