(function () {
    'use strict';

    /* ---------- Mobile menu toggle ---------- */
    const navToggle = document.querySelector('.site-nav__toggle');
    const navMenu   = document.querySelector('.site-nav__menu');
    const siteNav   = document.querySelector('.site-nav');

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

        // Close on outside click
        document.addEventListener('click', (e) => {
            if (!dd.contains(e.target) && dd.classList.contains('is-open')) {
                dd.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        // ESC closes
        dd.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && dd.classList.contains('is-open')) {
                dd.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        });
    });

    /* ---------- Search: expand on focus handled by CSS; clear on Escape ---------- */
    const searchInput = document.querySelector('.site-nav__search-input');
    if (searchInput) {
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                searchInput.value = '';
                searchInput.blur();
            }
        });
    }

    /* ---------- Highlight active nav link ---------- */
    const path = window.location.pathname.replace(/\/+$/, '') || '/';
    document.querySelectorAll('.site-nav__link').forEach((link) => {
        try {
            const href = new URL(link.href, window.location.origin).pathname.replace(/\/+$/, '') || '/';
            if (href === path) link.classList.add('is-active');
        } catch (_) {}
    });
})();