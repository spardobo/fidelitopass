import { expect, test } from "@playwright/test";

const VIEWPORT_WIDTHS = [375, 768, 1024, 1280];
const EXPECTED_NAVIGATION = [
    ["#benefits", "Beneficios"],
    ["#how-it-works", "Cómo funciona"],
    ["#challenges", "Promociones"],
    ["#pass", "El Pase"],
    ["#questions", "Preguntas frecuentes"],
    ["#business", "Empezar"],
];

async function expectOrderedNavigation(nav) {
    await expect(nav.locator("a")).toHaveCount(EXPECTED_NAVIGATION.length);
    expect(
        await nav
            .locator("a")
            .evaluateAll((links) =>
                links.map((link) => [link.getAttribute("href"), link.textContent.trim()]),
            ),
    ).toEqual(EXPECTED_NAVIGATION);
}

async function waitForFonts(page) {
    await page.evaluate(() => document.fonts.ready);
}

async function expectNoHorizontalOverflow(page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
}

async function getPassFixture(page) {
    const pass = page.locator("[data-pass]");
    const bounds = await page.locator("[data-thumbnail]").boundingBox();

    return { pass, bounds };
}

async function expectVisibleAtRest(locator) {
    await expect(locator).toHaveCSS("opacity", "1");
    await expect(locator).toHaveCSS("transform", "none");
}

async function applyCssVariables(page, values) {
    await page.evaluate((variables) => {
        for (const [name, value] of Object.entries(variables)) {
            document.documentElement.style.setProperty(name, value);
        }
    }, values);
}

async function removeCssVariables(page, names) {
    await page.evaluate((variables) => {
        for (const name of variables) {
            document.documentElement.style.removeProperty(name);
        }
    }, names);
}

test("sample pass responds with a stronger bounded mouse tilt and returns to rest", async ({
    page,
}) => {
    await page.goto("/");

    const { pass, bounds } = await getPassFixture(page);

    await pass.dispatchEvent("pointermove", {
        pointerType: "mouse",
        clientX: bounds.x + bounds.width,
        clientY: bounds.y,
    });

    const tiltAngleDegrees = () =>
        pass.evaluate((el) => {
            const matrix = new DOMMatrix(getComputedStyle(el).transform);
            return (
                (Math.acos(
                    Math.max(-1, Math.min(1, (matrix.m11 + matrix.m22 + matrix.m33 - 1) / 2)),
                ) *
                    180) /
                Math.PI
            );
        });
    await expect.poll(tiltAngleDegrees).toBeGreaterThan(7.5);
    const settledAngle = await tiltAngleDegrees();
    console.log("pass-tilt-degrees", settledAngle);
    expect(settledAngle).toBeLessThanOrEqual(8.1);

    await pass.dispatchEvent("pointerleave");
    await expect(pass).toHaveCSS("transform", "none");
});

test("initially visible benefits play a real entrance transition once", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 1200 });
    await page.addInitScript(() => {
        window.benefitsFrames = [];
        const record = () => {
            const section = document.querySelector("#benefits");
            if (section) {
                const style = getComputedStyle(section);
                window.benefitsFrames.push({
                    opacity: Number(style.opacity),
                    y: new DOMMatrix(style.transform).m42,
                    top: section.getBoundingClientRect().top,
                });
            }
            if (window.benefitsFrames.length < 90) requestAnimationFrame(record);
        };
        requestAnimationFrame(record);
    });
    await page.goto("/");

    await expect
        .poll(() => page.evaluate(() => window.benefitsFrames.length))
        .toBeGreaterThanOrEqual(90);
    const frames = await page.evaluate(() => window.benefitsFrames);
    console.log(
        "benefits-entry",
        JSON.stringify({
            first: frames[0],
            pending: frames.find((frame) => frame.opacity === 0),
            moving: frames.find((frame) => frame.opacity > 0 && frame.opacity < 1),
            last: frames.at(-1),
        }),
    );
    expect(frames.some((frame) => frame.top < 1200)).toBe(true);
    expect(frames.some((frame) => frame.opacity === 0 && frame.y >= 23)).toBe(true);
    expect(frames.some((frame) => frame.opacity > 0 && frame.opacity < 1 && frame.y > 0)).toBe(
        true,
    );
    await expectVisibleAtRest(page.locator("#benefits"));
    await page.locator("footer").scrollIntoViewIfNeeded();
    await page.locator("#home").scrollIntoViewIfNeeded();
    await expect(page.locator("#benefits")).toHaveCSS("opacity", "1");
});

test("landing typography uses the loaded local Onest and responsive editorial roles", async ({
    page,
}) => {
    for (const width of VIEWPORT_WIDTHS) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto("/");

        await waitForFonts(page);

        const roles = await page.evaluate(() => {
            const style = (selector) => {
                const computed = getComputedStyle(document.querySelector(selector));
                return [computed.fontSize, computed.lineHeight, computed.fontWeight];
            };
            return {
                loaded: document.fonts.check('600 44px "Onest Variable"'),
                family: getComputedStyle(document.querySelector("#hero-title")).fontFamily,
                hero: style("#hero-title"),
                section: style("#benefits-title"),
                card: style("#benefits article h3"),
                statement: style("[data-tagline]"),
                body: style("#home p:not(.text-sm)"),
                support: style("#home .app-role-support"),
                heroInk: getComputedStyle(document.querySelector("#hero-title")).color,
                sectionInk: getComputedStyle(document.querySelector("#benefits-title")).color,
                benefitEyebrowInk: getComputedStyle(
                    document.querySelector("#benefits > div:first-child > p:first-child"),
                ).color,
                sectionIntroInks: [
                    "#home > div:first-child > span",
                    "#benefits > div:first-child > p:first-child",
                    "#how-it-works > p:first-child",
                    "#challenges > p:first-child",
                    "#pass > p:first-child",
                ].map((selector) => {
                    const element = document.querySelector(selector);
                    return element ? getComputedStyle(element).color : `missing:${selector}`;
                }),
                loginLinkInk: getComputedStyle(document.querySelector('#home a[href$="/login"]'))
                    .color,
            };
        });
        console.log("landing-computed-roles", width, JSON.stringify(roles));

        expect(roles.loaded).toBe(true);
        expect(roles.family).toContain("Onest Variable");
        let expectedHeroTypography = ["36px", "40px", "600"];

        if (width >= 1120) {
            expectedHeroTypography = ["44px", "48px", "600"];
        } else if (width >= 1024) {
            expectedHeroTypography = ["40px", "44px", "600"];
        }

        expect(roles.hero).toEqual(expectedHeroTypography);
        expect(roles.section).toEqual(
            width < 768 ? ["28px", "36px", "600"] : ["32px", "40px", "600"],
        );
        expect(roles.card).toEqual(width < 768 ? ["20px", "28px", "600"] : ["24px", "32px", "600"]);
        expect(roles.statement).toEqual(
            width < 768 ? ["28px", "36px", "600"] : ["32px", "40px", "600"],
        );
        expect(roles.heroInk).toBe("rgb(246, 245, 242)");
        expect(roles.sectionInk).toBe("rgb(246, 245, 242)");
        expect(roles.benefitEyebrowInk).toBe("rgb(167, 123, 255)");
        expect(roles.sectionIntroInks).toEqual(Array(5).fill("rgb(167, 123, 255)"));
        expect(roles.loginLinkInk).toBe("rgb(167, 123, 255)");
        await expect(page.locator("#benefits article").first().locator("h3")).toHaveCSS(
            "color",
            "rgb(246, 245, 242)",
        );
        expect(roles.body).toEqual(["16px", "24px", "400"]);
        expect(roles.support).toEqual(["14px", "20px", "400"]);
    }
});

test("public copy preserves sample pass typography and contextual paragraph ink", async ({
    page,
}) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    for (const width of [375, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto("/");

        await waitForFonts(page);

        const sample = await page.locator("[data-pass]").evaluate((pass) => {
            const typography = (element) => {
                const style = getComputedStyle(element);
                return [style.fontSize, style.lineHeight, style.fontWeight, style.color];
            };
            return [...pass.querySelectorAll("h2, h3, p, p span")].map(typography);
        });
        console.log("public-copy-sample", width, JSON.stringify(sample));
        const ink = "rgb(23, 19, 31)";
        expect(sample).toEqual([
            ["20px", "28px", "700", ink],
            ["18px", "28px", "600", ink],
            ["14px", "20px", "400", ink],
            ["30px", "36px", "700", ink],
            ["14px", "20px", "400", ink],
            ["16px", "24px", "700", ink],
            ["14px", "20px", "400", ink],
            ["14px", "20px", "600", ink],
            ["20px", "28px", "600", ink],
        ]);

        const composition = await page.locator("[data-pass]").evaluate((pass) => {
            const middle = pass.querySelector(".app-pass-preview-content");
            const qr = middle.querySelector(".app-pass-preview-qr");
            const footer = pass.querySelector(".app-pass-preview-reward");
            const manualCode = footer.querySelector(".app-pass-preview-manual-code");
            const middleCopy = middle.querySelector(".app-pass-preview-copy");
            const footerCopy = footer.firstElementChild;

            const qrBounds = qr.getBoundingClientRect();
            const middleBounds = middle.getBoundingClientRect();
            const footerBounds = footer.getBoundingClientRect();
            const manualBounds = manualCode.getBoundingClientRect();
            const middleCopyBounds = middleCopy.getBoundingClientRect();
            const footerCopyBounds = footerCopy.getBoundingClientRect();

            return {
                qrInsideMiddle: middle.contains(qr),
                qrWithinMiddle:
                    qrBounds.top >= middleBounds.top && qrBounds.bottom <= middleBounds.bottom,
                footerFollowsMiddle: footerBounds.top >= middleBounds.bottom,
                manualInsideFooter: footer.contains(manualCode),
                manualWithinFooter:
                    manualBounds.top >= footerBounds.top &&
                    manualBounds.bottom <= footerBounds.bottom,
                qrCentered:
                    Math.abs(
                        (qrBounds.top + qrBounds.bottom) / 2 -
                            (middleCopyBounds.top + middleCopyBounds.bottom) / 2,
                    ) <= 1,
                manualCentered:
                    Math.abs(
                        (manualBounds.top + manualBounds.bottom) / 2 -
                            (footerCopyBounds.top + footerCopyBounds.bottom) / 2,
                    ) <= 1,
                rewardWeight: getComputedStyle(footer.querySelector("p")).fontWeight,
            };
        });

        expect(composition).toEqual({
            qrInsideMiddle: true,
            qrWithinMiddle: true,
            footerFollowsMiddle: true,
            manualInsideFooter: true,
            manualWithinFooter: true,
            qrCentered: true,
            manualCentered: true,
            rewardWeight: "700",
        });

        for (const [selector, color] of [
            [
                "#benefits > div:first-child > p:last-child, #how-it-works > p:nth-of-type(2), #pass > p:nth-of-type(2)",
                "rgb(196, 196, 196)",
            ],
            [
                "#benefits article:last-child p, #how-it-works li p, #pass > p:last-child, #questions details > p",
                "rgb(224, 224, 224)",
            ],
            ["#business > p", ink],
        ]) {
            for (const paragraph of await page.locator(selector).all()) {
                await expect(paragraph).toHaveCSS("font-size", "16px");
                await expect(paragraph).toHaveCSS("line-height", "24px");
                await expect(paragraph).toHaveCSS("font-weight", "400");
                await expect(paragraph).toHaveCSS("color", color);
            }
        }
        for (const heading of await page
            .locator("#steps-title, #challenge-title, #wallet-title, #faq-title")
            .all()) {
            await expect(heading).toHaveCSS("color", "rgb(246, 245, 242)");
        }
        await expect(page.locator("#business-title")).toHaveCSS("color", ink);
    }
});

