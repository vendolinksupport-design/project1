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
}

// ============================================================
// 2. Live Clock
// ============================================================
function initClock() {
    const clockDisplay = document.getElementById('clockDisplay');
    if (!clockDisplay) return;

    function updateClock() {
        const now = new Date();
        let h = now.getHours();
        const m = String(now.getMinutes()).padStart(2, '0');
        const s = String(now.getSeconds()).padStart(2, '0');
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        clockDisplay.textContent = `${String(h).padStart(2, '0')}:${m} ${ampm}`;
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

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.password-toggle');
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
// 9. Session Timeout Warning
//    Shows a warning 5 minutes before session expires (60 min).
// ============================================================
(function initSessionTimeout() {
    const SESSION_LIFETIME_MS = 55 * 60 * 1000; // warn at 55 min (5 min before 1hr)

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
// 10. CSRF Token Auto-Injection
//     For fetch/XHR requests, adds the CSRF token as a header.
//     PHP reads it from: $_SERVER['HTTP_X_CSRF_TOKEN']
// ============================================================
window.VendoLink.fetchWithCSRF = function (url, options = {}) {
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    const csrfToken = csrfInput ? csrfInput.value : '';

    options.headers = {
        'X-CSRF-Token': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.headers || {}),
    };

    return fetch(url, options);
};

// ============================================================
// 11. Progressive Web App (PWA) & Background Service Worker
// ============================================================
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/vendolink_claude_v1/sw.js')
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
window.VendoLink.sendBackgroundNotification = function (title, body, url = '/vendolink_claude_v1/dashboard/alerts.php') {
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
                        icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="20" fill="%230eac5e"/><text y=".75em" x="15" font-size="70" fill="white">⚡</text></svg>',
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
// 12. Session Inactivity Auto-Logout Engine (15 Mins / 14 Mins Warning)
// ============================================================
function initInactivityTimer() {
    // Only activate if user is authenticated (sidebar or topnav present)
    if (!document.getElementById('sidebar') && !document.querySelector('.topnav')) return;

    const INACTIVITY_TIMEOUT = 14 * 60 * 1000; // 14 minutes in milliseconds
    const COUNTDOWN_SECONDS  = 60;             // 60 seconds warning modal countdown
    
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
            const icon = refreshBtn.querySelector('i');
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
                                window.VendoLink.toast('🔔 ' + alert.title + ': ' + alert.message, alert.type === 'danger' ? 'danger' : 'warning', 6000);
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
                        }
                    });
                }
            }
        } catch (err) {
            console.debug('[VendoLink] Notification poll notice:', err);
        } finally {
            isPolling = false;
            if (isManual && refreshBtn) {
                const icon = refreshBtn.querySelector('i');
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
