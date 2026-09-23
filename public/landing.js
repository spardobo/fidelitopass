// Apply the explicit preference before the stylesheet paints; storage can be unavailable.
(() => {
    const key = 'flux.appearance';
    let dark = false;
    try {
        dark = localStorage.getItem(key) === 'dark';
    } catch (_) {
        // Private browsing or disabled storage still permits an in-page toggle.
    }

    const apply = (button) => {
        document.documentElement.dataset.landingTheme = dark ? 'dark' : 'light';
        button.setAttribute('aria-pressed', String(dark));
        button.setAttribute('aria-label', dark ? 'Activar modo claro' : 'Activar modo oscuro');
        button.textContent = dark ? 'Modo claro' : 'Modo oscuro';
    };

    if (dark) document.documentElement.dataset.landingTheme = 'dark';

    document.addEventListener('DOMContentLoaded', () => {
        const button = document.querySelector('.theme-toggle');
        if (!button) return;
        apply(button);
        button.addEventListener('click', () => {
            dark = !dark;
            apply(button);
            try {
                localStorage.setItem(key, dark ? 'dark' : 'light');
            } catch (_) {
                // Keep the current page functional when persistence is blocked.
            }
        });
    });
})();
