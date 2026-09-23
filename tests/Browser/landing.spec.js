import { test, expect } from '@playwright/test';

test('anonymous visitors can follow the product story without narrow overflow', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/');

    await expect(page.getByRole('heading', { level: 1, name: /Haz que volver/ })).toBeVisible();
    await expect(page.getByRole('main')).toHaveCount(1);
    await expect(page.getByRole('banner')).toHaveCount(1);
    await expect(page.getByRole('contentinfo')).toHaveCount(1);
    const menu = page.getByText('Menú', { exact: true });
    await expect(menu).toBeVisible();
    await menu.focus();
    await expect(menu).toBeFocused();
    await page.keyboard.press('Enter');
    const mobileNav = page.locator('.mobile-menu').getByRole('navigation', { name: 'Secciones de la página' });
    await expect(mobileNav).toBeVisible();
    for (const title of ['Crea un reto', 'Comparte tu QR', 'Valida visitas', 'Entrega la recompensa']) {
        await expect(page.getByRole('heading', { name: title })).toBeVisible();
    }
    await expect(page.getByText('Una sola tarjeta. Nuevos retos con el tiempo.')).toBeVisible();
    const primary = page.getByRole('link', { name: 'Crear mi reto' });
    await expect(primary).toHaveCount(2);
    for (const link of await primary.all()) {
        expect(new URL(await link.getAttribute('href'), page.url()).pathname).toBe('/register');
    }
    expect(new URL(await page.getByRole('link', { name: 'Crea tu primer reto' }).getAttribute('href'), page.url()).pathname).toBe('/register');
    expect(new URL(await page.getByRole('contentinfo').getByRole('link', { name: 'Registrarse' }).getAttribute('href'), page.url()).pathname).toBe('/register');
    await expect(page.getByRole('link', { name: 'Entrar' })).toHaveCount(2);
    await expect(page.locator('.header-cta')).toBeVisible();
    await page.locator('.header-cta').focus();
    await expect(page.locator('.header-cta')).toBeFocused();
    for (const link of await page.getByRole('link', { name: 'Entrar' }).all()) {
        expect(new URL(await link.getAttribute('href'), page.url()).pathname).toBe('/login');
    }
    await expect(page.getByText('Primero crea una cuenta Business para empezar.')).toBeVisible();
    await expect(page.getByText('El primer paso es registrar tu cuenta Business.')).toBeVisible();
    await expect(page.locator('a[href="#"], a[href="/dashboard"]')).toHaveCount(0);
    for (const [name, target] of [['Cómo funciona', '#como-funciona'], ['Retos', '#retos'], ['Para negocios', '#negocios']]) {
        await expect(mobileNav.getByRole('link', { name })).toHaveAttribute('href', target);
    }
    await expect(page.getByRole('link', { name: 'Ir al contenido' })).toHaveAttribute('href', '#contenido');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('keyboard toggle starts in warm light and persists an explicit dark choice', async ({ page }) => {
    await page.goto('/');
    const toggle = page.getByRole('button', { name: 'Activar modo oscuro' });
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator('html')).not.toHaveAttribute('data-landing-theme', 'dark');

    await toggle.focus();
    await expect(toggle).toBeFocused();
    expect(await toggle.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
    await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: 'Activar modo claro' })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('html')).toHaveAttribute('data-landing-theme', 'dark');

    expect(await page.evaluate(() => localStorage.getItem('flux.appearance'))).toBe('dark');
    const loginResponse = await page.goto('/login');
    expect(loginResponse?.status()).toBe(200);
    expect(await page.evaluate(() => localStorage.getItem('flux.appearance'))).toBe('dark');
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-landing-theme', 'dark');
    await page.locator('.site-nav').getByRole('link', { name: 'Retos', exact: true }).click();
    await page.reload();
    await expect(page.getByRole('button', { name: 'Activar modo claro' })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Activar modo claro' }).click();
    expect(await page.evaluate(() => localStorage.getItem('flux.appearance'))).toBe('light');
    const registerResponse = await page.goto('/register');
    expect(registerResponse?.status()).toBe(200);
    expect(await page.evaluate(() => localStorage.getItem('flux.appearance'))).toBe('light');
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-landing-theme', 'light');
});

test('theme control works when storage is unavailable', async ({ page }) => {
    await page.addInitScript(() => {
        Object.defineProperty(window, 'localStorage', { get() { throw new Error('storage unavailable'); } });
    });
    await page.goto('/');
    await page.getByRole('button', { name: 'Activar modo oscuro' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-landing-theme', 'dark');
});
