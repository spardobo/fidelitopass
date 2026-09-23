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
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /Crea retos de puntos/);
    await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
    expect(await page.evaluate(() => localStorage.getItem('flux.appearance'))).toBe('light');
    await expect(page.getByRole('button', { name: /modo oscuro|modo claro/i })).toHaveCount(0);

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

    for (const title of ['Crea un reto', 'Comparte tu QR', 'Valida visitas', 'Entrega la recompensa']) {
        await expect(page.getByRole('heading', { name: title })).toBeVisible();
    }
    await expect(page.getByText('Una sola tarjeta. Nuevos retos con el tiempo.')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('explicit dark appearance persists across public and authentication navigation', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('flux.appearance', 'dark'));
    await page.goto('/');
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    await page.getByRole('link', { name: 'Entrar' }).first().click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
});

test('public calls to action navigate to starter authentication', async ({ page }) => {
    await page.goto('/');
    for (const link of await page.getByRole('link', { name: 'Crear mi reto' }).all()) {
        expect(new URL(await link.getAttribute('href'), page.url()).pathname).toBe('/register');
    }
    expect(new URL(await page.getByRole('link', { name: 'Crea tu primer reto' }).getAttribute('href'), page.url()).pathname).toBe('/register');
    for (const link of await page.getByRole('link', { name: 'Entrar' }).all()) {
        expect(new URL(await link.getAttribute('href'), page.url()).pathname).toBe('/login');
    }
    await page.getByRole('link', { name: 'Crea tu primer reto' }).click();
    await expect(page).toHaveURL(/\/register$/);
    await page.goto('/');
    await page.getByRole('link', { name: 'Entrar' }).first().click();
    await expect(page).toHaveURL(/\/login$/);
});
