/**
 * ============================================================
 * VendoLink — app.js
 * ============================================================
 *
 * Provides: Sidebar toggle, clock, CSRF auto-injection,
 * session timeout warning, toast notifications, logout confirm,
 * and Bootstrap tooltip initialization.
 *
 * @package VendoLink
 * @version 1.0
 */

'use strict';

// ============================================================
// DOMContentLoaded & Boot — Boot all features
// ============================================================
function bootVendoLink() {
    initSidebar();
    initClock();
    initTooltips();
    initLogoutConfirm();
    initPasswordToggle();
    initFormLoadingState();
    initAutoHideAlerts();
    initInactivityTimer();
    initNotificationPoller();
    initLucideIcons();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootVendoLink);
} else {
    bootVendoLink();
}

// ============================================================
// 1. Sidebar Toggle (mobile & desktop collapse)
// ============================================================
function initSidebar() {
    const sidebar        = document.getElementById('sidebar');
    const sidebarToggle  = document.getElementById('sidebarToggle');
    const sidebarClose   = document.getElementById('sidebarClose');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (!sidebar) return;

    /**
     * Open the sidebar (mobile).
     */
    function openSidebar() {
        sidebar.classList.add('sidebar-open');
        if (sidebarOverlay) sidebarOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    /**
     * Close the sidebar (mobile).
     */
    function closeSidebar() {
        sidebar.classList.remove('sidebar-open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Toggle button (hamburger in topnav)
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            sidebar.classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
        });
    }

    // Close button inside sidebar (mobile)
    if (sidebarClose) {
        sidebarClose.addEventListener('click', closeSidebar);
    }

    // Overlay click
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }

    // Close sidebar on resize to desktop
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992) {
            closeSidebar();
        }
    });

    // Submenu collapse sync
    const submenuParents = document.querySelectorAll('.nav-link-parent[data-bs-toggle="collapse"]');
    submenuParents.forEach(function (parentLink) {
        const targetId = parentLink.getAttribute('href') || parentLink.getAttribute('data-bs-target');
        const targetCollapse = targetId ? document.querySelector(targetId) : null;
        if (targetCollapse) {
            targetCollapse.addEventListener('show.bs.collapse', function () {
                parentLink.setAttribute('aria-expanded', 'true');
                const parentItem = parentLink.closest('.nav-item-has-submenu');
                if (parentItem) parentItem.classList.add('open');
            });
            targetCollapse.addEventListener('hide.bs.collapse', function () {
                parentLink.setAttribute('aria-expanded', 'false');
                const parentItem = parentLink.closest('.nav-item-has-submenu');
                if (parentItem) parentItem.classList.remove('open');
            });
        }
    });
}