test("landing registration calls to action use the shared action role and control height", async ({
    page,
}) => {
    for (const width of [375, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto("/");

        for (const button of await page
            .locator('#home a[href*="register"], #business a[href*="register"]')
            .all()) {
            await expect(button).toHaveCSS("font-size", "16px");
            await expect(button).toHaveCSS("line-height", "24px");
            await expect(button).toHaveCSS("font-weight", "500");
            await expect(button).toHaveCSS("min-height", "44px");
        }
    }
});

test("public links preserve inherited ink weight decoration and keyboard focus", async ({
    page,
}) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    for (const width of [375, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto("/");

        await page.mouse.move(0, 0);
        if (width < 1280) await page.locator(".landing-menu summary").click();
        const nav = page.locator(width < 1280 ? ".landing-menu nav" : "header > div > nav");
        await expectOrderedNavigation(nav);

        for (const [links, ink, decoration, size] of [
            [nav.locator("a"), "rgb(246, 245, 242)", "none", width < 1280 ? "16px" : "14px"],
            [page.locator("footer a"), "rgb(224, 224, 224)", "none", "14px"],
            [
                page.locator("#home .landing-role-support a"),
                "rgb(224, 224, 224)",
                "underline",
                "14px",
            ],
        ]) {
            for (const link of await links.all()) {
                await expect(link).toHaveCSS("font-size", size);
                await expect(link).toHaveCSS("font-weight", "400");
                await expect(link).toHaveCSS("color", ink);
                await expect(link).toHaveCSS("text-decoration-line", decoration);
                await expect(link).toHaveCSS("text-underline-offset", "auto");
                await link.hover();
                await expect(link).toHaveCSS("color", "rgb(205, 176, 255)");
                await expect(link).toHaveCSS("text-decoration-line", decoration);
                await page.keyboard.press("Tab");
                await link.focus();
                await expect(link).toBeFocused();
                await expect(link).toHaveCSS("outline-color", "rgb(205, 176, 255)");
                await expect(link).toHaveCSS("outline-offset", "4px");
                await page.mouse.move(0, 0);
            }
        }
        await expect(page.locator("#home .landing-role-support")).toHaveText(/\? Inicia sesión$/);
        const logo = page.locator('header a[href="#page-top"]');
        await expect(logo).toHaveAttribute("aria-label", /\S/);
        await expect(logo.locator("img")).toHaveAttribute("width", "480");
        await expect(logo.locator("img")).toHaveAttribute("height", "105");
        await page.reload();
        await page.keyboard.press("Tab");
        const skip = page.getByRole("link", { name: "Ir al contenido" });
        await expect(skip).toBeFocused();
        await expect(skip).toHaveCSS("color", "rgb(23, 19, 31)");
        await expect(skip).toHaveCSS("text-decoration-line", "none");
        await page.keyboard.press("Enter");
        await expect(page.locator("main")).toBeFocused();
    }
});

test("hero outer gaps are bounded by the existing major section rhythm", async ({ page }) => {
    for (const width of [375, 768, 1280]) {
        for (const height of [900, 1600]) {
            await page.setViewportSize({ width, height });
            await page.goto("/");

            const geometry = await page.locator("#home").evaluate((hero) => {
                const rect = hero.getBoundingClientRect();
                const benefits = document.querySelector("#benefits");
                return {
                    topGap:
                        rect.top - document.querySelector("header").getBoundingClientRect().bottom,
                    bottomGap: parseFloat(getComputedStyle(hero).marginBottom),
                    sectionSpace: parseFloat(getComputedStyle(benefits).paddingTop),
                    heroTop: rect.top,
                    heroBottom: rect.bottom,
                    benefitsTop: benefits.getBoundingClientRect().top,
                    titleTop: benefits.firstElementChild.getBoundingClientRect().top,
                };
            });

            console.log("bounded-hero", width, height, JSON.stringify(geometry));
            expect(geometry.topGap).toBeLessThanOrEqual(geometry.sectionSpace);
            expect(geometry.bottomGap).toBeLessThanOrEqual(geometry.sectionSpace);
            expect(geometry.topGap).toBeCloseTo(geometry.bottomGap, 0);
            expect(Math.abs(geometry.topGap / 4 - Math.round(geometry.topGap / 4))).toBeLessThan(
                0.001,
            );
            expect(
                Math.abs(geometry.bottomGap / 4 - Math.round(geometry.bottomGap / 4)),
            ).toBeLessThan(0.001);
        }
    }
});

test("organic hero preserves base main padding and a centered nominal cap", async ({ page }) => {
    for (const height of [1200, 900, 729, 540]) {
        await page.setViewportSize({ width: 1280, height });
        await page.goto("/");

        await waitForFonts(page);
        const geometry = await page.locator("#home").evaluate((hero) => {
            const rect = hero.getBoundingClientRect();
            const header = document.querySelector("header").getBoundingClientRect();
            return {
                padding: getComputedStyle(document.querySelector("main")).paddingTop,
                height: rect.height,
                topGap: rect.top - header.bottom,
                bottomGap: parseFloat(getComputedStyle(hero).marginBottom),
            };
        });
        console.log("organic-hero", height, JSON.stringify(geometry));
        expect(geometry.padding).toBe("40px");
        if (height >= 729) {
            expect(geometry.height).toBeLessThanOrEqual(704);
            expect(geometry.topGap).toBeCloseTo(geometry.bottomGap, 0);
        } else expect(geometry.topGap).toBe(40);
    }
    await page.setViewportSize({ width: 375, height: 540 });
    await page.goto("/");

    const mobile = await page.locator("#home").evaluate((hero) => ({
        base: getComputedStyle(document.querySelector("main")).paddingTop,
        top:
            hero.getBoundingClientRect().top -
            document.querySelector("header").getBoundingClientRect().bottom,
        bottomMargin: getComputedStyle(hero).marginBottom,
    }));
    expect(mobile.base).toBe("32px");
    expect(mobile.top).toBeCloseTo(32, 0);
    expect(parseFloat(mobile.bottomMargin)).toBeGreaterThanOrEqual(32);
});

test("shared tracks retain accessible main and banner landmarks and skip-link focus", async ({
    page,
}) => {
    await page.goto("/");

    await expect(page.getByRole("banner")).toHaveCount(1);
    await expect(page.getByRole("main")).toHaveCount(1);

    const roles = await page.context().newCDPSession(page);
    const tree = await roles.send("Accessibility.getFullAXTree");
    expect(tree.nodes.filter((node) => !node.ignored && node.role?.value === "main")).toHaveLength(
        1,
    );
    expect(
        tree.nodes.filter((node) => !node.ignored && node.role?.value === "banner"),
    ).toHaveLength(1);
    await page.keyboard.press("Tab");
    await page.keyboard.press("Enter");
    await expect(page.locator("main")).toBeFocused();
    await expect(page).toHaveURL(/#content$/);
});

test("runtime fits the hero without ResizeObserver while no-JS remains readable", async ({
    browser,
}) => {
    for (const mode of ["disabled", "no-resize-observer"]) {
        const context = await browser.newContext({
            viewport: { width: 1280, height: 900 },
            javaScriptEnabled: mode !== "disabled",
        });
        try {
            if (mode === "no-resize-observer") {
                await context.addInitScript(() => {
                    window.ResizeObserver = undefined;
                });
            }
            const page = await context.newPage();
            await page.goto("/");

            const layout = await page.evaluate(() => {
                const header = document.querySelector("header").getBoundingClientRect();
                const main = document.querySelector("main").getBoundingClientRect();
                const hero = document.querySelector("#home").getBoundingClientRect();
                return {
                    headerBottom: header.bottom,
                    mainTop: main.top,
                    heroTop: hero.top,
                    heroHeight: hero.height,
                    heroBottom: hero.bottom,
                };
            });
            expect(layout.heroTop - layout.headerBottom, mode).toBeGreaterThan(0);
            expect(layout.mainTop, mode).toBeCloseTo(layout.headerBottom, 0);
            expect(layout.heroHeight, mode).toBeGreaterThan(0);

            if (mode === "no-resize-observer") {
                expect(layout.heroHeight, mode).toBeCloseTo(704, 0);
                expect(layout.heroTop - layout.headerBottom).toBeCloseTo(
                    900 - layout.heroBottom,
                    0,
                );
                await expect(page.locator("#business")).toHaveAttribute("data-reveal", "pending");

                await page.locator("main").evaluate((main) => {
                    main.style.paddingTop = "80px";
                    for (let index = 0; index < 5; index++) {
                        window.dispatchEvent(new Event("resize"));
                    }
                });
                await expect
                    .poll(() =>
                        page
                            .locator("#home")
                            .evaluate((hero) => hero.getBoundingClientRect().height),
                    )
                    .toBeCloseTo(649, 0);
                expect(
                    await page
                        .locator("#home")
                        .evaluate(
                            (hero) =>
                                hero.getBoundingClientRect().top -
                                document.querySelector("header").getBoundingClientRect().bottom,
                        ),
                ).toBeCloseTo(80, 0);

                await page.locator("main").evaluate((main) => {
                    main.style.removeProperty("padding-top");
                    window.dispatchEvent(new Event("resize"));
                });
                await page.locator("header").evaluate((header) => {
                    header.style.paddingBottom = "20px";
                    window.dispatchEvent(new Event("resize"));
                });

                await expect
                    .poll(() =>
                        page.locator("#home").evaluate((hero) => {
                            const box = hero.getBoundingClientRect();
                            const headerBottom = document
                                .querySelector("header")
                                .getBoundingClientRect().bottom;

                            return Math.abs(box.top - headerBottom - (innerHeight - box.bottom));
                        }),
                    )
                    .toBeLessThan(1);

                await page.setViewportSize({ width: 1280, height: 540 });
                await expect
                    .poll(() =>
                        page
                            .locator("#home")
                            .evaluate((hero) => parseFloat(getComputedStyle(hero).marginTop)),
                    )
                    .toBe(0);
                expect(
                    await page
                        .locator("#home")
                        .evaluate(
                            (hero) =>
                                hero.getBoundingClientRect().top -
                                document.querySelector("header").getBoundingClientRect().bottom,
                        ),
                ).toBeCloseTo(40, 0);
            } else {
                await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
                await expect(page.locator("#business")).toHaveCSS("opacity", "1");
                await expect(page.locator(".landing-world")).toHaveCSS(
                    "background-color",
                    "rgb(36, 36, 36)",
                );
                await expect(page.locator("#home")).toHaveCSS(
                    "background-color",
                    "rgb(46, 46, 46)",
                );
                expect(layout.heroBottom).toBeGreaterThan(layout.heroTop);
            }
        } finally {
            await context.close();
        }
    }
});

test("measured hero distributes unused space symmetrically", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto("/");

    const geometry = await page.evaluate(() => {
        const main = document.querySelector("main");
        const hero = document.querySelector("#home").getBoundingClientRect();
        return {
            headerBottom: document.querySelector("header").getBoundingClientRect().bottom,
            top: hero.top,
            bottom: hero.bottom,
            padding: parseFloat(getComputedStyle(main).paddingTop),
        };
    });
    expect(geometry.headerBottom).toBeCloseTo(91, 0);
    expect(geometry.padding).toBe(40);
    expect(geometry.top - geometry.headerBottom).toBeCloseTo(900 - geometry.bottom, 0);
});

test("hero text and controls remain inside their panel at 200 percent text size", async ({
    page,
}) => {
    for (const width of [320, 375, 1280]) {
        await page.setViewportSize({ width, height: 700 });
        await page.goto("/");

        await page.evaluate(() => {
            document.documentElement.style.fontSize = "32px";
        });
        const boxes = await page.locator("#home").evaluate((hero) => {
            const panel = hero.getBoundingClientRect();
            const selectors = [
                "#hero-title",
                "#home p[data-flux-text]",
                "#home .landing-button",
                "[data-thumbnail]",
            ];
            return {
                panel: { left: panel.left, right: panel.right },
                content: selectors.map((selector) => {
                    const box = document.querySelector(selector).getBoundingClientRect();
                    return { selector, left: box.left, right: box.right };
                }),
                viewportOverflow: document.documentElement.scrollWidth > innerWidth,
            };
        });
        for (const box of boxes.content) {
            expect(box.left, `${width} ${box.selector}`).toBeGreaterThanOrEqual(boxes.panel.left);
            expect(box.right, `${width} ${box.selector}`).toBeLessThanOrEqual(boxes.panel.right);
        }
        expect(boxes.viewportOverflow).toBe(false);
        await page.locator("#home .landing-button").focus();
        await expect(page.locator("#home .landing-button")).toBeFocused();
    }
});

test("landing observers disconnect when its Livewire root is removed without navigation", async ({
    page,
}) => {
    await page.addInitScript(() => {
        window.landingObservers = { resize: 0, intersection: 0 };
        for (const [name, counter] of [
            ["ResizeObserver", "resize"],
            ["IntersectionObserver", "intersection"],
        ]) {
            const NativeObserver = window[name];
            window[name] = class extends NativeObserver {
                disconnect() {
                    window.landingObservers[counter]++;
                    return super.disconnect();
                }
            };
        }
    });
    await page.goto("/");

    await expect(page.locator("#business")).toHaveAttribute("data-reveal", "pending");
    await page.locator(".landing-world").evaluate((root) => root.remove());
    await expect
        .poll(() => page.evaluate(() => window.landingObservers.resize))
        .toBeGreaterThanOrEqual(1);
    await expect
        .poll(() => page.evaluate(() => window.landingObservers.intersection))
        .toBeGreaterThanOrEqual(1);
});

test("overlapping navigation and disposal restore visible content and allow one fresh initialization", async ({
    page,
}) => {
    await page.addInitScript(() => {
        window.createdLandingObservers = 0;
        const NativeObserver = window.ResizeObserver;
        window.ResizeObserver = class extends NativeObserver {
            constructor(callback) {
                super(callback);
                window.createdLandingObservers++;
            }
        };
    });
    await page.goto("/");

    const closing = page.locator("#business");
    await expect(closing).toHaveAttribute("data-reveal", "pending");
    const replay = () =>
        page.locator(".landing-world").evaluate((root) => {
            const scripts = JSON.parse(root.getAttribute("wire:effects")).scripts;
            const script = new DOMParser()
                .parseFromString(Object.values(scripts)[0], "text/html")
                .querySelector("script").textContent;
            Function("$wire", script)({ $el: root });
        });
    const initialObservers = await page.evaluate(() => window.createdLandingObservers);
    await replay();
    expect(await page.evaluate(() => window.createdLandingObservers)).toBe(initialObservers);
    await page.evaluate(() => {
        document.dispatchEvent(new Event("livewire:navigating"));
        document.dispatchEvent(new Event("livewire:navigating"));
    });
    await expectVisibleAtRest(closing);
    await replay();
    expect(await page.evaluate(() => window.createdLandingObservers)).toBe(initialObservers + 1);
    await replay();
    expect(await page.evaluate(() => window.createdLandingObservers)).toBe(initialObservers + 1);
    const { pass, bounds } = await getPassFixture(page);
    await pass.dispatchEvent("pointermove", {
        pointerType: "mouse",
        clientX: bounds.x + bounds.width,
        clientY: bounds.y,
    });
    await expect
        .poll(() => pass.evaluate((el) => getComputedStyle(el).transform))
        .toMatch(/^matrix3d\(/);
    await page.locator(".landing-world").evaluate((root) => {
        document.dispatchEvent(new Event("livewire:navigating"));
        root.remove();
    });
});

test("replacing actual hero nodes reconnects sizing and pointer behavior without retaining stale nodes", async ({
    page,
}) => {
    await page.goto("/");

    await page.locator("#home").evaluate((hero) => hero.replaceWith(hero.cloneNode(true)));
    const { pass, bounds } = await getPassFixture(page);
    await pass.dispatchEvent("pointermove", {
        pointerType: "mouse",
        clientX: bounds.x + bounds.width,
        clientY: bounds.y,
    });
    await expect
        .poll(() => pass.evaluate((el) => getComputedStyle(el).transform))
        .toMatch(/^matrix3d\(/);
    await pass.dispatchEvent("pointerleave");
    await expect(pass).toHaveCSS("transform", "none");
    await page.setViewportSize({ width: 375, height: 700 });
    await expect
        .poll(() => page.locator("[data-pass]").evaluate((el) => el.getBoundingClientRect().width))
        .toBeCloseTo((await page.locator("[data-thumbnail]").boundingBox()).width, 0);
});

test("a pending tagline cadence completes visibly when reduced motion changes", async ({
    page,
}) => {
    await page.addInitScript(() => {
        const NativeObserver = window.IntersectionObserver;
        window.startTagline = null;
        window.IntersectionObserver = class extends NativeObserver {
            constructor(callback, options) {
                super(callback, options);
                this.callback = callback;
            }
            observe(target) {
                if (target.hasAttribute("data-tagline")) {
                    window.startTagline = () => this.callback([{ target, isIntersecting: true }]);
                    return;
                }
                super.observe(target);
            }
        };
    });
    await page.goto("/");

    await expect.poll(() => page.evaluate(() => typeof window.startTagline)).toBe("function");
    await page.clock.install({ time: new Date("2026-01-01T00:00:00Z") });
    await page.clock.pauseAt(new Date("2026-01-01T00:00:10Z"));
    await page.evaluate(() => window.startTagline());
    await page.clock.runFor(1);
    await expect(page.locator("[data-tagline-word].is-lit")).toHaveCount(1);
    await page.clock.runFor(178);
    await expect(page.locator("[data-tagline-word].is-lit")).toHaveCount(1);
    await page.clock.runFor(1);
    await expect(page.locator("[data-tagline-word].is-lit")).toHaveCount(2);
    await page.emulateMedia({ reducedMotion: "reduce" });
    await expect(page.locator("[data-tagline-word]:not(.is-lit)")).toHaveCount(0);
    await page.emulateMedia({ reducedMotion: "no-preference" });
    await page.clock.runFor(700);
    await expect(page.locator("[data-tagline-word]").last()).toHaveCSS(
        "color",
        "rgb(176, 172, 184)",
    );
});

test("reduced motion during an active section entrance restores its final visible state", async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 1200 });
    await page.goto("/");

    const benefits = page.locator("#benefits");
    await expect
        .poll(() =>
            benefits.evaluate((section) => {
                const style = getComputedStyle(section);
                return Number(style.opacity) > 0 && Number(style.opacity) < 1;
            }),
        )
        .toBe(true);
    await page.emulateMedia({ reducedMotion: "reduce" });
    await expectVisibleAtRest(benefits);
    await expect(page.locator("#business")).toHaveCSS("opacity", "1");
    await page.emulateMedia({ reducedMotion: "no-preference" });
    await expect(benefits).toHaveCSS("opacity", "1");
    await expect(page.locator("#business")).toHaveCSS("opacity", "1");
});

