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
    await expect(page.getByRole('link', { name: 'Crear mi reto' }).first()).toHaveAttribute('href', '#negocios');
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

    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-landing-theme', 'dark');
    await page.locator('.site-nav').getByRole('link', { name: 'Retos', exact: true }).click();
    await page.reload();
    await expect(page.getByRole('button', { name: 'Activar modo claro' })).toHaveAttribute('aria-pressed', 'true');
});

test('theme control works when storage is unavailable', async ({ page }) => {
    await page.addInitScript(() => {
        Object.defineProperty(window, 'localStorage', { get() { throw new Error('storage unavailable'); } });
    });
    await page.goto('/');
    await page.getByRole('button', { name: 'Activar modo oscuro' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-landing-theme', 'dark');
});
