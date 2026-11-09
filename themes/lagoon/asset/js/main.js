(function () {
    'use strict';

    /* ---------- Mobile menu toggle ---------- */
    const navToggle = document.querySelector('.site-nav__toggle');
    const navMenu   = document.querySelector('.site-nav__menu');
    const siteNav   = document.querySelector('.site-nav');
    const headerTop = document.querySelector('.site-header__top');

    const setMenuOpen = (open) => {
        if (!navToggle || !navMenu) return;
        navMenu.classList.toggle('is-open', open);
        navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    if (navToggle && navMenu) {
        navToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            setMenuOpen(!navMenu.classList.contains('is-open'));
        });

        document.addEventListener('click', (e) => {
            if (!navMenu.classList.contains('is-open')) return;
            if (siteNav && siteNav.contains(e.target)) return;
            if (headerTop && headerTop.contains(e.target)) return;
            setMenuOpen(false);
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && navMenu.classList.contains('is-open')) {
                setMenuOpen(false);
                navToggle.focus();
            }
        });
    }

    /* ---------- User dropdown ---------- */
    document.querySelectorAll('.site-nav__dropdown').forEach((dd) => {
        const toggle = dd.querySelector('.site-nav__dropdown-toggle');
        if (!toggle) return;

        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = dd.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('click', (e) => {
            if (!dd.contains(e.target) && dd.classList.contains('is-open')) {
                dd.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        dd.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && dd.classList.contains('is-open')) {
                dd.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        });
    });

    /* ---------- Color theme toggle ---------- */
    const themeRoot = document.documentElement;
    const themeToggle = document.querySelector('[data-theme-toggle]');

    const currentTheme = () => {
        const attr = themeRoot.getAttribute('data-theme');
        if (attr === 'dark' || attr === 'light') return attr;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    };

    const applyTheme = (theme) => {
        themeRoot.setAttribute('data-theme', theme);
        try {
            localStorage.setItem('site.theme', theme);
        } catch (_) {}
        if (themeToggle) {
            themeToggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        }
    };

    applyTheme(currentTheme());

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
        });
    }
})();