// ============================================================
// Lucide Icons Engine & Auto-Renderer
// ============================================================
const LUCIDE_PATHS = {
    'layers': '<polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline>',
    'search': '<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>',
    'filter': '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>',
    'trending-up': '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline>',
    'trending-down': '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline><polyline points="17 18 23 18 23 12"></polyline>',
    'dollar-sign': '<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
    'wallet': '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"></path><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"></path><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"></path>',
    'history': '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M12 7v5l4 2"></path>',
    'gauge': '<path d="m12 14 4-4"></path><path d="M3.34 19a10 10 0 1 1 17.32 0"></path>',
    'layout-dashboard': '<rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect>',
    'server': '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"></rect><rect width="20" height="8" x="2" y="14" rx="2" ry="2"></rect><line x1="6" x2="6.01" y1="6" y2="6"></line><line x1="6" x2="6.01" y1="18" y2="18"></line>',
    'hard-drive': '<line x1="22" x2="2" y1="12" y2="12"></line><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path><line x1="6" x2="6.01" y1="16" y2="16"></line><line x1="10" x2="10.01" y1="16" y2="16"></line>',
    'bell': '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path>',
    'bell-ring': '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path><path d="M4 2C2.8 3.7 2 5.7 2 8"></path><path d="M22 8c0-2.3-.8-4.3-2-6"></path>',
    'bell-off': '<path d="M8.7 3A6 6 0 0 1 18 8a21.3 21.3 0 0 0 .6 5"></path><path d="M17 17H3s3-2 3-9a4.67 4.67 0 0 1 .3-1.7"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path><line x1="1" x2="23" y1="1" y2="23"></line>',
    'wrench': '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>',
    'users': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
    'user': '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
    'user-check': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline>',
    'settings': '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path><circle cx="12" cy="12" r="3"></circle>',
    'log-out': '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" x2="9" y1="12" y2="12"></line>',
    'log-in': '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" x2="3" y1="12" y2="12"></line>',
    'rotate-cw': '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path>',
    'rotate-ccw': '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path>',
    'refresh-cw': '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path><path d="M8 16H3v5"></path>',
    'chevron-down': '<polyline points="6 9 12 15 18 9"></polyline>',
    'chevron-up': '<polyline points="18 15 12 9 6 15"></polyline>',
    'chevron-right': '<polyline points="9 18 15 12 9 6"></polyline>',
    'chevron-left': '<polyline points="15 18 9 12 15 6"></polyline>',
    'file-spreadsheet': '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M8 13h2"></path><path d="M14 13h2"></path><path d="M8 17h2"></path><path d="M14 17h2"></path>',
    'download': '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line>',
    'plus': '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>',
    'plus-circle': '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line>',
    'minus': '<line x1="5" y1="12" x2="19" y2="12"></line>',
    'minus-circle': '<circle cx="12" cy="12" r="10"></circle><line x1="8" x2="16" y1="12" y2="12"></line>',
    'arrow-right': '<line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline>',
    'arrow-right-circle': '<circle cx="12" cy="12" r="10"></circle><polyline points="12 16 16 12 12 8"></polyline><line x1="8" x2="16" y1="12" y2="12"></line>',
    'arrow-up-right': '<line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline>',
    'arrow-down-right': '<line x1="7" y1="7" x2="17" y2="17"></line><polyline points="17 7 17 17 7 17"></polyline>',
    'check': '<polyline points="20 6 9 17 4 12"></polyline>',
    'check-circle': '<circle cx="12" cy="12" r="10"></circle><polyline points="9 12 12 15 16 10"></polyline>',
    'check-circle-2': '<circle cx="12" cy="12" r="10"></circle><path d="m9 12 2 2 4-4"></path>',
    'check-check': '<path d="M18 6 7 17l-5-5"></path><path d="m22 10-7.5 7.5L13 16"></path>',
    'circle': '<circle cx="12" cy="12" r="10"></circle>',
    'calendar': '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y1="10"></line>',
    'droplet': '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"></path>',
    'droplets': '<path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"></path><path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97"></path>',
    'sparkles': '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"></path>',
    'sliders': '<line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line>',
    'receipt': '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"></path><path d="M16 8H8"></path><path d="M16 12H8"></path><path d="M13 16H8"></path>',
    'clock': '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
    'x': '<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>',
    'x-circle': '<circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line>',
    'info': '<circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path>',
    'help-circle': '<circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" x2="12.01" y1="17" y2="17"></line>',
    'alert-triangle': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
    'shield-check': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path>',
    'shield': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>',
    'shield-alert': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><line x1="12" x2="12" y1="8" y2="12"></line><line x1="12" x2="12.01" y1="16" y2="16"></line>',
    'cpu': '<rect width="16" height="16" x="4" y="4" rx="2"></rect><rect width="6" height="6" x="9" y="9" rx="1"></rect><path d="M15 2v2"></path><path d="M15 20v2"></path><path d="M2 15h2"></path><path d="M2 9h2"></path><path d="M20 15h2"></path><path d="M20 9h2"></path><path d="M9 2v2"></path><path d="M9 20v2"></path>',
    'bar-chart-2': '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
    'pie-chart': '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path>',
    'activity': '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>',
    'key': '<path d="m21 2-2 2m-1.5 1.5L14 9l-1.5-1.5L11 9l-1.5-1.5L8 9l-2-2a4 4 0 1 0-4 4l9.5 9.5a2.12 2.12 0 0 0 3 0l6.5-6.5a2.12 2.12 0 0 0 0-3L21 2Z"></path><circle cx="5.5" cy="5.5" r="1.5"></circle>',
    'lock': '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>',
    'mail': '<rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>',
    'eye': '<path d="2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle>',
    'eye-off': '<path d="9.88 9.88a3 3 0 1 0 4.24 4.24"></path><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"></path><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"></path><line x1="2" x2="22" y1="2" y2="22"></line>',
    'edit': '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"></path>',
    'trash-2': '<path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path><line x1="10" x2="10" y1="11" y2="17"></line><line x1="14" x2="14" y1="11" y2="17"></line>',
    'wifi': '<path d="M12 20h.01"></path><path d="M2 8.82a15 15 0 0 1 20 0"></path><path d="M5 12.86a10 10 0 0 1 14 0"></path><path d="M8.5 16.43a5 5 0 0 1 7 0"></path>',
    'radio': '<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"></path><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"></path><circle cx="12" cy="12" r="2"></circle><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"></path><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"></path>',
    'display': '<rect width="20" height="14" x="2" y="3" rx="2"></rect><line x1="8" x2="16" y1="21" y2="21"></line><line x1="12" x2="12" y1="17" y2="21"></line>',
    'bot': '<path d="M12 8V4H8"></path><rect width="16" height="12" x="4" y="8" rx="2"></rect><path d="M2 14h2"></path><path d="M20 14h2"></path><path d="M15 13v2"></path><path d="M9 13v2"></path>',
    'power': '<path d="M12 2v10"></path><path d="M18.4 6.6a9 9 0 1 1-12.77.04"></path>',
    'send': '<line x1="22" x2="11" y1="2" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>'
};