test("short desktop hero grows naturally without negative margins", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 540 });
    await page.goto("/");

    const layout = await page.locator("#home").evaluate((hero) => {
        const rect = hero.getBoundingClientRect();
        return {
            top: rect.top,
            height: rect.height,
            headerBottom: document.querySelector("header").getBoundingClientRect().bottom,
            cardBottom: hero.querySelector("[data-thumbnail]").getBoundingClientRect().bottom,
        };
    });
    expect(layout.height).toBeGreaterThanOrEqual(540 - layout.top);
    expect(layout.top - layout.headerBottom).toBeCloseTo(40, 0);
    expect(layout.cardBottom).toBeLessThan(layout.top + layout.height);
});

test("hero occupies the first visible screen without clipping its content", async ({ page }) => {
    for (const [width, height] of [
        [375, 700],
        [768, 800],
        [1280, 900],
        [375, 540],
    ]) {
        await page.setViewportSize({ width, height });
        await page.goto("/");

        const layout = await page.evaluate(() => {
            const hero = document.querySelector("#home");
            const first = document.querySelector("#benefits");
            const header = document.querySelector("header").getBoundingClientRect();
            const bounds = hero.getBoundingClientRect();
            const children = [...hero.children].map((child) => child.getBoundingClientRect());
            return {
                heroTop: bounds.top,
                heroHeight: bounds.height,
                headerBottom: header.bottom,
                mainGap: parseFloat(getComputedStyle(document.querySelector("main")).paddingTop),
                heroBottom: bounds.bottom,
                benefitsTop: first.getBoundingClientRect().top,
                benefitsContentTop: first.firstElementChild.getBoundingClientRect().top,
                contentBottom: Math.max(...children.map((child) => child.bottom)),
                overflow: document.documentElement.scrollWidth > innerWidth,
            };
        });
        expect(layout.mainGap).toBeGreaterThanOrEqual(32);
        expect(layout.heroTop - layout.headerBottom).toBeGreaterThanOrEqual(0);
        expect(layout.benefitsTop).toBeGreaterThanOrEqual(layout.heroBottom);
        expect(layout.benefitsContentTop).toBeGreaterThanOrEqual(layout.benefitsTop);
        expect(layout.contentBottom).toBeLessThanOrEqual(layout.heroBottom);
        expect(layout.overflow).toBe(false);
    }
});

