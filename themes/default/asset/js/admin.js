(function () {
    'use strict';

    const shell = document.getElementById('adminShell');
    if (!shell) return;

    const sidebar = document.getElementById('adminSidebar');
    const backdrop = document.querySelector('.admin-backdrop');
    const COLLAPSE_KEY = 'admin.sidebar.collapsed';
    const mqDesktop = window.matchMedia('(min-width: 992px)');

    const hydrateNavLabels = () => {
        document.querySelectorAll('.admin-nav-link').forEach((link) => {
            if (link.dataset.label) return;
            const span = link.querySelector('span');
            const text = (span ? span.textContent : link.textContent || '').trim();
            if (text) link.dataset.label = text;
        });
    };

    /* ---------- Desktop collapse ---------- */
    const collapseBtn = document.querySelector('[data-sidebar-collapse]');
    const setCollapsed = (collapsed) => {
        // Collapsed/icon mode is desktop-only.
        const onDesktop = mqDesktop.matches;
        const next = onDesktop && collapsed;
        shell.classList.toggle('is-collapsed', next);
        if (collapseBtn) {
            collapseBtn.setAttribute('aria-pressed', next ? 'true' : 'false');
            collapseBtn.setAttribute(
                'aria-label',
                next ? 'Expand sidebar' : 'Collapse sidebar'
            );
            collapseBtn.setAttribute(
                'title',
                next ? 'Expand sidebar' : 'Collapse sidebar'
            );
            const icon = collapseBtn.querySelector('i');
            if (icon) {
                icon.className = next
                    ? 'bi bi-chevron-double-right'
                    : 'bi bi-chevron-double-left';
            }
        }
        if (next) hydrateNavLabels();
        try {
            localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
        } catch (_) {}
    };

    if (collapseBtn) {
        const saved = (() => {
            try {
                return localStorage.getItem(COLLAPSE_KEY);
            } catch (_) {
                return null;
            }
        })();
        if (saved === '1') setCollapsed(true);

        collapseBtn.addEventListener('click', () => {
            setCollapsed(!shell.classList.contains('is-collapsed'));
        });
    }

    // Leaving / entering desktop: drop collapsed chrome on mobile widths.
    const syncViewport = () => {
        if (!mqDesktop.matches && shell.classList.contains('is-collapsed')) {
            shell.classList.remove('is-collapsed');
        } else if (mqDesktop.matches) {
            try {
                if (localStorage.getItem(COLLAPSE_KEY) === '1') {
                    setCollapsed(true);
                }
            } catch (_) {}
        }
    };
    if (typeof mqDesktop.addEventListener === 'function') {
        mqDesktop.addEventListener('change', syncViewport);
    } else if (typeof mqDesktop.addListener === 'function') {
        mqDesktop.addListener(syncViewport);
    }

    /* ---------- Mobile drawer ---------- */
    const openBtn = document.querySelector('[data-sidebar-open]');
    const closeTriggers = document.querySelectorAll('[data-sidebar-close]');

    const openMobile = () => {
        shell.classList.add('is-mobile-open');
        if (backdrop) {
            backdrop.hidden = false;
            requestAnimationFrame(() => backdrop.classList.add('is-visible'));
        }
        document.body.style.overflow = 'hidden';
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    };

    const closeMobile = () => {
        shell.classList.remove('is-mobile-open');
        if (backdrop) {
            backdrop.classList.remove('is-visible');
            setTimeout(() => {
                backdrop.hidden = true;
            }, 200);
        }
        document.body.style.overflow = '';
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
    };

    if (openBtn) {
        openBtn.setAttribute('aria-expanded', 'false');
        openBtn.setAttribute('aria-controls', 'adminSidebar');
        openBtn.addEventListener('click', openMobile);
    }
    closeTriggers.forEach((el) => el.addEventListener('click', closeMobile));

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && shell.classList.contains('is-mobile-open')) {
            closeMobile();
        }
    });

    // Close mobile drawer when a nav link is clicked
    if (sidebar) {
        sidebar.addEventListener('click', (e) => {
            if (
                e.target.closest('a.admin-nav-link') &&
                window.matchMedia('(max-width: 991.98px)').matches
            ) {
                closeMobile();
            }
        });
    }

    // Resize from mobile-open → desktop: clear drawer state
    const syncMobileDrawer = () => {
        if (mqDesktop.matches && shell.classList.contains('is-mobile-open')) {
            closeMobile();
        }
    };
    if (typeof mqDesktop.addEventListener === 'function') {
        mqDesktop.addEventListener('change', syncMobileDrawer);
    } else if (typeof mqDesktop.addListener === 'function') {
        mqDesktop.addListener(syncMobileDrawer);
    }
})();