const LUCIDE_ALIASES = {
    'speedometer': 'gauge',
    'speedometer2': 'gauge',
    'dashboard': 'gauge',
    'hdd-stack': 'server',
    'hdd': 'server',
    'hdd-network': 'server',
    'hdd-network-fill': 'server',
    'bell-fill': 'bell',
    'bell-ring': 'bell-ring',
    'bell-ring-fill': 'bell-ring',
    'wrench-adjustable': 'wrench',
    'wrench-adjustable-circle': 'wrench',
    'people': 'users',
    'people-fill': 'users',
    'person-badge': 'users',
    'person-badge-fill': 'users',
    'gear': 'settings',
    'gear-fill': 'settings',
    'gear-wide': 'settings',
    'gear-wide-connected': 'settings',
    'cog': 'settings',
    'cogs': 'settings',
    'box-arrow-right': 'log-out',
    'box-arrow-left': 'log-in',
    'sign-out': 'log-out',
    'logout': 'log-out',
    'arrow-clockwise': 'rotate-cw',
    'arrow-counterclockwise': 'rotate-ccw',
    'arrow-repeat': 'refresh-cw',
    'check2-all': 'check-check',
    'check-all': 'check-check',
    'check2-circle': 'check-circle',
    'check-circle-fill': 'check-circle',
    'circle-fill': 'circle',
    'droplet-fill': 'droplet',
    'droplets-fill': 'droplets',
    'stars': 'sparkles',
    'sparkles-fill': 'sparkles',
    'clock-history': 'clock',
    'clock-fill': 'clock',
    'cash-coin': 'dollar-sign',
    'currency-dollar': 'dollar-sign',
    'shield-lock': 'shield-check',
    'shield-lock-fill': 'shield-check',
    'key-fill': 'key',
    'person': 'user',
    'person-fill': 'user',
    'person-circle': 'user',
    'graph-up': 'trending-up',
    'graph-up-arrow': 'trending-up',
    'robot': 'bot',
    'cpu-fill': 'cpu',
    'x-lg': 'x',
    'exclamation-triangle': 'alert-triangle',
    'exclamation-triangle-fill': 'alert-triangle',
    'info-circle': 'info',
    'info-circle-fill': 'info',
    'question-circle': 'help-circle',
    'send-check': 'send',
    'send-check-fill': 'send',
    'sliders2': 'sliders',
    'dash-lg': 'minus',
    'plus-lg': 'plus'
};

function renderLucideElement(el) {
    if (!el || el.tagName.toLowerCase() === 'svg') return;
    const rawName = (el.getAttribute('data-lucide') || '').trim().toLowerCase();
    if (!rawName) return;
    const cleanName = rawName.replace(/^bi[-_ ]+/, '');
    const iconName = LUCIDE_ALIASES[cleanName] || LUCIDE_ALIASES[rawName] || cleanName;
    const pathHtml = LUCIDE_PATHS[iconName];
    if (!pathHtml) return;

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    svg.setAttribute('width', el.getAttribute('width') || '18');
    svg.setAttribute('height', el.getAttribute('height') || '18');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', el.getAttribute('stroke-width') || '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('data-lucide', iconName);

    const existingClasses = (el.className || '').trim();
    const lucideClasses = `lucide lucide-${iconName}` + (existingClasses ? ' ' + existingClasses : '');
    svg.setAttribute('class', lucideClasses);

    if (el.getAttribute('style')) {
        svg.setAttribute('style', el.getAttribute('style'));
    }

    svg.innerHTML = pathHtml;
    if (el.parentNode) {
        el.parentNode.replaceChild(svg, el);
    }
}

function initLucideIcons(rootNode) {
    const scope = (rootNode instanceof Element || rootNode instanceof Document) ? rootNode : document;
    if (typeof window.lucide !== 'undefined' && typeof window.lucide.createIcons === 'function' && window.lucide.createIcons !== initLucideIconsInternal) {
        try {
            window.lucide.createIcons({ root: scope });
        } catch (e) {
            // fallback
        }
    }
    const elements = scope.querySelectorAll('[data-lucide]');
    elements.forEach(renderLucideElement);
}

function initLucideIconsInternal(options) {
    initLucideIcons(options && options.root ? options.root : document);
}

// Expose on window.lucide and window.VendoLink
window.lucide = window.lucide || {};
if (typeof window.lucide.createIcons !== 'function') {
    window.lucide.createIcons = initLucideIconsInternal;
}
window.VendoLink = window.VendoLink || {};
window.VendoLink.renderIcons = initLucideIcons;

// Bootstrap lifecycle event listeners for modals, tabs, and collapse
if (typeof document !== 'undefined') {
    document.addEventListener('shown.bs.modal', function(e) {
        initLucideIcons(e.target);
    });
    document.addEventListener('shown.bs.tab', function(e) {
        initLucideIcons(e.target);
    });
    document.addEventListener('shown.bs.collapse', function(e) {
        initLucideIcons(e.target);
    });
}

// ============================================================
// 2. Live Clock
// ============================================================
function initClock() {
    const clockDisplays = document.querySelectorAll('#clockDisplay, #adminClockDisplay, .clock-display');
    if (!clockDisplays.length) return;

    function updateClock() {
        const now = new Date();
        let h = now.getHours();
        const m = String(now.getMinutes()).padStart(2, '0');
        const s = String(now.getSeconds()).padStart(2, '0');
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        const timeStr = `${String(h).padStart(2, '0')}:${m} ${ampm}`;
        clockDisplays.forEach(el => { el.textContent = timeStr; });
    }

    updateClock();
    setInterval(updateClock, 1000);
}

// ============================================================
// 3. Bootstrap Tooltips
// ============================================================
function initTooltips() {
    if (typeof bootstrap === 'undefined') return;
    const tooltipEls = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipEls.forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));
}

