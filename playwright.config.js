import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
    testDir: "./tests/Browser",
    outputDir: "./test-results",
    forbidOnly: Boolean(process.env.CI),
    retries: 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: [["list"], ["html", { open: "never", outputFolder: "playwright-report" }]],
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "http://fidelitopass-laravel-app",
        screenshot: "only-on-failure",
        trace: "retain-on-failure",
        video: "retain-on-failure",
    },
    projects: [
        {
            name: "chromium",
            use: { ...devices["Desktop Chrome"] },
        },
    ],
});
