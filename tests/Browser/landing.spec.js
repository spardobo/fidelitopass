import { test, expect } from '@playwright/test';

const widths = [375, 768, 1024, 1280];
const navigation = [
    ['#benefits', 'Beneficios'],
    ['#how-it-works', 'Cómo funciona'],
    ['#challenges', 'Retos de puntos'],
    ['#pass', 'El pase'],
    ['#questions', 'Preguntas frecuentes'],
    ['#business', 'Empezar'],
];

async function expectOrderedNavigation(nav) {
    await expect(nav.locator('a')).toHaveCount(navigation.length);
    expect(await nav.locator('a').evaluateAll(links => links.map(link => [link.getAttribute('href'), link.textContent.trim()]))).toEqual(navigation);
}

test('desktop navigation follows the visible section order without horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');
    await expectOrderedNavigation(page.locator('header > div > nav'));
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.locator('header > div > nav a[href="#benefits"]').click();
    await expect(page).toHaveURL(/#benefits$/);
    await expect.poll(() => page.locator('#benefits').evaluate(el => el.getBoundingClientRect().top)).toBeGreaterThanOrEqual(0);
    await expect.poll(() => page.locator('#benefits').evaluate(el => el.getBoundingClientRect().top)).toBeLessThan(40);
});

test('public landing scales the approved sample and keeps every section reachable', async ({ page }, testInfo) => {
    for (const width of widths) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/');
        await expect(page.getByRole('heading', { level: 1, name: 'Dale a tus clientes una razón para volver.' })).toBeVisible();
        await expect(page.locator('html')).toHaveClass(/\bdark\b/);
        await expect(page.getByRole('main')).toHaveCount(1);
        await expect(page.getByRole('contentinfo')).toHaveCount(1);
        const pass = page.locator('[data-pass]');
        const thumbnail = page.locator('[data-thumbnail]');
        await expect(pass).toHaveAttribute('aria-label', /pase de Google Wallet/);
        await expect(page).toHaveTitle('FidelitoPass - Dale a tus clientes una razón para volver. - FidelitoPass');
        await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /Convierte cada visita en una razón para volver/);
        await expect(pass).toContainText('9 / 15 puntos');
        await expect(pass).toContainText('2 puntos');
        await expect(pass).toContainText('Hamburguesa gratis');
        await expect(pass).toContainText('30 SEP');
        await expect(pass).toContainText('48273');
        await expect(page.getByText('QR y código de ejemplo.')).toBeVisible();
        await expect(page.getByText('Ejemplo: 1 punto por visita, 2 los martes. Al llegar a 10 puntos antes del plazo, tu cliente obtiene un café.')).toBeVisible();
        await expect(page.locator('header nav a[href="#pass"]').first()).toHaveAttribute('href', '#pass');
        await expect(page.getByRole('img', { name: 'QR de muestra con el texto FidelitoPass' })).toBeVisible();
        await expect(page.locator('main')).not.toContainText(/landing\.[a-z_]+|tarjeta Wallet|Apple Wallet|tarjeta de sellos/);
        const geometry = await page.evaluate(() => {
            const box = selector => { const r = document.querySelector(selector).getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height, right: r.right, bottom: r.bottom }; };
            return { thumb: box('[data-thumbnail]'), pass: box('[data-pass]'), qr: box('.landing-pass-qr'), code: box('.landing-pass-qr + p'), cards: [...document.querySelectorAll('#benefits article')].map(e => { const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width }; }), overflow: document.documentElement.scrollWidth > innerWidth };
        });
        expect(geometry.pass.width).toBeCloseTo(geometry.thumb.width, 0);
        expect(geometry.pass.height).toBeCloseTo(geometry.thumb.height, 0);
        expect(geometry.pass.width / geometry.pass.height).toBeCloseTo(1.5, 2);
        expect(geometry.qr.x).toBeGreaterThan(geometry.pass.x + geometry.pass.width / 2);
        expect(geometry.code.y).toBeGreaterThan(geometry.qr.y);
        expect(geometry.code.bottom).toBeLessThanOrEqual(geometry.pass.bottom);
        expect(geometry.overflow).toBe(false);
        const layout = await page.evaluate(() => ({ headerBottom: document.querySelector('header').getBoundingClientRect().bottom, heroTop: document.querySelector('#home').getBoundingClientRect().top, passCenter: document.querySelector('[data-thumbnail]').getBoundingClientRect().top + document.querySelector('[data-thumbnail]').getBoundingClientRect().height / 2, copyCenter: document.querySelector('#home > div').getBoundingClientRect().top + document.querySelector('#home > div').getBoundingClientRect().height / 2 }));
        expect(layout.heroTop - layout.headerBottom).toBeGreaterThanOrEqual(31);
        if (width === 1280) expect(Math.abs(layout.passCenter - layout.copyCenter)).toBeLessThan(60);
        expect(geometry.cards[0].width).toBeCloseTo(geometry.cards[1].width, 0);
        if (width >= 768) expect(geometry.cards[0].y).toBeCloseTo(geometry.cards[1].y, 0);
        else expect(geometry.cards[1].y).toBeGreaterThan(geometry.cards[0].y);
        for (const id of ['home', 'how-it-works', 'challenges', 'pass', 'benefits', 'questions', 'business']) await expect(page.locator(`#${id}`)).toHaveCount(1);
        await expect(page.locator('#questions details')).toHaveCount(6);
        for (const asset of ['logo-header.webp', 'logo_icon.svg', 'benefit-challenges.svg', 'benefit-points.svg', 'preview-pass-qr.svg']) expect((await page.request.get(`/${asset}`)).ok()).toBe(true);
        await page.screenshot({ path: testInfo.outputPath(`public-${width}.png`), fullPage: true, animations: 'disabled' });
    }
});