test("sections reveal once on entry and stay readable with motion disabled or no observer", async ({
    page,
    browser,
}) => {
    await page.setViewportSize({ width: 375, height: 700 });
    await page.goto("/");

    const hero = page.locator("#home");
    const closing = page.locator("#business");
    await expect(hero).toHaveCSS("opacity", "1");
    await expect(closing).toHaveCSS("opacity", "0");
    expect(await closing.evaluate((el) => new DOMMatrix(getComputedStyle(el).transform).m42)).toBe(
        24,
    );
    await closing.scrollIntoViewIfNeeded();
    await expectVisibleAtRest(closing);
    await expect(closing).toHaveCSS("transition-duration", "0.5s, 0.5s");
    await expect(closing).toHaveCSS("transition-timing-function", "ease-out, ease-out");
    await hero.scrollIntoViewIfNeeded();
    await expect(closing).toHaveCSS("opacity", "1");
    const steps = page.locator("#how-it-works");
    await steps.scrollIntoViewIfNeeded();
    await expect(steps).toHaveAttribute("data-reveal", "shown");
    await expect(steps.locator(".landing-info-card").nth(1)).toHaveCSS(
        "transition-delay",
        "0.15s, 0s, 0s",
    );
    await expect(steps.locator(".landing-info-card").nth(2)).toHaveCSS(
        "transition-delay",
        "0.3s, 0s, 0s",
    );
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.reload();
    await expect(closing).toHaveCSS("opacity", "1");
    await page.emulateMedia({ reducedMotion: "no-preference" });
    await expect(closing).toHaveCSS("opacity", "1");
    await page.reload();
    await expect(closing).toHaveCSS("opacity", "0");
    await page.emulateMedia({ reducedMotion: "reduce" });
    await expect(closing).toHaveCSS("opacity", "1");

    const withoutObserver = await browser.newContext({ viewport: { width: 375, height: 700 } });
    try {
        await withoutObserver.addInitScript(() => {
            window.IntersectionObserver = undefined;
        });
        const withoutObserverPage = await withoutObserver.newPage();
        await withoutObserverPage.goto("/");
        await expect(withoutObserverPage.locator("#business")).toHaveCSS("opacity", "1");
    } finally {
        await withoutObserver.close();
    }

    const fallback = await browser.newContext({
        javaScriptEnabled: false,
        viewport: { width: 375, height: 700 },
    });
    try {
        const fallbackPage = await fallback.newPage();
        await fallbackPage.goto("/");
        await expect(fallbackPage.locator("#business")).toHaveCSS("opacity", "1");
    } finally {
        await fallback.close();
    }
});

test("the emphasized benefit keeps on-dark ink across appearance preferences", async ({ page }) => {
    await page.goto("/");

    const card = page.locator("#benefits article").first();
    const heading = card.locator("h3");
    await expect(card).toHaveCSS("background-color", "rgb(61, 46, 85)");
    await expect(heading).toHaveCSS("color", "rgb(246, 245, 242)");
    await expect(card.locator("p")).toHaveCSS("color", "rgb(228, 214, 250)");
    await page.locator("html").evaluate((el) => el.classList.remove("dark"));
    await expect(heading).toHaveCSS("color", "rgb(246, 245, 242)");
    await expect(card.locator("p")).toHaveCSS("color", "rgb(228, 214, 250)");
});

test("shared palette and matching exterior gaps preserve public geometry", async ({ page }) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    for (const [width, height] of [
        [375, 900],
        [768, 900],
        [1280, 900],
        [1920, 1080],
        [1280, 540],
        [1280, 1600],
    ]) {
        await page.setViewportSize({ width, height });
        await page.goto("/");

        await waitForFonts(page);
        const geometry = await page.locator("main").evaluate((main) => {
            const style = getComputedStyle(main);
            const hero = document.querySelector("#home");
            const header = document.querySelector("header");
            const footer = document.querySelector("footer");
            const last = main.lastElementChild;
            const headerRowStyle = getComputedStyle(header.firstElementChild);
            const footerStyle = getComputedStyle(footer);
            const footerBox = footer.getBoundingClientRect();
            const lastBox = last.getBoundingClientRect();
            const headerBox = header.getBoundingClientRect();
            const heroBox = hero.getBoundingClientRect();

            return {
                headerPadding: [headerRowStyle.paddingTop, headerRowStyle.paddingBottom],
                footerPadding: [footerStyle.paddingTop, footerStyle.paddingBottom],
                padding: [
                    style.paddingTop,
                    style.paddingRight,
                    style.paddingBottom,
                    style.paddingLeft,
                ],
                footerGap: footerBox.top - lastBox.bottom,
                mainBottom: main.getBoundingClientRect().bottom,
                footerTop: footerBox.top,
                headerHeight: headerBox.height,
                heroHeight: heroBox.height,
                heroTopGap: heroBox.top - headerBox.bottom,
                heroBottomGap: parseFloat(getComputedStyle(hero).marginBottom),
            };
        });
        console.log("shared-palette-spacing", width, height, JSON.stringify(geometry));
        const lateral = width < 768 ? "24px" : "32px";
        expect(geometry.padding[0]).toBe(width < 768 ? "32px" : "40px");
        expect(geometry.padding[1]).toBe(lateral);
        expect(geometry.padding[3]).toBe(lateral);
        expect.soft(parseFloat(geometry.padding[2])).toBeCloseTo(geometry.heroTopGap, 0);
        expect.soft(geometry.footerGap).toBeCloseTo(geometry.heroTopGap, 0);
        expect(geometry.headerPadding).toEqual(["24px", "24px"]);
        expect(geometry.footerPadding).toEqual(geometry.headerPadding);
        expect(geometry.mainBottom).toBeCloseTo(geometry.footerTop, 0);
        expect(geometry.headerHeight).toBeCloseTo(width < 640 ? 87 : 91, 0);
        if (width === 768) expect(geometry.heroHeight).toBeCloseTo(918, 0);
        if (height === 540) expect(geometry.heroHeight).toBeCloseTo(486, 0);
        if (width >= 1280 && height >= 900) {
            expect(geometry.heroHeight).toBeCloseTo(704, 0);
            expect(geometry.heroTopGap).toBeCloseTo(height === 900 ? 52.5 : 80, 0);
            expect(geometry.heroBottomGap).toBeCloseTo(geometry.heroTopGap, 0);
        }
        expect
            .soft(
                await page
                    .locator("header")
                    .evaluate((header) => getComputedStyle(header).backgroundColor),
            )
            .toBe("rgb(36, 36, 36)");
        await expect(page.locator(".landing-world")).toHaveCSS(
            "background-color",
            "rgb(36, 36, 36)",
        );
        await expect(page.locator("#home")).toHaveCSS("background-color", "rgb(46, 46, 46)");
    }
    await page.goto("/login");

    await expect(page.locator("body")).toHaveCSS("background-color", "rgb(36, 36, 36)");
});

test("active auth panels consume approved roles despite saved appearance", async ({ browser }) => {
    for (const appearance of [null, "light", "system"]) {
        const context = await browser.newContext({
            viewport: { width: 375, height: 900 },
            colorScheme: "light",
        });
        try {
            await context.addInitScript((value) => {
                if (value === null) localStorage.removeItem("flux.appearance");
                else localStorage.setItem("flux.appearance", value);
            }, appearance);
            const page = await context.newPage();
            for (const route of ["/login", "/register"]) {
                await page.goto(route);

                await waitForFonts(page);
                const panel = page.locator("div.rounded-2xl").filter({ has: page.locator("form") });
                const colors = await panel.evaluate((panel) => {
                    const background = getComputedStyle(panel).backgroundColor;
                    const ink = getComputedStyle(panel.querySelector("h1")).color;
                    const luminance = (color) => {
                        const channels = color
                            .match(/[\d.]+/g)
                            .slice(0, 3)
                            .map(Number)
                            .map((channel) => {
                                const value = channel / 255;
                                return value <= 0.04045
                                    ? value / 12.92
                                    : ((value + 0.055) / 1.055) ** 2.4;
                            });
                        return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
                    };
                    const light = luminance(ink),
                        dark = luminance(background);
                    return {
                        canvas: getComputedStyle(document.body).backgroundColor,
                        panel: background,
                        accent: getComputedStyle(panel.querySelector('button[type="submit"]'))
                            .backgroundColor,
                        ink,
                        contrast: (Math.max(light, dark) + 0.05) / (Math.min(light, dark) + 0.05),
                    };
                });
                console.log("auth-backgrounds", appearance, route, JSON.stringify(colors));
                expect.soft(colors.canvas).toBe("rgb(36, 36, 36)");
                expect.soft(colors.panel).toBe("rgb(46, 46, 46)");
                expect.soft(colors.accent).toBe("rgb(167, 123, 255)");
                expect(colors.contrast).toBeGreaterThanOrEqual(4.5);
                await expect(page.locator("html")).toHaveClass(/\bdark\b/);
                const input = page.locator('input[name="email"]');
                await input.focus();
                await expect(input).toBeFocused();
                await expect(input).toBeEditable();
            }
        } finally {
            await context.close();
        }
    }
});