// ============================================================
// 4. Logout & Save Confirmation Modals
// ============================================================
function initLogoutConfirm() {
    // Explicitly handle "Yes, Sign Out" action buttons inside confirmation modals
    document.querySelectorAll('#logoutConfirmActionBtn, #adminLogoutConfirmActionBtn, .logout-action-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const targetUrl = this.dataset.logoutUrl
                || this.getAttribute('href')
                || (typeof BASE_URL !== 'undefined' ? `${BASE_URL}/auth/logout.php` : '/vendolink_claude_v1/auth/logout.php');
            window.location.href = targetUrl;
        });
    });

    initSaveConfirm();
}

// Prevent browser Back button from displaying cached protected pages after logout
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});

function initSaveConfirm() {
    document.querySelectorAll('form[data-confirm-save="true"]').forEach(form => {
        // Skip service-config-card forms because machine-config.js handles their change tracking and confirmation
        if (form.classList.contains('service-config-card')) return;

        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmed === 'true') return;

            e.preventDefault();
            const customMessage = form.dataset.confirmMessage || 'Are you sure you want to save these updated configurations?';
            
            const modalEl = document.getElementById('saveConfirmModal');
            const msgEl = document.getElementById('saveModalMessage');
            const actionBtn = document.getElementById('saveConfirmActionBtn');
            
            if (msgEl) msgEl.textContent = customMessage;
            
            if (modalEl && typeof bootstrap !== 'undefined') {
                const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                bsModal.show();

                if (actionBtn) {
                    actionBtn.onclick = function () {
                        form.dataset.confirmed = 'true';
                        bsModal.hide();
                        form.submit();
                    };
                }
            } else {
                if (confirm(customMessage)) {
                    form.dataset.confirmed = 'true';
                    form.submit();
                }
            }
        });
    });
}

