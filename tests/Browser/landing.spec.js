import { test, expect } from '@playwright/test';

test('mobile visitors can navigate the localized product story without overflow', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/');

    await expect(page.getByRole('heading', { level: 1, name: 'Haz que volver sea parte del juego.' })).toBeVisible();
    await expect(page.getByRole('main')).toHaveCount(1);
    await expect(page.getByRole('banner')).toHaveCount(1);
    await expect(page.getByRole('contentinfo')).toHaveCount(1);
    await expect(page).toHaveTitle(/Haz que volver sea parte del juego/);
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /Crea tu cuenta y registra tu negocio/);
    await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
    expect(await page.evaluate(() => localStorage.getItem('flux.appearance'))).toBe('light');
    await expect(page.getByRole('button', { name: 'Modo oscuro' })).toHaveAttribute('aria-pressed', 'false');

    const skip = page.getByRole('link', { name: 'Ir al contenido' });
    await page.keyboard.press('Tab');
    await expect(skip).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#contenido$/);

    const menu = page.getByText('Menú', { exact: true });
    await menu.focus();
    await page.keyboard.press('Enter');
    const nav = page.getByRole('navigation', { name: 'Secciones de la página' }).last();
    for (const [name, target] of [['Cómo funciona', '#como-funciona'], ['Retos', '#retos'], ['Google Wallet', '#wallet'], ['Para negocios', '#negocios']]) {
        await expect(nav.getByRole('link', { name })).toHaveAttribute('href', target);
    }
    await nav.getByRole('link', { name: 'Retos' }).click();
    await expect(page).toHaveURL(/#retos$/);

    for (const title of ['Registra tu negocio', 'Compartir un QR · En desarrollo', 'Registrar visitas · En desarrollo', 'Reconocer la constancia · En desarrollo']) {
        await expect(page.getByRole('heading', { name: title })).toBeVisible();
    }
    await expect(page.getByText('Una tarjeta para acompañar futuros retos.')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('explicit dark appearance persists across public and authentication navigation', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'Modo oscuro' }).click();
    await expect(page.getByRole('button', { name: 'Modo oscuro' })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    await page.getByRole('link', { name: 'Inicia sesión' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
});

test('public calls to action navigate to starter authentication', async ({ page }) => {
    await page.goto('/');
    for (const link of await page.getByRole('link', { name: 'Crear mi cuenta' }).all()) {
        expect(new URL(await link.getAttribute('href'), page.url()).pathname).toBe('/register');
    }
    for (const link of await page.getByRole('link', { name: 'Entrar' }).all()) {
        expect(new URL(await link.getAttribute('href'), page.url()).pathname).toBe('/login');
    }
    await page.getByRole('link', { name: 'Crear mi cuenta' }).last().click();
    await expect(page).toHaveURL(/\/register$/);
    await page.goto('/');
    await page.getByRole('link', { name: 'Inicia sesión' }).click();
    await expect(page).toHaveURL(/\/login$/);
});

const contrastOf = async (locator) => locator.evaluate((element) => {
    const rgb = (value) => (value.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
    const luminance = (color) => {
        const channels = rgb(color).map((channel) => channel / 255).map((channel) =>
            channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
        return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
    };
    let ancestor = element;
    let background;
    while (ancestor && !background) {
        const color = getComputedStyle(ancestor).backgroundColor;
        if (color && !color.endsWith(', 0)')) background = color;
        ancestor = ancestor.parentElement;
    }
    const foreground = luminance(getComputedStyle(element).color);
    const backdrop = luminance(background);
    return (Math.max(foreground, backdrop) + 0.05) / (Math.min(foreground, backdrop) + 0.05);
});

test('meaningful landing text remains legible in both themes at mobile and desktop widths', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    for (const width of [375, 1280]) {
        await page.setViewportSize({ width, height: 812 });
        await page.goto('/');
        await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
        for (const theme of ['light', 'dark']) {
            for (const selector of ['header > div > a', 'header a[href$="register"]', 'header button[aria-pressed]', '#hero-title', '#inicio p', '#inicio a[href$="login"]', '#inicio a[href$="register"]', '#inicio [role="img"] p', '#inicio [role="img"] > p', '#steps-title', '#retos h2', '#wallet h2', '#negocios h2', '#negocios a[href$="register"]']) {
                const ratio = await contrastOf(page.locator(selector).first());
                expect(ratio, `${theme} ${width}px ${selector}`).toBeGreaterThanOrEqual(4.5);
            }
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            if (theme === 'light') await page.getByRole('button', { name: 'Modo oscuro' }).click();
        }
        await expect(page.locator('html')).toHaveClass(/\bdark\b/);
        await page.getByRole('button', { name: 'Modo oscuro' }).click();
    }
});