test("global application palette edits propagate to public and auth roles", async ({ page }) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto("/");

    await page.mouse.move(0, 0);
    const overrides = {
        "--color-app-canvas": "#202830",
        "--color-app-surface": "#34404C",
        "--color-app-emphasis": "#503860",
        "--color-app-accent": "#C090F0",
    };
    const roles = [
        [".landing-world, header, footer", "rgb(32, 40, 48)"],
        [
            "#home, #benefits article:last-child, #how-it-works li, #questions details, .landing-menu nav",
            "rgb(52, 64, 76)",
        ],
        ["#benefits article:first-child, #challenges", "rgb(80, 56, 96)"],
        ["[data-pass], #business, .landing-button-primary", "rgb(192, 144, 240)"],
    ];
    const applyOverrides = () => applyCssVariables(page, overrides);
    const resetOverrides = () => removeCssVariables(page, Object.keys(overrides));

    await applyOverrides();
    try {
        const surfaces = await page.evaluate((roles) => {
            const effectiveBackground = (element) => {
                const color = getComputedStyle(element).backgroundColor;
                return color === "rgba(0, 0, 0, 0)" && element.parentElement
                    ? effectiveBackground(element.parentElement)
                    : color;
            };
            return roles.map(([selector]) =>
                [...document.querySelectorAll(selector)].map(effectiveBackground),
            );
        }, roles);
        for (const [index, [, expected]] of roles.entries()) {
            expect(surfaces[index].length).toBeGreaterThan(0);
            for (const background of surfaces[index])
                expect.soft(background, roles[index][0]).toBe(expected);
        }
    } finally {
        await resetOverrides();
    }
    const defaults = await page.evaluate(
        (names) =>
            names.map((name) =>
                getComputedStyle(document.documentElement)
                    .getPropertyValue(name)
                    .trim()
                    .toUpperCase(),
            ),
        Object.keys(overrides),
    );
    expect.soft(defaults).toEqual(["#242424", "#2E2E2E", "#3D2E55", "#A77BFF"]);
    await expect(page.locator(".landing-world")).toHaveCSS("background-color", "rgb(36, 36, 36)");

    for (const route of ["/login", "/register"]) {
        await page.goto(route);

        await applyOverrides();
        try {
            const panel = page.locator("div.rounded-2xl").filter({ has: page.locator("form") });
            await expect(page.locator("body")).toHaveCSS("background-color", "rgb(32, 40, 48)");
            await expect(panel).toHaveCSS("background-color", "rgb(52, 64, 76)");
            await expect(panel.locator('button[type="submit"]')).toHaveCSS(
                "background-color",
                "rgb(192, 144, 240)",
            );
        } finally {
            await resetOverrides();
        }
    }
});

test("closing spacing survives scrolled resize and resets only with its layout owner", async ({
    browser,
}) => {
    for (const mode of ["normal", "no-resize-observer", "no-javascript"]) {
        const context = await browser.newContext({
            viewport: { width: 1280, height: 900 },
            javaScriptEnabled: mode !== "no-javascript",
            reducedMotion: "reduce",
        });
        try {
            if (mode === "no-resize-observer") {
                await context.addInitScript(() => {
                    window.ResizeObserver = undefined;
                });
            }
            const page = await context.newPage();
            await page.goto("/");

            await waitForFonts(page);
            const main = page.locator("main");
            await expect(main).toHaveCSS(
                "padding-bottom",
                mode === "no-javascript" ? "40px" : "52.5px",
            );
            if (mode !== "no-javascript") {
                await page.locator("#business").scrollIntoViewIfNeeded();
                await page.setViewportSize({ width: 1280, height: 1600 });
                await expect(main).toHaveCSS("padding-bottom", "80px");
                await main.evaluate((main) => main.replaceWith(main.cloneNode(true)));
                await expect(main).toHaveCSS("padding-bottom", "80px");
                const owned = await main.evaluate((main) =>
                    main.style.getPropertyValue("--landing-closing-gap"),
                );
                expect(owned).toBe("80px");
                await page.evaluate(() => document.dispatchEvent(new Event("livewire:navigating")));
                await expect(main).toHaveCSS("padding-bottom", "40px");
                expect(
                    await main.evaluate((main) =>
                        main.style.getPropertyValue("--landing-closing-gap"),
                    ),
                ).toBe("");
                await page.setViewportSize({ width: 375, height: 900 });
                await expect(main).toHaveCSS("padding-bottom", "32px");
            } else {
                await page.setViewportSize({ width: 375, height: 900 });
                await expect(main).toHaveCSS("padding-bottom", "32px");
                await expect(page.locator("#business")).toHaveCSS("opacity", "1");
            }
        } finally {
            await context.close();
        }
    }
});

test("public surface roles retain readable contextual ink across responsive viewports", async ({
    page,
}) => {
    // motion is covered separately; settled surfaces expose their actual reading context.
    await page.emulateMedia({ reducedMotion: "reduce" });
    const roles = [
        {
            role: "neutral benefit",
            selector: "#benefits article:last-child",
            expectedBackground: "rgb(46, 46, 46)",
            textSelectors: ["h3", "p"],
        },
        {
            role: "canvas",
            selector: ".landing-world",
            expectedBackground: "rgb(36, 36, 36)",
            textSelectors: [],
        },
        {
            role: "header",
            selector: "header",
            expectedBackground: "rgb(36, 36, 36)",
            textSelectors: ["nav a"],
        },
        {
            role: "footer",
            selector: "footer",
            expectedBackground: "rgb(36, 36, 36)",
            textSelectors: ["a"],
        },
        {
            role: "hero",
            selector: "#home",
            expectedBackground: "rgb(46, 46, 46)",
            textSelectors: ["h1", "p[data-flux-text]"],
        },
        {
            role: "emphasized benefit",
            selector: "#benefits article:first-child",
            expectedBackground: "rgb(61, 46, 85)",
            textSelectors: ["h3", "p"],
        },
        {
            role: "steps",
            selector: "#how-it-works li",
            expectedBackground: "rgb(46, 46, 46)",
            textSelectors: ["span", "h3", "p"],
        },
        {
            role: "faq",
            selector: "#questions details",
            expectedBackground: "rgb(46, 46, 46)",
            textSelectors: ["summary", "p"],
        },
        {
            role: "promotion",
            selector: "#challenges",
            expectedBackground: "rgb(61, 46, 85)",
            textSelectors: ["h2", "p"],
        },
        {
            role: "business cta",
            selector: "#business",
            expectedBackground: "rgb(167, 123, 255)",
            textSelectors: ["h2", "p"],
        },
        {
            role: "sample pass",
            selector: "[data-pass]",
            expectedBackground: "rgb(167, 123, 255)",
            textSelectors: ["h2", "h3", "p"],
        },
        {
            role: "menu",
            selector: ".landing-menu nav",
            expectedBackground: "rgb(46, 46, 46)",
            textSelectors: ["a"],
        },
    ];

    for (const [width, height] of [
        ...VIEWPORT_WIDTHS.map((width) => [width, 900]),
        [375, 540],
        [1280, 540],
        [1280, 1600],
    ]) {
        await page.setViewportSize({ width, height });
        await page.goto("/");

        if (width < 1280) {
            await page.locator(".landing-menu summary").click();
        }
        await page
            .locator("#questions details")
            .first()
            .evaluate((el) => {
                el.open = true;
            });

        const matrix = await page.evaluate((roles) => {
            const channels = (color) => color.match(/[\d.]+/g).map(Number);

            // composite transparent ancestors; the pass's white sheen only improves dark-ink contrast.
            const effectiveBackground = (element) => {
                if (!element) {
                    return [255, 255, 255];
                }

                const [red, green, blue, alpha = 1] = channels(
                    getComputedStyle(element).backgroundColor,
                );
                if (alpha === 1) {
                    return [red, green, blue];
                }

                const ancestorBackground = effectiveBackground(element.parentElement);

                if (alpha === 0) {
                    return ancestorBackground;
                }

                return [red, green, blue].map(
                    (channel, index) => channel * alpha + ancestorBackground[index] * (1 - alpha),
                );
            };

            const luminance = (rgb) => {
                const linear = rgb.map((channel) => {
                    const normalized = channel / 255;
                    return normalized <= 0.04045
                        ? normalized / 12.92
                        : ((normalized + 0.055) / 1.055) ** 2.4;
                });
                return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
            };

            const surfaces = [];

            for (const { role, selector, expectedBackground, textSelectors } of roles) {
                for (const element of document.querySelectorAll(selector)) {
                    const surfaceBackground = effectiveBackground(element);
                    const background = `rgb(${surfaceBackground.map(Math.round).join(", ")})`;
                    const textMeasurements = [];

                    for (const textSelector of textSelectors) {
                        for (const textElement of element.querySelectorAll(textSelector)) {
                            const color = getComputedStyle(textElement).color;
                            const [red, green, blue, alpha = 1] = channels(color);
                            const textBackground = effectiveBackground(textElement);
                            const ink = [red, green, blue].map(
                                (channel, index) =>
                                    channel * alpha + textBackground[index] * (1 - alpha),
                            );

                            const inkLuminance = luminance(ink);
                            const backgroundLuminance = luminance(textBackground);
                            const contrast =
                                (Math.max(inkLuminance, backgroundLuminance) + 0.05) /
                                (Math.min(inkLuminance, backgroundLuminance) + 0.05);

                            textMeasurements.push({ color, contrast });
                        }
                    }

                    surfaces.push({
                        role,
                        expectedBackground,
                        background,
                        text: textMeasurements,
                    });
                }
            }

            return surfaces;
        }, roles);
        console.log(
            "public-surface-matrix",
            width,
            height,
            JSON.stringify(
                matrix.map((surface) => ({
                    role: surface.role,
                    background: surface.background,
                    inks: [...new Set(surface.text.map((text) => text.color))],
                    minimumContrast: surface.text.length
                        ? Math.min(...surface.text.map((text) => text.contrast))
                        : null,
                })),
            ),
        );
        expect([...new Set(matrix.map((surface) => surface.role))]).toEqual(
            roles.map(({ role }) => role),
        );
        for (const surface of matrix) {
            if (surface.role !== "canvas") {
                expect(surface.text.length, surface.role).toBeGreaterThan(0);
            }
            expect(surface.background, `${width}x${height} ${surface.role}`).toBe(
                surface.expectedBackground,
            );
            for (const text of surface.text) {
                expect(
                    text.contrast,
                    `${width}x${height} ${surface.role} ${text.color}`,
                ).toBeGreaterThanOrEqual(4.5);
            }
            if (["business cta", "sample pass"].includes(surface.role)) {
                for (const text of surface.text) {
                    expect(text.color, surface.role).toBe("rgb(23, 19, 31)");
                }
            }
        }
    }
});