// ============================================================
// 5. Password Toggle (generic — for any .password-toggle button)
//    This is the SOLE authoritative handler for password toggle buttons.
//    Uses event delegation on document so static inputs, modals,
//    and dynamically rendered password fields are all supported
//    without duplicate listeners or event conflicts.
// ============================================================
function initPasswordToggle() {
    if (window._vendoLinkPasswordToggleInitialized) return;
    window._vendoLinkPasswordToggleInitialized = true;

    // 1. Universal Eyeball Click Delegation
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.password-toggle, .toggle-password-btn');
        if (!btn) return;

        e.preventDefault();
        const wrapper = btn.closest('.input-password-wrapper, .input-group, .position-relative') || btn.parentElement;
        if (!wrapper) return;

        const input = wrapper.querySelector('input[type="password"], input[type="text"]');
        const icon  = btn.querySelector('i');
        if (!input) return;

        if (input.type === 'password') {
            input.type = 'text';
            btn.setAttribute('aria-label', 'Hide password');
            if (icon) {
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        } else {
            input.type = 'password';
            btn.setAttribute('aria-label', 'Show password');
            if (icon) {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
    });

    // 2. Delegated Input Handler for Dynamic Confirm Password & Live Match/Alphanumeric Feedback
    document.addEventListener('input', function (e) {
        const input = e.target;
        if (!input) return;

        // Dynamic reveal of confirm password field in edit modals & live alphanumeric rule check
        if (input.classList.contains('edit-admin-password') || input.dataset.targetConfirm || input.dataset.targetRules) {
            const targetConfirmSelector = input.dataset.targetConfirm;
            const confirmGroup = targetConfirmSelector ? document.querySelector(targetConfirmSelector) : null;
            const val = input.value.trim();
            const rulesEl = input.dataset.targetRules ? document.querySelector(input.dataset.targetRules) : null;

            if (confirmGroup) {
                const confirmInput = confirmGroup.querySelector('input');
                if (val.length > 0) {
                    confirmGroup.classList.remove('d-none');
                    if (confirmInput) confirmInput.required = true;
                } else {
                    confirmGroup.classList.add('d-none');
                    if (confirmInput) {
                        confirmInput.required = false;
                        confirmInput.value = '';
                    }
                    const matchEl = input.dataset.targetMatch ? document.querySelector(input.dataset.targetMatch) : null;
                    if (matchEl) matchEl.innerHTML = '';
                    if (rulesEl) {
                        rulesEl.innerHTML = rulesEl.dataset.defaultText || 'Leave blank unless you wish to change the password.';
                    }
                    return;
                }
            }

            // Alphanumeric and length live rule check
            if (rulesEl && val.length > 0) {
                const isLenOk = val.length >= 8;
                const isAlphaNum = /[A-Za-z]/.test(val) && /[0-9]/.test(val);
                if (isLenOk && isAlphaNum) {
                    rulesEl.innerHTML = '<span class="text-success small fw-medium"><i class="bi bi-check-circle-fill me-1"></i>8+ characters with letters and numbers</span>';
                } else if (!isLenOk) {
                    rulesEl.innerHTML = '<span class="text-danger small fw-medium"><i class="bi bi-x-circle me-1"></i>Must be at least 8 characters long</span>';
                } else {
                    rulesEl.innerHTML = '<span class="text-danger small fw-medium"><i class="bi bi-x-circle me-1"></i>Must contain both letters and numbers</span>';
                }
            } else if (rulesEl && val.length === 0) {
                rulesEl.innerHTML = rulesEl.dataset.defaultText || (confirmGroup ? 'Leave blank unless you wish to change the password.' : 'Must be at least 8 characters with letters and numbers.');
            }
        }

        // Live Match check for confirm password inputs
        if (input.dataset.targetMatch || input.classList.contains('edit-admin-confirm') || input.id === 'addAdminConfirmPassword' || input.id === 'addAdminPassword') {
            let pwVal = '';
            let confirmVal = '';
            let matchEl = null;

            if (input.id === 'addAdminPassword' || input.id === 'addAdminConfirmPassword') {
                const pwInput = document.getElementById('addAdminPassword');
                const confirmInput = document.getElementById('addAdminConfirmPassword');
                matchEl = document.getElementById('addAdminPwMatch');
                const rulesEl = document.getElementById('addAdminPwRules');
                pwVal = pwInput ? pwInput.value : '';
                confirmVal = confirmInput ? confirmInput.value : '';

                if (rulesEl && pwVal.length > 0) {
                    const isLenOk = pwVal.length >= 8;
                    const isAlphaNum = /[A-Za-z]/.test(pwVal) && /[0-9]/.test(pwVal);
                    if (isLenOk && isAlphaNum) {
                        rulesEl.innerHTML = '<span class="text-success small fw-medium"><i class="bi bi-check-circle-fill me-1"></i>8+ characters with letters and numbers</span>';
                    } else if (!isLenOk) {
                        rulesEl.innerHTML = '<span class="text-danger small fw-medium"><i class="bi bi-x-circle me-1"></i>Must be at least 8 characters long</span>';
                    } else {
                        rulesEl.innerHTML = '<span class="text-danger small fw-medium"><i class="bi bi-x-circle me-1"></i>Must contain both letters and numbers</span>';
                    }
                } else if (rulesEl && pwVal.length === 0) {
                    rulesEl.innerHTML = 'Must be at least 8 characters with letters and numbers.';
                }
            } else {
                const modal = input.closest('.modal, form');
                if (modal) {
                    const pwInput = modal.querySelector('.edit-admin-password, input[name="password"], input[name="new_password"]');
                    const confirmInput = modal.querySelector('.edit-admin-confirm, input[name="confirm_password"]');
                    pwVal = pwInput ? pwInput.value : '';
                    confirmVal = confirmInput ? confirmInput.value : '';
                    const matchSelector = (confirmInput && confirmInput.dataset.targetMatch) || (pwInput && pwInput.dataset.targetMatch);
                    if (matchSelector) {
                        matchEl = document.querySelector(matchSelector);
                    }
                }
            }

            if (matchEl) {
                if (confirmVal.length === 0) {
                    matchEl.innerHTML = '';
                } else if (pwVal === confirmVal) {
                    matchEl.innerHTML = '<span class="text-success small fw-medium"><i class="bi bi-check-circle-fill me-1"></i>Passwords match</span>';
                } else {
                    matchEl.innerHTML = '<span class="text-danger small fw-medium"><i class="bi bi-x-circle-fill me-1"></i>Passwords do not match</span>';
                }
            }
        }
    });
}

// ============================================================
// 6. Form Loading State
//    Automatically disables submit buttons and shows a spinner
//    when any form tagged with data-loading="true" is submitted.
// ============================================================
function initFormLoadingState() {
    document.querySelectorAll('form[data-loading]').forEach(function (form) {
        form.addEventListener('submit', function () {
            const btn     = form.querySelector('button[type="submit"]');
            const btnText = btn?.querySelector('.btn-text');
            const btnLoad = btn?.querySelector('.btn-loading');

            if (btn)     btn.disabled = true;
            if (btnText) btnText.classList.add('d-none');
            if (btnLoad) btnLoad.classList.remove('d-none');
        });
    });
}

// ============================================================
// 7. Auto-hide Alerts after 6 seconds
// ============================================================
function initAutoHideAlerts() {
    document.querySelectorAll('.alert.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            // Use Bootstrap's dismiss method if available
            if (typeof bootstrap !== 'undefined') {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert?.close();
            } else {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alert.remove(), 500);
            }
        }, 6000);
    });
}

// ============================================================
// 8. Toast Notification API
//    Usage from PHP-driven pages (call via inline script):
//
//    VendoLink.toast('Machine #3 went offline', 'danger');
//    VendoLink.toast('Sales target reached!', 'success');
// ============================================================
window.VendoLink = window.VendoLink || {};

