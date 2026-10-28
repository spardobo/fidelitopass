import { expect, test } from "@playwright/test";
import { existsSync, readFileSync } from "node:fs";
import { dirname, extname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const presentationStates = [
    "waiting",
    "scheduled",
    "zero",
    "earning",
    "available",
    "redeemed",
    "ended",
    "expired",
    "cancelled",
];
const buildAssetsDirectory = resolve(repositoryRoot, "public/build/assets");

test.use({ javaScriptEnabled: false });

for (const viewportWidth of [390, 1280]) {
    test(`renders personal current-state information at ${viewportWidth}px without a running application`, async ({
        page,
    }) => {
        await page.setViewportSize({ width: viewportWidth, height: 900 });
        let pageHtml = "";
        await page.route("**/*", async (route) => {
            const pathname = new URL(route.request().url()).pathname;
            if (pathname === "/personal-render-fixture") {
                await route.fulfill({ status: 200, contentType: "text/html", body: pageHtml });
                return;
            }
            const assetPath = resolve(
                buildAssetsDirectory,
                pathname.replace(/^\/build\/assets\//, ""),
            );
            if (
                pathname.startsWith("/build/assets/") &&
                assetPath.startsWith(`${buildAssetsDirectory}/`) &&
                existsSync(assetPath)
            ) {
                const contentTypes = {
                    ".css": "text/css",
                    ".woff2": "font/woff2",
                    ".woff": "font/woff",
                    ".png": "image/png",
                    ".svg": "image/svg+xml",
                };
                await route.fulfill({
                    status: 200,
                    contentType: contentTypes[extname(assetPath)] ?? "application/octet-stream",
                    body: readFileSync(assetPath),
                });
                return;
            }
            await route.abort();
        });

        for (const state of presentationStates) {
            const artifactPath = resolve(
                repositoryRoot,
                `test-results/personal-pass/${state}.html`,
            );
            expect(
                existsSync(artifactPath),
                "Run the guarded personal HTTP feature suite first",
            ).toBe(true);
            pageHtml = readFileSync(artifactPath, "utf8");
            await page.goto("http://personal-pass.test/personal-render-fixture");
            await page.evaluate(() => document.fonts.ready);
            const main = page.getByRole("main");
            await expect(main.getByRole("heading", { level: 1 })).toBeVisible();
            await expect(main.getByRole("heading", { level: 2 })).toBeVisible();
            await expect(
                main.locator("form, input, button, [wire\\:poll], [wire\\:click]"),
            ).toHaveCount(0);
            await expect(main).not.toContainText(
                /00123|signature|sincroniz|inscr[ií]b|historial|TU ÚLTIMO CANJE/i,
            );
            const geometry = await main.evaluate((element) => {
                const businessHeading = element.querySelector("h1");
                const icon = element.querySelector("header svg");
                const statusHeading = element.querySelector("#personal-status");
                return {
                    overflow: document.documentElement.scrollWidth > window.innerWidth,
                    iconBottom: icon.getBoundingClientRect().bottom,
                    businessTop: businessHeading.getBoundingClientRect().top,
                    businessBottom: businessHeading.getBoundingClientRect().bottom,
                    statusTop: statusHeading.getBoundingClientRect().top,
                    titleFont: parseFloat(getComputedStyle(businessHeading).fontSize),
                    statusFont: parseFloat(getComputedStyle(statusHeading).fontSize),
                    family: getComputedStyle(businessHeading).fontFamily,
                    decorative: icon.closest("[aria-hidden]")?.getAttribute("aria-hidden"),
                };
            });
            expect(geometry.overflow).toBe(false);
            expect(geometry.iconBottom).toBeLessThan(geometry.businessTop);
            expect(geometry.businessBottom).toBeLessThan(geometry.statusTop);
            expect(geometry.titleFont).toBeGreaterThanOrEqual(24);
            expect(geometry.statusFont).toBeGreaterThanOrEqual(18);
            expect(geometry.family).toContain("Onest");
            expect(geometry.decorative).toBe("true");
        }
    });
}