test("confirmed mechanics priority keeps on-dark heading body and example ink", async ({
    page,
}) => {
    await page.goto("/");

    const mechanics = page.locator("#challenges");

    await expect(mechanics).toHaveCSS("background-color", "rgb(61, 46, 85)");
    await expect(mechanics.locator("h2")).toHaveCSS("color", "rgb(246, 245, 242)");
    for (const paragraph of await mechanics.locator("p:not(:first-child)").all()) {
        await expect(paragraph).toHaveCSS("color", "rgb(228, 214, 250)");
    }
});

test("mechanics eyebrow uses contrast-safe accent text without changing typography", async ({
    page,
}) => {
    const fontSizes = [];

    for (const width of [375, 768, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto("/");
        await waitForFonts(page);

        const eyebrow = page.locator("#challenges > p:first-child");
        const measurement = await eyebrow.evaluate((element) => {
            const panel = element.closest("#challenges");
            const style = getComputedStyle(element);
            const foreground = style.color;
            const background = getComputedStyle(panel).backgroundColor;
            const luminance = (color) => {
                const channels = color
                    .match(/[\d.]+/g)
                    .slice(0, 3)
                    .map(Number);
                const linear = channels.map((channel) => {
                    const normalized = channel / 255;
                    return normalized <= 0.04045
                        ? normalized / 12.92
                        : ((normalized + 0.055) / 1.055) ** 2.4;
                });
                return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
            };
            const foregroundLuminance = luminance(foreground);
            const backgroundLuminance = luminance(background);

            return {
                foreground,
                background,
                fontSize: style.fontSize,
                fontWeight: style.fontWeight,
                fontFamily: style.fontFamily,
                fontStatus: document.fonts.status,
                contrast:
                    (Math.max(foregroundLuminance, backgroundLuminance) + 0.05) /
                    (Math.min(foregroundLuminance, backgroundLuminance) + 0.05),
            };
        });

        fontSizes.push(measurement.fontSize);
        console.log("mechanics-eyebrow-contrast", width, JSON.stringify(measurement));
        expect(measurement.foreground, `${width}px eyebrow color`).toBe("rgb(205, 176, 255)");
        expect(measurement.fontWeight, `${width}px eyebrow weight`).toBe("600");
        expect(
            measurement.contrast,
            `${width}px ${measurement.foreground} on ${measurement.background}`,
        ).toBeGreaterThanOrEqual(4.5);
    }

    expect(new Set(fontSizes).size, "eyebrow font size across responsive widths").toBe(1);
});

test("confirmed panel boundaries and separators retain neutral and priority roles", async ({
    page,
}) => {
    await page.goto("/");

    for (const [selector, color] of [
        [
            "#home, #benefits article:last-child, #how-it-works li, #questions details",
            "rgb(82, 82, 82)",
        ],
        ["#benefits article:first-child, #challenges", "rgb(132, 101, 172)"],
    ]) {
        for (const panel of await page.locator(selector).all()) {
            await expect(panel).toHaveCSS("border-top-style", "solid");
            await expect(panel).toHaveCSS("border-top-width", "1px");
            await expect(panel).toHaveCSS("border-top-color", color);
        }
    }
    for (const [selector, color] of [
        ["#challenges > p:last-child", "rgb(132, 101, 172)"],
        ["footer", "rgb(72, 72, 72)"],
    ]) {
        await expect(page.locator(selector)).toHaveCSS("border-top-width", "1px");
        await expect(page.locator(selector)).toHaveCSS("border-top-style", "solid");
        await expect(page.locator(selector)).toHaveCSS("border-top-color", color);
    }
});

test("confirmed editorial hierarchy persists after entrance and every visibility fallback", async ({
    page,
    browser,
}) => {
    const expectHierarchy = async (current) => {
        const words = current.locator("[data-tagline-word]");
        await expect(words).toHaveText([
            "Cada",
            "visita",
            "acerca",
            "a",
            "tu",
            "cliente",
            "a",
            "una",
            "recompensa.",
            "El",
            "vínculo",
            "con",
            "tu",
            "negocio",
            "permanece.",
        ]);
        await expect(words.first()).toHaveCSS("color", "rgb(246, 245, 242)");
        await expect(words.filter({ hasText: /^recompensa\.$/ })).toHaveCSS(
            "color",
            "rgb(167, 123, 255)",
        );
        await expect(words.last()).toHaveCSS("color", "rgb(176, 172, 184)");
    };
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto("/");

    await page.locator("[data-tagline]").scrollIntoViewIfNeeded();
    await expect(page.locator("[data-tagline-word]:not(.is-lit)")).toHaveCount(0);
    await expectHierarchy(page);
    await expect(page.locator("[data-tagline]")).toHaveCSS("font-size", "32px");
    await expect(page.locator("[data-tagline]")).toHaveCSS("max-width", "768px");
    await page.evaluate(() => document.dispatchEvent(new Event("livewire:navigating")));
    await expectHierarchy(page);
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.reload();
    await expectHierarchy(page);

    for (const mode of ["no-js", "no-observer"]) {
        const context = await browser.newContext({
            viewport: { width: 375, height: 812 },
            javaScriptEnabled: mode !== "no-js",
        });
        try {
            if (mode === "no-observer") {
                await context.addInitScript(() => {
                    window.IntersectionObserver = undefined;
                });
            }
            const fallback = await context.newPage();
            await fallback.goto("/");
            await expectHierarchy(fallback);
            expect(
                await fallback.evaluate(() => document.documentElement.scrollWidth > innerWidth),
            ).toBe(false);
        } finally {
            await context.close();
        }
    }
});

test("confirmed FAQ has a right decorative chevron with native keyboard toggle and focus", async ({
    page,
}) => {
    await page.goto("/");

    const details = page.locator("#questions details").first();
    const summary = details.locator("summary");
    const icon = summary.locator("svg");

    await expect(icon).toHaveCount(1);
    await expect(icon).toHaveAttribute("aria-hidden", "true");
    await expect(summary).toHaveText("¿Qué es una Promoción de puntos?");
    await expect(summary).toHaveCSS("list-style-type", "none");
    await expect(icon).toHaveCSS("transform", "none");
    const boxes = await summary.evaluate((element) => {
        const summaryBox = element.getBoundingClientRect();
        const textBox = element.querySelector("span").getBoundingClientRect();
        const iconBox = element.querySelector("svg").getBoundingClientRect();

        return {
            text: textBox.right,
            icon: iconBox.left,
            right: summaryBox.right,
            iconRight: iconBox.right,
        };
    });
    expect(boxes.icon).toBeGreaterThan(boxes.text);
    expect(boxes.iconRight).toBeCloseTo(boxes.right, 0);
    await summary.focus();
    await expect(summary).toBeFocused();
    await expect(summary).toHaveCSS("outline-style", "solid");
    await page.keyboard.press("Enter");
    await expect(details).toHaveAttribute("open", "");
    await expect(icon).toHaveCSS("transform", "matrix(-1, 0, 0, -1, 0, 0)");
    await page.keyboard.press("Space");
    await expect(details).not.toHaveAttribute("open", "");
    await expect(icon).toHaveCSS("transform", "none");
    await page.emulateMedia({ reducedMotion: "reduce" });
    await expect(icon).toHaveCSS("transition-duration", "0s");
});

test("native Flux primitives preserve the public semantic hierarchy and real account links", async ({
    page,
}) => {
    await page.goto("/");

    for (const [selector, level] of [
        ["#hero-title", "H1"],
        ["#benefits-title", "H2"],
        ["#benefits article h3", "H3"],
    ]) {
        const heading = page.locator(selector).first();
        await expect(heading).toHaveAttribute("data-flux-heading", "");
        expect(await heading.evaluate((el) => el.tagName)).toBe(level);
    }
    await expect(page.locator("#home > div:first-child > p")).toHaveCount(2);
    const actions = page.getByRole("link", { name: "Crear mi cuenta" });
    await expect(actions).toHaveCount(2);
    for (const action of await actions.all()) {
        await expect(action).toHaveAttribute("data-flux-button", "data-flux-button");
        await expect(action).toHaveAttribute("href", /\/register$/);
    }
});

test("public palette, focus and responsive hero geometry follow the landing roles", async ({
    page,
}) => {
    for (const width of VIEWPORT_WIDTHS) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto("/");

        const hero = page.locator("#home");
        const primary = hero.locator("a.landing-button");
        const sample = page.locator("[data-pass]");
        await expect(hero).toHaveCSS("background-color", "rgb(46, 46, 46)");
        await expect(page.locator(".landing-world")).toHaveCSS(
            "background-color",
            "rgb(36, 36, 36)",
        );
        await expect(primary).toHaveCSS("background-color", "rgb(167, 123, 255)");
        await expect(primary).toHaveCSS("color", "rgb(23, 19, 31)");
        await expect(sample).toHaveCSS("background-color", "rgb(167, 123, 255)");
        await primary.focus();
        expect(await primary.evaluate((el) => getComputedStyle(el).outlineColor)).toBe(
            "rgb(205, 176, 255)",
        );
        expect((await primary.boundingBox()).height).toBeGreaterThanOrEqual(44);
        await primary.hover();
        await expect(primary).toHaveCSS("background-color", "rgb(184, 147, 255)");
        await page.mouse.down();
        await expect(primary).toHaveCSS("background-color", "rgb(149, 102, 235)");
        await page.mouse.move(0, 0);
        await page.mouse.up();
        if (width === 1280) {
            const navLink = page.locator("header > div > nav a").first();
            await navLink.hover();
            await expect(navLink).toHaveCSS("color", "rgb(205, 176, 255)");
        }
        const geometry = await hero.evaluate((el) => {
            const heroBox = el.getBoundingClientRect();
            const copy = el.firstElementChild.getBoundingClientRect();
            const pass = el.lastElementChild.getBoundingClientRect();
            return {
                width: heroBox.width,
                gap: pass.left - copy.right,
                columns: [copy.width, pass.width],
                overflow: document.documentElement.scrollWidth > innerWidth,
            };
        });
        console.log("landing-computed-grid", width, JSON.stringify(geometry));
        expect(geometry.width).toBeLessThanOrEqual(1088);
        expect(geometry.overflow).toBe(false);
        if (width === 1280) {
            expect(geometry.gap).toBeCloseTo(48, 0);
            expect(geometry.columns[1] / geometry.columns[0]).toBeCloseTo(1.3 / 0.88, 1);
        }
    }
});