window.VendoLink.toast = function (message, type = 'info', duration = 4000) {
    type = type === 'error' ? 'danger' : type;

    const icons = {
        success: 'bi-check-circle-fill',
        danger:  'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info:    'bi-info-circle-fill',
    };

    const colors = {
        success: '#22c55e',
        danger:  '#ef4444',
        warning: '#f59e0b',
        info:    '#38bdf8',
    };

    // Create container if not exists
    let container = document.getElementById('vl-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'vl-toast-container';
        Object.assign(container.style, {
            position:   'fixed',
            bottom:     '1.5rem',
            right:      '1.5rem',
            zIndex:     '9999',
            display:    'flex',
            flexDirection: 'column',
            gap:        '.5rem',
        });
        document.body.appendChild(container);
    }

    // Build toast element
    const toast = document.createElement('div');
    toast.innerHTML = `
        <i class="bi ${icons[type] || icons.info}" style="color:${colors[type] || colors.info};font-size:1rem;flex-shrink:0"></i>
        <span style="flex:1;font-size:.85rem;color:#f1f5f9">${escapeHtml(message)}</span>
        <button onclick="this.parentElement.remove()" style="background:none;border:none;color:#64748b;cursor:pointer;font-size:.85rem;padding:0;line-height:1">&#x2715;</button>
    `;
    Object.assign(toast.style, {
        display:      'flex',
        alignItems:   'center',
        gap:          '.75rem',
        background:   '#1e293b',
        border:       '1px solid #334155',
        borderLeft:   `4px solid ${colors[type] || colors.info}`,
        borderRadius: '10px',
        padding:      '.75rem 1rem',
        boxShadow:    '0 10px 30px rgba(0,0,0,.4)',
        minWidth:     '260px',
        maxWidth:     '360px',
        opacity:      '0',
        transform:    'translateX(20px)',
        transition:   'opacity .25s ease, transform .25s ease',
    });

    container.appendChild(toast);

    // Animate in
    requestAnimationFrame(() => {
        toast.style.opacity   = '1';
        toast.style.transform = 'translateX(0)';
    });

    // Auto-dismiss
    setTimeout(() => {
        toast.style.opacity   = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(() => toast.remove(), 300);
    }, duration);
};

// ============================================================
// 9. Session Timeout Warning (Aligned with 4-hour server session)
//    Shows a warning 5 minutes before absolute timeout.
// ============================================================
(function initSessionTimeout() {
    const SESSION_LIFETIME_MS = (3 * 60 + 55) * 60 * 1000; // warn at 3h 55m

    setTimeout(function () {
        if (window.VendoLink && typeof window.VendoLink.toast === 'function') {
            window.VendoLink.toast(
                'Your session will expire in 5 minutes. Save your work.',
                'warning',
                10000
            );
        }
    }, SESSION_LIFETIME_MS);
})();

// ============================================================
// 10. CSRF Auto-Sync & Session Keepalive Engine
//     Keeps the session alive silently and keeps form tokens
//     synchronized so users never experience a 403 or token mismatch.
// ============================================================
(function initCsrfAndKeepalive() {
    window.VendoLink = window.VendoLink || {};

    function getBaseUrl() {
        if (typeof BASE_URL !== 'undefined') return BASE_URL;
        if (typeof window.BASE_URL !== 'undefined') return window.BASE_URL;
        return window.location.pathname.startsWith('/vendolink_claude_v1') ? '/vendolink_claude_v1' : '';
    }

    // Synchronize all CSRF inputs and headers on page
    window.VendoLink.syncCsrfToken = function (token) {
        if (!token) return;
        window.VendoLink.csrfToken = token;

        // Update all form inputs
        document.querySelectorAll('input[name="csrf_token"]').forEach(function (input) {
            input.value = token;
        });

        // Update meta tags if present
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', token);
    };

    // Ping keepalive endpoint to extend session & fetch latest token
    window.VendoLink.refreshSessionAndCsrf = async function () {
        try {
            const url = `${getBaseUrl()}/api/csrf_keepalive.php`;
            const res = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            if (!res.ok) return;
            const data = await res.json();
            if (data && data.success && data.csrf_token) {
                window.VendoLink.syncCsrfToken(data.csrf_token);
            }
        } catch (e) {
            // Non-critical background ping; fail silently
        }
    };

    // 1. Initial keepalive after page load
    setTimeout(window.VendoLink.refreshSessionAndCsrf, 5000);

    // 2. Periodic background ping every 4 minutes (prevents inactivity timeout)
    setInterval(window.VendoLink.refreshSessionAndCsrf, 4 * 60 * 1000);

    // 3. Auto-refresh when returning to tab from background or sleep
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            window.VendoLink.refreshSessionAndCsrf();
        }
    });
    window.addEventListener('focus', function () {
        window.VendoLink.refreshSessionAndCsrf();
    });

    // 4. Ensure any form being submitted has the latest valid token
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!form || !form.querySelector) return;
        const input = form.querySelector('input[name="csrf_token"]');
        if (input && window.VendoLink && window.VendoLink.csrfToken) {
            input.value = window.VendoLink.csrfToken;
        }
    }, true);

    // 5. CSRF fetch helper
    window.VendoLink.fetchWithCSRF = function (url, options = {}) {
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const csrfToken = (window.VendoLink && window.VendoLink.csrfToken) || (csrfInput ? csrfInput.value : '');

        options.headers = {
            'X-CSRF-Token': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers || {}),
        };

        return fetch(url, options);
    };
})();

// ============================================================
// 11. Progressive Web App (PWA) & Background Service Worker
// ============================================================
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        const swUrl = (typeof window.BASE_URL !== 'undefined') ? `${window.BASE_URL}/sw.js` : (window.location.pathname.startsWith('/vendolink_claude_v1') ? '/vendolink_claude_v1/sw.js' : '/sw.js');
        navigator.serviceWorker.register(swUrl)
            .then(function (reg) {
                console.log('VendoLink Service Worker registered successfully:', reg.scope);
            })
            .catch(function (err) {
                console.warn('VendoLink Service Worker registration failed:', err);
            });
    });
}