test('mobile navigation, FAQ, skip link and authentication routes work', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Ir al contenido' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#content$/);
    await expect(page.locator('main')).toBeFocused();
    await expect.poll(() => page.locator('main').evaluate(el => el.getBoundingClientRect().top)).toBeGreaterThanOrEqual(-1);
    await page.locator('.landing-menu summary').click();
    const nav = page.locator('.landing-menu nav');
    await expectOrderedNavigation(nav);
    await nav.locator('a[href="#challenges"]').click();
    await expect(page).toHaveURL(/#challenges$/);
    await expect.poll(() => page.locator('#challenges').evaluate(el => el.getBoundingClientRect().top)).toBeGreaterThanOrEqual(0);
    await expect.poll(() => page.locator('#challenges').evaluate(el => el.getBoundingClientRect().top)).toBeLessThan(40);
    await expect(page.locator('.landing-menu')).not.toHaveAttribute('open', '');
    await page.locator('#questions summary').first().click();
    await expect(page.locator('#questions details').first()).toHaveAttribute('open', '');
    await expect(page.locator('a[href$="/register"]').first()).toHaveAttribute('href', /register$/);
    await page.getByRole('link', { name: 'Inicia sesión' }).click();
    await expect(page).toHaveURL(/\/login$/);
});

test('footer and header return to the absolute page top while the hero remains navigable', async ({ page }) => {
    for (const [width, height, linkSelector] of [
        [375, 812, 'footer a[href="#page-top"]'],
        [1280, 900, 'header a[href="#page-top"]'],
    ]) {
        await page.setViewportSize({ width, height });
        await page.goto('/');
        await expect(page.locator('#page-top')).toHaveCSS('scroll-margin-top', '0px');
        await expect(page.locator('#home')).toHaveCount(1);
        await page.locator('#home').evaluate(el => el.scrollIntoView({ behavior: 'instant' }));
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
        await page.locator(linkSelector).click();
        await expect(page).toHaveURL(/#page-top$/);
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
    }

    await page.locator('#home').scrollIntoViewIfNeeded();
    await expect(page.locator('#home')).toHaveAttribute('id', 'home');
});

test('registration CTAs retain readable hover contrast and keyboard focus', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: 'Crear mi cuenta' })).toHaveCount(2);
    // sample computed colors after the real pointer transition, not source class strings.
    for (const selector of ['#home a.landing-button', '#business a.landing-button']) {
        const link = page.locator(selector);
        await link.hover();
        await page.waitForTimeout(350);
        const colors = await link.evaluate(el => ({ foreground: getComputedStyle(el).color, background: getComputedStyle(el).backgroundColor }));
        const luminance = value => {
            const channels = value.match(/[\d.]+/g).slice(0, 3).map(Number).map(channel => {
                const normalized = channel / 255;
                return normalized <= .04045 ? normalized / 12.92 : ((normalized + .055) / 1.055) ** 2.4;
            });
            return channels[0] * .2126 + channels[1] * .7152 + channels[2] * .0722;
        };
        const a = luminance(colors.foreground);
        const b = luminance(colors.background);
        expect((Math.max(a, b) + .05) / (Math.min(a, b) + .05), selector).toBeGreaterThanOrEqual(4.5);
        await link.focus();
        await expect(link).toBeFocused();
        expect(await link.evaluate(el => getComputedStyle(el).outlineStyle)).not.toBe('none');
    }
});