test("sample pass and landing benefit use the Flux outline icon pack", async ({ page }) => {
    await page.goto("/");

    const promotionIcon = page.locator(
        "[data-pass] svg.app-pass-preview-promotion-icon[data-flux-icon]",
    );
    await expect(promotionIcon).toHaveAttribute("aria-hidden", "true");
    await expect(promotionIcon).toBeVisible();
    await expect(promotionIcon).toHaveAttribute("viewBox", "0 0 24 24");
    await expect(promotionIcon).toHaveAttribute("stroke-width", "1.5");
    await expect(promotionIcon).toHaveAttribute("fill", "none");
    await expect(promotionIcon.locator("path").first()).toHaveAttribute("d", /^M9\.568 3H5\.25/);

    const benefitIcons = page.locator(".landing-info-card svg[data-flux-icon]");
    await expect(benefitIcons).toHaveCount(2);
    const expectedPaths = [
        "M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3",
        "M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941",
    ];
    for (const [index, benefitIcon] of (await benefitIcons.all()).entries()) {
        await expect(benefitIcon).toBeVisible();
        await expect(benefitIcon).toHaveAttribute("viewBox", "0 0 24 24");
        await expect(benefitIcon).toHaveCSS("width", "64px");
        await expect(benefitIcon).toHaveCSS("height", "64px");
        await expect(benefitIcon.locator("path")).toHaveAttribute("d", expectedPaths[index]);
    }
});

test("desktop navigation follows the visible section order without horizontal overflow", async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto("/");

    await expectOrderedNavigation(page.locator("header > div > nav"));
    await expectNoHorizontalOverflow(page);
    await page.locator('header > div > nav a[href="#benefits"]').click();
    await expect(page).toHaveURL(/#benefits$/);
    await expect
        .poll(() => page.locator("#benefits").evaluate((el) => el.getBoundingClientRect().top))
        .toBeGreaterThanOrEqual(0);
    await expect
        .poll(() => page.locator("#benefits").evaluate((el) => el.getBoundingClientRect().top))
        .toBeLessThan(40);
});

test("public landing scales the approved sample and keeps every section reachable", async ({
    page,
}, testInfo) => {
    test.setTimeout(60000);
    await page.emulateMedia({ reducedMotion: "no-preference" });
    for (const width of [320, ...VIEWPORT_WIDTHS, 1920]) {
        await page.setViewportSize({ width, height: width === 1920 ? 1080 : 900 });
        await page.goto("/");

        await waitForFonts(page);
        await expect(
            page.getByRole("heading", {
                level: 1,
                name: "Dale a tus clientes una razón para volver.",
            }),
        ).toBeVisible();
        await expect(page.locator("html")).toHaveClass(/\bdark\b/);
        await expect(page.getByRole("main")).toHaveCount(1);
        await expect(page.getByRole("contentinfo")).toHaveCount(1);
        const pass = page.locator("[data-pass]");
        await expect(pass).toHaveAttribute("aria-label", /Pase de ejemplo/);
        await expect(page).toHaveTitle(
            "FidelitoPass - Dale a tus clientes una razón para volver. - FidelitoPass",
        );
        await expect(page.locator('meta[name="description"]')).toHaveAttribute(
            "content",
            /Crea Promociones de puntos/,
        );
        await expect(pass).toContainText("9 / 15 puntos");
        await expect(pass).toContainText("2 puntos");
        await expect(pass).toContainText("Un consumo de cortesía");
        await expect(pass).not.toContainText("Hamburguesa gratis");
        await expect(pass.locator(".app-pass-preview-header")).toBeVisible();
        await expect(pass.locator(".app-pass-preview-content")).toBeVisible();
        await expect(pass.locator(".app-pass-preview-reward")).toBeVisible();
        await expect(pass.locator(".app-pass-preview-qr")).toBeVisible();
        await expect(pass).toHaveClass(/app-pass-preview--with-qr/);
        await expect(pass).toContainText("30 SEP");
        await expect(pass).toContainText("48273");
        await expect(
            page.getByText(
                "Ejemplo: 1 punto por visita, 2 los martes. Al llegar a 15 puntos antes del plazo, tu cliente obtiene un consumo de cortesía.",
            ),
        ).toBeVisible();
        await expect(page.locator('header nav a[href="#pass"]').first()).toHaveAttribute(
            "href",
            "#pass",
        );
        await expect(page.getByRole("img", { name: "QR de ejemplo" })).toBeVisible();

        const stepNumbers = page.locator("#how-it-works ol > li > span");
        await expect(stepNumbers).toHaveText(["01", "02", "03"]);
        for (const stepNumber of await stepNumbers.all()) {
            await expect(stepNumber).toHaveCSS("color", "rgb(167, 123, 255)");
        }
        await page.locator("[data-tagline]").scrollIntoViewIfNeeded();
        await expect(page.locator("[data-tagline-word]:not(.is-lit)")).toHaveCount(0);
        const taglineWords = page.locator("[data-tagline-word]");
        await expect(taglineWords.first()).toHaveCSS("color", "rgb(246, 245, 242)");
        await expect(taglineWords.filter({ hasText: /^recompensa\.$/ })).toHaveCSS(
            "color",
            "rgb(167, 123, 255)",
        );
        await expect(taglineWords.last()).toHaveCSS("color", "rgb(176, 172, 184)");

        await expect(page.locator("main")).not.toContainText(
            /landing\.[a-z_]+|tarjeta Wallet|Apple Wallet|tarjeta de sellos/,
        );

        const geometry = await page.evaluate(() => {
            const box = (selector) => {
                const r = document.querySelector(selector).getBoundingClientRect();
                return {
                    x: r.x,
                    y: r.y,
                    width: r.width,
                    height: r.height,
                    right: r.right,
                    bottom: r.bottom,
                };
            };
            const thumbnail = document.querySelector("[data-thumbnail]");
            const pass = document.querySelector("[data-pass]");
            const thumbnailStyle = getComputedStyle(thumbnail);
            const passStyle = getComputedStyle(pass);
            const passBox = box("[data-pass]");
            const renderedScale = passBox.width / pass.offsetWidth;

            return {
                thumb: box("[data-thumbnail]"),
                pass: passBox,
                qr: box(".landing-pass-qr"),
                code: box(".app-pass-preview-manual-code"),
                thumbnailWidth: thumbnail.clientWidth,
                scale: thumbnailStyle.getPropertyValue("--landing-scale"),
                containerType: thumbnailStyle.containerType,
                containerName: thumbnailStyle.containerName,
                passOffsetWidth: pass.offsetWidth,
                passPadding: passStyle.paddingLeft,
                renderedPadding: {
                    left: parseFloat(passStyle.paddingLeft) * renderedScale,
                    right: parseFloat(passStyle.paddingRight) * renderedScale,
                },
                cards: [...document.querySelectorAll("#benefits article")].map((e) => {
                    const r = e.getBoundingClientRect();
                    return { x: r.x, y: r.y, width: r.width };
                }),
                overflow: document.documentElement.scrollWidth > innerWidth,
            };
        });
        console.log("landing-card-padding", width, JSON.stringify(geometry));

        expect(geometry.pass.width).toBeCloseTo(geometry.thumb.width, 0);
        expect(geometry.pass.height).toBeCloseTo(geometry.thumb.height, 0);
        expect(geometry.pass.width / geometry.pass.height).toBeCloseTo(1.5, 2);
        expect(geometry.qr.x).toBeGreaterThan(geometry.pass.x + geometry.pass.width / 2);
        expect(geometry.code.y).toBeGreaterThan(geometry.qr.y);
        expect(geometry.code.bottom).toBeLessThanOrEqual(geometry.pass.bottom);

        if (width <= 375) {
            expect(geometry.renderedPadding.left).toBeGreaterThanOrEqual(15.99);
            expect(geometry.renderedPadding.right).toBeGreaterThanOrEqual(15.99);
        } else {
            expect(geometry.passPadding).toBe("24px");
        }

        expect(geometry.overflow).toBe(false);

        const layout = await page.evaluate(() => {
            const thumbnailBox = document.querySelector("[data-thumbnail]").getBoundingClientRect();
            const copyBox = document.querySelector("#home > div").getBoundingClientRect();

            return {
                headerBottom: document.querySelector("header").getBoundingClientRect().bottom,
                heroTop: document.querySelector("#home").getBoundingClientRect().top,
                passCenter: thumbnailBox.top + thumbnailBox.height / 2,
                copyCenter: copyBox.top + copyBox.height / 2,
            };
        });
        expect(layout.heroTop - layout.headerBottom).toBeGreaterThanOrEqual(0);
        if (width === 1280)
            expect(Math.abs(layout.passCenter - layout.copyCenter)).toBeLessThan(60);

        expect(geometry.cards[0].width).toBeCloseTo(geometry.cards[1].width, 0);
        if (width >= 768) expect(geometry.cards[0].y).toBeCloseTo(geometry.cards[1].y, 0);
        else expect(geometry.cards[1].y).toBeGreaterThan(geometry.cards[0].y);

        for (const id of [
            "home",
            "how-it-works",
            "challenges",
            "pass",
            "benefits",
            "questions",
            "business",
        ])
            await expect(page.locator(`#${id}`)).toHaveCount(1);
        await expect(page.locator("#questions details")).toHaveCount(6);

        for (const asset of ["logo-header.webp", "logo_icon.svg", "preview-pass-qr.svg"])
            expect((await page.request.get(`/${asset}`)).ok()).toBe(true);

        for (const asset of ["icons/target.svg", "benefit-challenges.svg", "benefit-points.svg"])
            expect((await page.request.get(`/${asset}`)).status()).toBe(404);

        for (const section of await page.locator("main > section:not(#home)").all()) {
            await section.scrollIntoViewIfNeeded();
            await expect(section).toHaveCSS("opacity", "1");
            await expect(section).toHaveCSS("transform", "none");
        }

        await expect(page.locator("[data-tagline-word]:not(.is-lit)")).toHaveCount(0);
        await expect(page.locator("[data-tagline-word]").last()).toHaveCSS(
            "color",
            "rgb(176, 172, 184)",
        );
        await expect(
            page.locator("[data-tagline-word]").filter({ hasText: /^recompensa\.$/ }),
        ).toHaveCSS("color", "rgb(167, 123, 255)");

        await page.mouse.move(0, 0);
        await pass.dispatchEvent("pointerleave");
        await expect(pass).toHaveCSS("transform", "none");
        await page.evaluate(() => window.scrollTo({ top: 0, behavior: "instant" }));
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
        console.log(
            "stable-app-capture",
            JSON.stringify(
                await page.evaluate(() => ({
                    viewport: [innerWidth, innerHeight],
                    deviceScaleFactor: devicePixelRatio,
                    reducedMotion: matchMedia("(prefers-reduced-motion: reduce)").matches,
                    fonts: document.fonts.status,
                    onest: document.fonts.check('600 36px "Onest Variable"'),
                    stylesheets: [...document.querySelectorAll('link[rel="stylesheet"]')].map(
                        (link) => link.href,
                    ),
                })),
            ),
        );
        await page.screenshot({ path: testInfo.outputPath(`public-${width}.png`), fullPage: true });
    }
});