/**
 * Trigger a real background system notification via Service Worker (PWA / Lockscreen).
 */
window.VendoLink.sendBackgroundNotification = function (title, body, url = null) {
    if (!url) {
        const baseUrl = (typeof window.BASE_URL !== 'undefined') ? window.BASE_URL : (window.location.pathname.startsWith('/vendolink_claude_v1') ? '/vendolink_claude_v1' : '');
        url = `${baseUrl}/dashboard/alerts.php`;
    }
    if (!('Notification' in window)) {
        alert('This browser does not support desktop/mobile push notifications.');
        return;
    }

    Notification.requestPermission().then(function (permission) {
        if (permission === 'granted') {
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                navigator.serviceWorker.ready.then(function (reg) {
                    reg.showNotification(title, {
                        body: body,
                        icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="20" fill="%230eac5e"/><polygon points="55,15 25,55 50,55 45,85 75,45 50,45" fill="white"/></svg>',
                        vibrate: [200, 100, 200],
                        data: { url: url }
                    });
                });
            } else {
                new Notification(title, { body: body });
            }
        } else {
            console.warn('Notification permission denied by user.');
        }
    });
};

// ============================================================
// 12. Session Inactivity Auto-Logout Engine (Aligned with 4-Hour Session)
// ============================================================
function initInactivityTimer() {
    // Only activate if user is authenticated (sidebar or topnav present)
    if (!document.getElementById('sidebar') && !document.querySelector('.topnav')) return;

    const INACTIVITY_TIMEOUT = (3 * 60 + 55) * 60 * 1000; // 3 hours 55 minutes (aligned with 4h server timeout)
    const COUNTDOWN_SECONDS  = 60;                        // 60 seconds warning modal countdown
    
    let inactivityTimer = null;
    let countdownInterval = null;
    let secondsRemaining = COUNTDOWN_SECONDS;
    let isWarningShown = false;

    function startInactivityTimer() {
        if (isWarningShown) return;
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(showInactivityWarning, INACTIVITY_TIMEOUT);
    }

    function showInactivityWarning() {
        isWarningShown = true;
        secondsRemaining = COUNTDOWN_SECONDS;

        const modalEl = document.getElementById('inactivityWarningModal');
        const timerCountEl = document.getElementById('inactivityTimerCount');
        
        if (modalEl && typeof bootstrap !== 'undefined') {
            const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
        }

        if (timerCountEl) {
            timerCountEl.textContent = '00:59';
        }

        clearInterval(countdownInterval);
        countdownInterval = setInterval(function () {
            secondsRemaining--;
            if (timerCountEl) {
                const secs = String(secondsRemaining).padStart(2, '0');
                timerCountEl.textContent = `00:${secs}`;
            }

            if (secondsRemaining <= 0) {
                clearInterval(countdownInterval);
                const baseUrl = (typeof BASE_URL !== 'undefined') ? BASE_URL : '/vendolink_claude_v1';
                window.location.href = `${baseUrl}/auth/logout.php?reason=inactivity`;
            }
        }, 1000);
    }

    window.resetInactivityTimer = function () {
        const modalEl = document.getElementById('inactivityWarningModal');
        if (modalEl && typeof bootstrap !== 'undefined') {
            const bsModal = bootstrap.Modal.getInstance(modalEl);
            if (bsModal) bsModal.hide();
        }

        clearInterval(countdownInterval);
        isWarningShown = false;
        startInactivityTimer();
    };

    // Listen for user interactions across page
    const activityEvents = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'];
    activityEvents.forEach(function (evt) {
        window.addEventListener(evt, function () {
            if (!isWarningShown) {
                startInactivityTimer();
            }
        }, { passive: true });
    });

    startInactivityTimer();
}

