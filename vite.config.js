import tailwindcss from "@tailwindcss/vite";
import laravel from "laravel-vite-plugin";
import { defineConfig, lazyPlugins } from "vite-plus";

export default defineConfig({
    lint: {
        options: {
            denyWarnings: true,
        },
        overrides: [
            {
                files: ["tests/**/*.js", "tests/**/*.mjs"],
                rules: {
                    "no-nested-ternary": "error",
                },
            },
        ],
    },
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                "resources/css/app.css",
                "resources/js/native-validation.js",
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