test("mobile navigation, FAQ, skip link and authentication routes work", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto("/");

    await page.keyboard.press("Tab");
    await expect(page.getByRole("link", { name: "Ir al contenido" })).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/#content$/);
    await expect(page.locator("main")).toBeFocused();
    await expect
        .poll(() => page.locator("main").evaluate((el) => el.getBoundingClientRect().top))
        .toBeGreaterThanOrEqual(-1);
    await page.locator(".landing-menu summary").click();
    const nav = page.locator(".landing-menu nav");
    await expectOrderedNavigation(nav);
    await nav.locator('a[href="#challenges"]').click();
    await expect(page).toHaveURL(/#challenges$/);
    await expect
        .poll(() => page.locator("#challenges").evaluate((el) => el.getBoundingClientRect().top))
        .toBeGreaterThanOrEqual(0);
    await expect
        .poll(() => page.locator("#challenges").evaluate((el) => el.getBoundingClientRect().top))
        .toBeLessThan(40);
    await expect(page.locator(".landing-menu")).not.toHaveAttribute("open", "");
    await page.locator("#questions summary").first().click();
    await expect(page.locator("#questions details").first()).toHaveAttribute("open", "");
    await expect(page.locator('a[href$="/register"]').first()).toHaveAttribute("href", /register$/);
    await page.getByRole("link", { name: "Inicia sesión" }).click();
    await expect(page).toHaveURL(/\/login$/);
});

test("footer and header return to the absolute page top while the hero remains navigable", async ({
    page,
}) => {
    for (const [width, height, linkSelector] of [
        [375, 812, 'footer a[href="#page-top"]'],
        [1280, 900, 'header a[href="#page-top"]'],
    ]) {
        await page.setViewportSize({ width, height });
        await page.goto("/");

        await expect(page.locator("#page-top")).toHaveCSS("scroll-margin-top", "0px");
        await expect(page.locator("#home")).toHaveCount(1);
        await page.locator("#home").evaluate((el) => el.scrollIntoView({ behavior: "instant" }));
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
        await page.locator(linkSelector).click();
        await expect(page).toHaveURL(/#page-top$/);
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
    }

    await page.locator("#home").scrollIntoViewIfNeeded();
    await expect(page.locator("#home")).toHaveAttribute("id", "home");
});

test("landing footer groups links into two rows on mobile and one row from sm", async ({
    page,
}) => {
    const selectors = {
        footer: "footer",
        logo: 'footer img[src*="logo-header.webp"]',
        register: 'footer a[href$="/register"]',
        login: 'footer a[href$="/login"]',
        back: 'footer a[href="#page-top"]',
    };

    for (const width of [320, 375, 640, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto("/");
        await page.locator(selectors.footer).scrollIntoViewIfNeeded();

        const layout = await page.evaluate((items) => {
            const rect = (selector) => {
                const { x, y, width, height, right } = document
                    .querySelector(selector)
                    .getBoundingClientRect();

                return { x, y, width, height, right, centerY: y + height / 2 };
            };
            const textRect = (selector) => {
                const range = document.createRange();
                range.selectNodeContents(document.querySelector(selector));

                const { x, right } = range.getBoundingClientRect();

                return { x, right };
            };
            const footer = document.querySelector(items.footer);
            const style = getComputedStyle(footer);

            return {
                paddingInline: [style.paddingLeft, style.paddingRight],
                rowGap: style.rowGap,
                columnGap: style.columnGap,
                display: style.display,
                footer: rect(items.footer),
                logo: rect(items.logo),
                register: rect(items.register),
                login: rect(items.login),
                back: rect(items.back),
                registerText: textRect(items.register),
                loginText: textRect(items.login),
                backText: textRect(items.back),
                linkFontSizes: Array.from(
                    footer.querySelectorAll("a"),
                    (link) => getComputedStyle(link).fontSize,
                ),
                documentOverflow: document.documentElement.scrollWidth > innerWidth,
            };
        }, selectors);

        const expectedPadding = width < 768 ? "24px" : "32px";
        expect(layout.paddingInline).toEqual([expectedPadding, expectedPadding]);
        expect(layout.logo.width).toBe(128);
        expect(layout.linkFontSizes).toEqual(["14px", "14px", "14px"]);

        for (const name of ["register", "login", "back"]) {
            expect(layout[name].height, `${name} target at ${width}px`).toBeGreaterThanOrEqual(44);
        }

        expect(layout.documentOverflow).toBe(false);

        if (width < 640) {
            expect(layout.display).toBe("grid");
            expect(layout.rowGap).toBe("8px");
            expect(layout.columnGap).toBe("16px");
            expect(Math.abs(layout.logo.centerY - layout.back.centerY)).toBeLessThanOrEqual(1);
            expect(Math.abs(layout.register.centerY - layout.login.centerY)).toBeLessThanOrEqual(1);
            expect(layout.register.centerY - layout.logo.centerY).toBeGreaterThan(44);
            expect(Math.abs(layout.logo.x - layout.register.x)).toBeLessThanOrEqual(1);
            expect(Math.abs(layout.login.right - layout.back.right)).toBeLessThanOrEqual(1);
            expect(Math.abs(layout.registerText.x - layout.register.x)).toBeLessThanOrEqual(1);
            expect(Math.abs(layout.loginText.right - layout.login.right)).toBeLessThanOrEqual(1);
            expect(Math.abs(layout.backText.right - layout.back.right)).toBeLessThanOrEqual(1);
        } else {
            expect(layout.display).toBe("flex");
            const rowCenters = [layout.logo, layout.register, layout.login, layout.back].map(
                ({ centerY }) => centerY,
            );
            expect(Math.max(...rowCenters) - Math.min(...rowCenters)).toBeLessThanOrEqual(1);
            expect(layout.logo.x).toBeLessThan(layout.register.x);
            expect(layout.register.x).toBeLessThan(layout.login.x);
            expect(layout.login.x).toBeLessThan(layout.back.x);
        }
    }
});

test("registration CTAs retain readable hover contrast and keyboard focus", async ({ page }) => {
    await page.goto("/");

    await expect(page.getByRole("link", { name: "Crear mi cuenta" })).toHaveCount(2);

    const contrastRatio = ({ foreground, background }) => {
        const luminance = (value) => {
            const channels = value
                .match(/[\d.]+/g)
                .slice(0, 3)
                .map(Number)
                .map((channel) => {
                    const normalized = channel / 255;
                    return normalized <= 0.04045
                        ? normalized / 12.92
                        : ((normalized + 0.055) / 1.055) ** 2.4;
                });
            return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
        };
        const foregroundLuminance = luminance(foreground);
        const backgroundLuminance = luminance(background);
        return (
            (Math.max(foregroundLuminance, backgroundLuminance) + 0.05) /
            (Math.min(foregroundLuminance, backgroundLuminance) + 0.05)
        );
    };

    for (const selector of ["#home a.landing-button", "#business a.landing-button"]) {
        const link = page.locator(selector);
        await link.hover();
        await expect
            .poll(
                async () => {
                    const colors = await link.evaluate((element) => {
                        const style = getComputedStyle(element);

                        return {
                            foreground: style.color,
                            background: style.backgroundColor,
                            transitioning: element
                                .getAnimations()
                                .some((animation) => animation.playState === "running"),
                        };
                    });

                    // initial colors can also pass contrast; wait for the observed hover transition to finish.
                    if (colors.transitioning) return 0;
                    return contrastRatio(colors);
                },
                { message: selector },
            )
            .toBeGreaterThanOrEqual(4.5);

        await link.focus();
        await expect(link).toBeFocused();
        expect(await link.evaluate((el) => getComputedStyle(el).outlineStyle)).not.toBe("none");
    }
});

test("informational cards lift only for fine pointers without reduced motion", async ({
    page,
}, testInfo) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto("/");

    const cards = page.locator("#benefits article, #how-it-works li.landing-info-card");
    await expect(cards).toHaveCount(5);

    for (const card of [cards.first(), cards.nth(2)]) {
        await card.hover();
        await expect
            .poll(() => card.evaluate((el) => new DOMMatrix(getComputedStyle(el).transform).m42))
            .toBeLessThan(-1);
        const displacement = await card.evaluate(
            (el) => new DOMMatrix(getComputedStyle(el).transform).m42,
        );
        expect(displacement).toBeGreaterThanOrEqual(-4.1);
        await page.mouse.move(0, 0);
        await expect(card).toHaveCSS("transform", "none");
    }

    await cards.nth(2).hover();
    await page.screenshot({ path: testInfo.outputPath("landing-step-hover.png"), fullPage: true });

    const touch = await page
        .context()
        .browser()
        .newContext({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });
    try {
        const touchPage = await touch.newPage();
        await touchPage.goto("/");
        await touchPage.locator("#benefits article").first().dispatchEvent("mouseover");
        await expect(touchPage.locator("#benefits article").first()).toHaveCSS("transform", "none");
    } finally {
        await touch.close();
    }

    await page.emulateMedia({ reducedMotion: "reduce" });
    await cards.first().hover();
    await expect(cards.first()).toHaveCSS("transform", "none");
    const summary = page.locator("#questions summary").first();
    await summary.focus();
    await expect(summary).toBeFocused();
    expect(await summary.evaluate((el) => getComputedStyle(el).outlineStyle)).not.toBe("none");
    await page.keyboard.press("Enter");
    await expect(page.locator("#questions details").first()).toHaveAttribute("open", "");
});

test("mouse tilts sample diagonally while touch and reduced motion keep it static", async ({
    page,
}) => {
    await page.goto("/");

    const { pass, bounds } = await getPassFixture(page);
    await page.mouse.move(bounds.x + bounds.width * 0.8, bounds.y + bounds.height * 0.2);
    await expect
        .poll(() => pass.evaluate((el) => getComputedStyle(el).transform))
        .toMatch(/^matrix3d\(/);
    await page.mouse.move(0, 0);
    await expect(pass).toHaveCSS("transform", "none");
    await pass.dispatchEvent("pointermove", {
        pointerType: "touch",
        clientX: bounds.x + bounds.width * 0.8,
        clientY: bounds.y + bounds.height * 0.2,
    });
    await expect(pass).toHaveCSS("transform", "none");
    await page.emulateMedia({ reducedMotion: "reduce" });
    await pass.dispatchEvent("pointermove", {
        pointerType: "mouse",
        clientX: bounds.x + bounds.width * 0.8,
        clientY: bounds.y + bounds.height * 0.2,
    });
    await expect(pass).toHaveCSS("transform", "none");
    await expect(pass).toHaveCSS("transition-duration", "0s");
});