test('informational cards lift only for fine pointers without reduced motion', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');

    const cards = page.locator('#benefits article, #how-it-works li.landing-info-card');
    await expect(cards).toHaveCount(5);

    for (const card of [cards.first(), cards.nth(2)]) {
        await card.hover();
        await expect.poll(() => card.evaluate(el => new DOMMatrix(getComputedStyle(el).transform).m42)).toBeLessThan(-1);
        const displacement = await card.evaluate(el => new DOMMatrix(getComputedStyle(el).transform).m42);
        expect(displacement).toBeGreaterThanOrEqual(-4.1);
        await page.mouse.move(0, 0);
        await expect(card).toHaveCSS('transform', 'none');
    }

    await cards.nth(2).hover();
    await page.screenshot({ path: testInfo.outputPath('landing-step-hover.png'), fullPage: true });

    const touch = await page.context().browser().newContext({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });
    const touchPage = await touch.newPage();
    await touchPage.goto('/');
    await touchPage.locator('#benefits article').first().dispatchEvent('mouseover');
    await expect(touchPage.locator('#benefits article').first()).toHaveCSS('transform', 'none');
    await touch.close();

    await page.emulateMedia({ reducedMotion: 'reduce' });
    await cards.first().hover();
    await expect(cards.first()).toHaveCSS('transform', 'none');
    const summary = page.locator('#questions summary').first();
    await summary.focus();
    await expect(summary).toBeFocused();
    expect(await summary.evaluate(el => getComputedStyle(el).outlineStyle)).not.toBe('none');
    await page.keyboard.press('Enter');
    await expect(page.locator('#questions details').first()).toHaveAttribute('open', '');
});

test('mouse tilts sample diagonally while touch and reduced motion keep it static', async ({ page }) => {
    await page.goto('/');
    const pass = page.locator('[data-pass]');
    const bounds = await page.locator('[data-thumbnail]').boundingBox();
    await page.mouse.move(bounds.x + bounds.width * .8, bounds.y + bounds.height * .2);
    await expect.poll(() => pass.evaluate(el => getComputedStyle(el).transform)).toMatch(/^matrix3d\(/);
    await page.mouse.move(0, 0);
    await expect(pass).toHaveCSS('transform', 'none');
    await pass.dispatchEvent('pointermove', { pointerType: 'touch', clientX: bounds.x + bounds.width * .8, clientY: bounds.y + bounds.height * .2 });
    await expect(pass).toHaveCSS('transform', 'none');
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await pass.dispatchEvent('pointermove', { pointerType: 'mouse', clientX: bounds.x + bounds.width * .8, clientY: bounds.y + bounds.height * .2 });
    await expect(pass).toHaveCSS('transform', 'none');
    await expect(pass).toHaveCSS('transition-duration', '0s');
});
