import { expect, test } from "@playwright/test";
import { existsSync, readFileSync } from "node:fs";
import { dirname, extname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const buildAssetsDirectory = resolve(repositoryRoot, "public/build/assets");
const presentationStates = [
    { name: "waiting", color: "rgb(167, 123, 255)", surface: "rgb(61, 46, 85)" },
    { name: "scheduled", color: "rgb(230, 212, 171)", surface: "rgb(58, 51, 38)" },
    { name: "zero", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    { name: "earning", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    { name: "available", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    { name: "redeemed", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    { name: "ended", color: "rgb(255, 180, 190)", surface: "rgb(56, 36, 38)" },
    { name: "expired", color: "rgb(255, 180, 190)", surface: "rgb(56, 36, 38)" },
    { name: "cancelled", color: "rgb(255, 180, 190)", surface: "rgb(56, 36, 38)" },
    { name: "invalid", color: "rgb(255, 180, 190)", surface: "rgb(56, 36, 38)" },
    { name: "unavailable", color: "rgb(230, 212, 171)", surface: "rgb(58, 51, 38)" },
    { name: "zero-plain-1", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    { name: "zero-plain-2", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    { name: "zero-plain-3", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    { name: "zero-plain-5", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    { name: "earning-description-2", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    { name: "earning-description-3", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    { name: "earning-plain-5", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
    {
        name: "cancelled-redeemed-description",
        color: "rgb(255, 180, 190)",
        surface: "rgb(56, 36, 38)",
    },
    { name: "cancelled-redeemed-plain", color: "rgb(255, 180, 190)", surface: "rgb(56, 36, 38)" },
    { name: "large-facts", color: "rgb(188, 231, 201)", surface: "rgb(38, 61, 46)" },
];

/**
 * Supplies actual test-owned rendered output and local assets; refuses all application traffic.
 *
 * @param {import('@playwright/test').Page} page Isolated browser page.
 * @param {() => string} html Current synthetic HTTP response artifact.
 * @returns {Promise<string[]>} Blocked non-asset requests for the caller's isolation assertion.
 */
async function serveRenderedArtifact(page, html) {
    const blockedRequests = [];
    await page.route("**/*", async (route) => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === "/personal-render-fixture" && route.request().method() === "GET") {
            await route.fulfill({ status: 200, contentType: "text/html", body: html() });
            return;
        }
        let assetPath;
        if (pathname.startsWith("/build/assets/")) {
            const candidate = resolve(
                buildAssetsDirectory,
                pathname.replace(/^\/build\/assets\//, ""),
            );
            if (candidate.startsWith(`${buildAssetsDirectory}/`) && existsSync(candidate)) {
                assetPath = candidate;
            }
        }
        if (assetPath && route.request().method() === "GET") {
            const contentTypes = {
                ".css": "text/css",
                ".js": "application/javascript",
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
        if (!/favicon|apple-touch-icon/.test(pathname)) blockedRequests.push(pathname);
        await route.abort();
    });
    return blockedRequests;
}

/**
 * Reads an actual rendered feature-test artifact without contacting the application.
 *
 * @param {string} name Owned fixture name, never a customer URL or identifier.
 * @returns {string} Rendered HTML from the guarded feature-test boundary.
 */
function renderedArtifact(name) {
    const path = resolve(repositoryRoot, `test-results/personal-pass/${name}.html`);
    expect(existsSync(path), "Run the guarded personal feature suite first").toBe(true);
    return readFileSync(path, "utf8");
}

test.describe("read-only personal presentation", () => {
    test.use({ javaScriptEnabled: false });
    for (const viewportWidth of [390, 1280]) {
        test(`renders all personal families and optional content at ${viewportWidth}px`, async ({
            page,
        }, testInfo) => {
            await page.setViewportSize({ width: viewportWidth, height: 900 });
            const titleFontSize = viewportWidth < 768 ? "24px" : "30px";
            const titleLineHeight = viewportWidth < 768 ? "32px" : "36px";
            let pageHtml = "";
            const blockedRequests = await serveRenderedArtifact(page, () => pageHtml);
            for (const { name, color, surface } of presentationStates) {
                pageHtml = renderedArtifact(name);
                await page.goto("http://personal-pass.test/personal-render-fixture");
                await page.evaluate(() => document.fonts.ready);
                const main = page.getByRole("main");
                const error = ["invalid", "unavailable"].includes(name);
                const title = main.locator("#personal-status");
                await expect(title).toBeVisible();
                await expect(title).toHaveCSS("font-size", titleFontSize);
                await expect(title).toHaveCSS("line-height", titleLineHeight);
                await expect(title).toHaveCSS("font-weight", "600");
                await expect(main.locator(".app-personal-icon")).toHaveCSS("color", color);
                await expect(main.locator(".app-personal-icon")).toHaveCSS(
                    "background-color",
                    surface,
                );
                const positive =
                    name === "large-facts" ||
                    ["waiting", "scheduled", "zero", "earning", "available", "redeemed"].some(
                        (state) => name === state || name.startsWith(`${state}-`),
                    );
                await expect(title).toHaveCSS(
                    "color",
                    positive ? "rgb(167, 123, 255)" : "rgb(246, 245, 242)",
                );
                await expect(main.locator(".app-personal-icon")).toHaveCSS("width", "64px");
                await expect(main.locator(".app-personal-icon")).toHaveCSS("height", "64px");
                await expect(main.locator("header > span[aria-hidden=true]")).toHaveCount(1);
                await expect(
                    main.locator(
                        "form, input, button, a, [wire\\:poll], [wire\\:click], [role=progressbar]",
                    ),
                ).toHaveCount(0);
                await expect(main).not.toContainText(
                    /00123|signature|sincroniz|inscr[ií]b|historial|TU ÚLTIMO CANJE/i,
                );
                if (!error) {
                    await expect(main.getByRole("heading", { level: 1 })).toHaveCSS(
                        "font-size",
                        titleFontSize,
                    );
                    await expect(main.getByRole("heading", { level: 1 })).toHaveCSS(
                        "line-height",
                        titleLineHeight,
                    );
                    await expect(main.getByRole("heading", { level: 1 })).toHaveCSS(
                        "font-weight",
                        "600",
                    );
                    await expect(main.getByRole("heading", { level: 1 })).toHaveCSS(
                        "color",
                        "rgb(246, 245, 242)",
                    );
                    if (name !== "waiting") {
                        await expect(main.locator("section > p").first()).toContainText(
                            name === "large-facts" ? "Recompensa".repeat(22) : "Un pan artesanal",
                        );
                    }
                } else {
                    await expect(main.getByRole("heading", { level: 1 })).toHaveCount(1);
                    await expect(main.locator("[role=alert] svg")).toHaveCount(0);
                    await expect(main).not.toContainText("Panadería");
                }
                if (
                    [
                        "earning-description-3",
                        "scheduled",
                        "zero",
                        "redeemed",
                        "available",
                        "cancelled-redeemed-description",
                    ].includes(name)
                ) {
                    await page.screenshot({
                        path: testInfo.outputPath(
                            `personal-${name === "earning-description-3" ? "earning" : name}-${viewportWidth}.png`,
                        ),
                        fullPage: true,
                    });
                }
                const nextSteps = error
                    ? main.getByRole("alert")
                    : main.getByText(
                          /^\s*(Cuando empiece, muestra tu tarjeta|Muestra tu tarjeta en tu próxima visita|Muestra tu tarjeta en el negocio para canjearla)/,
                      );
                const hasNextStep =
                    error ||
                    name === "large-facts" ||
                    ["scheduled", "zero", "earning", "available"].some(
                        (state) => name === state || name.startsWith(`${state}-`),
                    );
                await expect(nextSteps).toHaveCount(hasNextStep ? 1 : 0);
                for (const instruction of await nextSteps.all()) {
                    await expect(instruction).toHaveCSS("color", "rgb(167, 123, 255)");
                    await expect.soft(instruction).toHaveCSS("font-weight", "400");
                }
                const supportingFacts = main.getByText(
                    /^\s*(Por ahora no hay promociones disponibles\.|Preparado cada mañana\.)/,
                );
                for (const fact of await supportingFacts.all()) {
                    await expect(fact).toHaveCSS("color", "rgb(199, 196, 206)");
                }
                const temporalInformation = main.getByText(
                    /^\s*(Empiezas a sumar el|Tienes hasta el|Puedes canjearla hasta el|Estuvo disponible hasta el|Podías canjearla hasta el|La canjeaste el|Tu recompensa fue canjeada el)/,
                );
                const hasTemporalInformation =
                    name === "large-facts" ||
                    name.startsWith("cancelled-redeemed-") ||
                    [
                        "scheduled",
                        "zero",
                        "earning",
                        "available",
                        "redeemed",
                        "ended",
                        "expired",
                    ].some((state) => name === state || name.startsWith(`${state}-`));
                await expect(temporalInformation).toHaveCount(hasTemporalInformation ? 1 : 0);
                for (const date of await temporalInformation.all()) {
                    await expect(date).toHaveCSS("color", "rgb(199, 196, 206)");
                    await expect(date).toHaveCSS("font-weight", "700");
                    await expect(date).toHaveCSS("font-size", "14px");
                    await expect(date).toHaveCSS("line-height", "20px");
                }
                if (name === "available" || name.startsWith("available-")) {
                    await expect(
                        main.locator("[data-personal-progress], .app-personal-multiplier"),
                    ).toHaveCount(0);
                    await expect(nextSteps).toHaveText(
                        "Muestra tu tarjeta en el negocio para canjearla.",
                    );
                    const date = await temporalInformation.boundingBox();
                    const instruction = await nextSteps.boundingBox();
                    expect(date.y + date.height).toBeLessThanOrEqual(instruction.y);
                }
                const historicalDates = main.getByText(
                    /^\s*(La canjeaste el|Tu recompensa fue canjeada el)/,
                );
                const hasHistoricalDate =
                    name === "redeemed" ||
                    name.startsWith("redeemed-") ||
                    name.startsWith("cancelled-redeemed-");
                await expect(historicalDates).toHaveCount(hasHistoricalDate ? 1 : 0);
                if (hasHistoricalDate) {
                    await expect(nextSteps).toHaveCount(0);
                    await expect(
                        main.locator("[data-personal-progress], .app-personal-multiplier"),
                    ).toHaveCount(0);
                    await expect(main.locator("section > p").last()).toHaveText(
                        /^\s*(La canjeaste el|Tu recompensa fue canjeada el)/,
                    );
                }
                if (name.startsWith("scheduled")) {
                    await expect.soft(main).not.toContainText("Necesitarás");
                }
                for (const paragraph of await main.locator("section > p").all()) {
                    await expect(paragraph).toHaveText(/\S/);
                }
                const progress = main.locator("[data-personal-progress]");
                if ((await progress.count()) > 0) {
                    await expect(progress).toHaveCSS("background-color", "rgb(46, 46, 46)");
                    await expect(progress).toHaveCSS("border-color", "rgb(82, 82, 82)");
                    await expect(progress).toHaveCSS("border-radius", "20px");
                    await expect(progress).toHaveCSS("row-gap", "4px");
                    await expect(progress).toHaveCSS(
                        "padding-top",
                        viewportWidth < 640 ? "16px" : "24px",
                    );
                    await expect(progress).toHaveCSS(
                        "padding-left",
                        viewportWidth < 640 ? "16px" : "24px",
                    );
                    const countLine = progress.locator(".app-role-personal-count");
                    await expect(countLine).toHaveCSS("column-gap", "12px");
                    await expect(countLine).toHaveCSS("font-size", "40px");
                    await expect(countLine).toHaveCSS("line-height", "48px");
                    const earnedPoints = countLine.locator("span").first();
                    const target = countLine.locator("span").last();
                    await expect(earnedPoints).toHaveCSS("color", "rgb(167, 123, 255)");
                    await expect(target).toHaveCSS("color", "rgb(246, 245, 242)");
                    if (name !== "large-facts") {
                        const earned = await earnedPoints.boundingBox();
                        const relation = await target.boundingBox();
                        expect(relation.x - earned.x - earned.width).toBeGreaterThanOrEqual(12);
                    }
                    expect(
                        await earnedPoints.evaluate((el) => getComputedStyle(el).color),
                    ).not.toBe(await target.evaluate((el) => getComputedStyle(el).color));
                    await expect(progress.locator("span").last()).toHaveText("puntos");
                    const count = await progress.locator("span").first().boundingBox();
                    const unit = await progress.locator("span").last().boundingBox();
                    expect(unit.y).toBeGreaterThanOrEqual(count.y + count.height);
                }
                const multiplier = main.locator(".app-personal-multiplier");
                if ((await multiplier.count()) > 0) {
                    await expect(multiplier).toHaveCSS("color", "rgb(228, 214, 250)");
                    await expect(multiplier).toHaveCSS("background-color", "rgb(61, 46, 85)");
                    await expect(multiplier).toHaveCSS("border-radius", "20px");
                    await expect(multiplier).toHaveCSS("border-color", "rgb(132, 101, 172)");
                    await expect(multiplier).toHaveCSS("border-width", "1px");
                    await expect(multiplier).toHaveCSS(
                        "padding-top",
                        viewportWidth < 640 ? "16px" : "24px",
                    );
                    await expect(multiplier).toHaveCSS(
                        "padding-left",
                        viewportWidth < 640 ? "16px" : "24px",
                    );
                    await expect.soft(multiplier).toHaveCSS("font-size", "16px");
                    await expect.soft(multiplier).toHaveCSS("line-height", "24px");
                    await expect(multiplier).toHaveCSS("gap", "8px");
                    expect(
                        await multiplier.evaluate((el) => getComputedStyle(el).fontFamily),
                    ).toContain("Onest");
                    expect(
                        await page.evaluate(() =>
                            document.fonts.check('400 16px "Onest Variable"'),
                        ),
                    ).toBe(true);
                    await expect(multiplier.locator("svg[aria-hidden=true]")).toHaveCount(1);
                    await expect.soft(multiplier).toHaveCSS("font-weight", "400");
                    const multiplierBox = await multiplier.boundingBox();
                    const progressBox = await progress.boundingBox();
                    const deadlineBox = await temporalInformation.boundingBox();
                    expect(progressBox.y + progressBox.height).toBeLessThanOrEqual(deadlineBox.y);
                    expect(deadlineBox.y + deadlineBox.height).toBeLessThanOrEqual(multiplierBox.y);
                    if (name === "earning-description-3") {
                        await multiplier.screenshot({
                            path: testInfo.outputPath(`personal-multiplier-${viewportWidth}.png`),
                        });
                    }
                    await expect(multiplier.locator("svg")).toHaveAttribute("fill", "currentColor");
                    await expect(multiplier.locator("svg")).not.toHaveAttribute(
                        "stroke",
                        "currentColor",
                    );
                }
                const geometry = await main.evaluate((element) => {
                    const business = element.querySelector("header h1");
                    const icon = element.querySelector(".app-personal-icon");
                    const status = element.querySelector("#personal-status");
                    return {
                        overflow: document.documentElement.scrollWidth > window.innerWidth,
                        iconBottom: icon.getBoundingClientRect().bottom,
                        nextTop: (business ?? status).getBoundingClientRect().top,
                        businessBottom: business?.getBoundingClientRect().bottom,
                        statusTop: status.getBoundingClientRect().top,
                        family: getComputedStyle(status).fontFamily,
                        onestLoaded: [...document.fonts].some(
                            (face) => face.family.includes("Onest") && face.status === "loaded",
                        ),
                    };
                });
                expect(geometry.overflow).toBe(false);
                expect(geometry.iconBottom).toBeLessThan(geometry.nextTop);
                if (geometry.businessBottom)
                    expect(geometry.businessBottom).toBeLessThan(geometry.statusTop);
                expect(geometry.family).toContain("Onest");
                expect(geometry.onestLoaded).toBe(true);
                if (name.includes("plain"))
                    await expect(main).not.toContainText("Preparado cada mañana.");
                if (name.startsWith("cancelled-redeemed"))
                    await expect(main).toContainText("Tu recompensa fue canjeada el 1 ene 2030.");
            }
            expect(blockedRequests).toEqual([]);
        });
    }
});