// ============================================================
// 13. Real-Time Operational Notification & Alerts Poller
// ============================================================
function initNotificationPoller() {
    const topnav = document.querySelector('.topnav');
    if (!topnav) return;

    const baseUrl = (typeof BASE_URL !== 'undefined') ? BASE_URL : '/vendolink_claude_v1';
    const streamList = document.getElementById('alertStreamList');
    let lastSeenId = streamList && streamList.dataset.lastId ? parseInt(streamList.dataset.lastId, 10) : 0;
    let isPolling = false;

    async function pollNotifications(isManual = false) {
        if (isPolling) return;
        isPolling = true;

        const refreshBtn = document.getElementById('btnRefreshAlerts');
        if (isManual && refreshBtn) {
            const icon = refreshBtn.querySelector('i, svg');
            if (icon) icon.classList.add('spin-icon');
        }

        try {
            const res = await fetch(`${baseUrl}/api/notifications.php?action=poll&last_id=${lastSeenId}`, {
                headers: { 'Accept': 'application/json' }
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            if (data && data.success) {
                // 1. Update Topnav Unread Badge
                const badge = document.getElementById('topnavNotifBadge');
                if (badge) {
                    if (data.unread_count > 0) {
                        badge.textContent = data.unread_count;
                        badge.classList.remove('d-none');
                    } else {
                        badge.classList.add('d-none');
                    }
                }

                // 2. Update Topnav Count Label
                const countLabel = document.getElementById('topnavNotifCount');
                if (countLabel) {
                    countLabel.textContent = `${data.unread_count} unread`;
                }

                // 3. Update Dropdown Items List
                const notifList = document.getElementById('topnavNotifList');
                if (notifList && Array.isArray(data.dropdown_items) && data.dropdown_items.length > 0) {
                    let html = '';
                    data.dropdown_items.forEach(item => {
                        html += `
                            <div class="notif-item ${item.unread ? 'notif-unread' : ''}">
                                <div class="notif-icon notif-icon-${escapeHtml(item.type)}">
                                    <i class="bi ${escapeHtml(item.icon)}"></i>
                                </div>
                                <div class="notif-body">
                                    <div class="notif-text">${escapeHtml(item.text)}</div>
                                    <div class="notif-time"><i class="bi bi-clock me-1"></i>${escapeHtml(item.time)}</div>
                                </div>
                            </div>`;
                    });
                    notifList.innerHTML = html;
                }

                // 4. Update KPI Summary Counters if on Alerts Page
                if (data.summary) {
                    const kpiTotal = document.getElementById('kpiTotalAlerts');
                    const kpiCrit  = document.getElementById('kpiCriticalCount');
                    const kpiHw    = document.getElementById('kpiHardwareCount');

                    if (kpiTotal) kpiTotal.textContent = data.summary.total;
                    if (kpiCrit)  kpiCrit.textContent  = data.summary.critical;
                    if (kpiHw)    kpiHw.textContent    = data.summary.hardware;

                    const filterAll = document.getElementById('filterAllCount');
                    const filterCrit = document.getElementById('filterCriticalCount');
                    const filterHw = document.getElementById('filterHardwareCount');

                    if (filterAll)  filterAll.textContent  = data.summary.total;
                    if (filterCrit) filterCrit.textContent = data.summary.critical;
                    if (filterHw)   filterHw.textContent   = data.summary.hardware;
                }

                // 5. Prepend New Alerts to Live Stream if present
                if (Array.isArray(data.new_alerts) && data.new_alerts.length > 0) {
                    const streamContainer = document.getElementById('alertStreamList');
                    const emptyState = document.getElementById('alertStreamEmpty');
                    if (emptyState) emptyState.classList.add('d-none');

                    data.new_alerts.forEach(alert => {
                        if (alert.id > lastSeenId) {
                            lastSeenId = alert.id;
                        }

                        // Trigger Web Notification / Toast if enabled
                        if (data.web_notif_enabled && window.VendoLink) {
                            if (typeof window.VendoLink.toast === 'function') {
                                window.VendoLink.toast(alert.title + ': ' + alert.message, alert.type === 'danger' ? 'danger' : 'warning', 6000);
                            }
                            if (typeof window.VendoLink.sendBackgroundNotification === 'function') {
                                window.VendoLink.sendBackgroundNotification(alert.title, alert.message, alert.action_link || `${baseUrl}/dashboard/alerts.php`);
                            }
                        }

                        // Prepend card to live stream if element exists and not already rendered
                        if (streamContainer && !document.getElementById(`alert-card-${alert.id}`)) {
                            const card = document.createElement('div');
                            card.className = `alert-card-item p-3 rounded-3 d-flex align-items-start gap-3 border border-info border-opacity-50`;
                            card.id = `alert-card-${alert.id}`;
                            card.innerHTML = `
                                <div class="notif-icon notif-icon-${escapeHtml(alert.type)} p-2 rounded-3 flex-shrink-0">
                                    <i class="bi ${escapeHtml(alert.icon)} fs-5"></i>
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                                        <h6 class="font-bold mb-0 text-light me-2">
                                            ${escapeHtml(alert.title)}
                                            <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25 ms-1" style="font-size: 0.7rem;">NEW</span>
                                        </h6>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25">
                                                ${escapeHtml(alert.badge)}
                                            </span>
                                            <span class="text-muted small">
                                                <i class="bi bi-clock me-1"></i>${escapeHtml(alert.formatted_time)}
                                            </span>
                                        </div>
                                    </div>
                                    <p class="text-muted mb-0 small line-height-md">
                                        ${escapeHtml(alert.message)}
                                    </p>
                                </div>`;
                            streamContainer.insertBefore(card, streamContainer.firstChild);
                            initLucideIcons(card);
                        }
                    });
                }
            }
        } catch (err) {
            console.debug('[VendoLink] Notification poll notice:', err);
        } finally {
            isPolling = false;
            if (isManual && refreshBtn) {
                const icon = refreshBtn.querySelector('i, svg');
                if (icon) icon.classList.remove('spin-icon');
            }
        }
    }

    // Expose manual trigger
    window.VendoLink = window.VendoLink || {};
    window.VendoLink.refreshAlertsFeed = function () {
        pollNotifications(true);
    };

    // Poll every 10 seconds in background
    setInterval(function () {
        pollNotifications(false);
    }, 10000);
}

// ============================================================
// Helpers
// ============================================================
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
