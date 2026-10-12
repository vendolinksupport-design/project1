/**
 * ============================================================
 * VendoLink — Machine Activity History Modal Manager
 * ============================================================
 *
 * Handles category filtering (All, Connection, Errors, Maintenance, Events)
 * inside the Operational Activity History modal.
 *
 * @package VendoLink
 * @version 1.0.0
 */

document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('activityHistoryModal');
    const listContainer = document.getElementById('modalActivityList');
    const filterBtns = document.querySelectorAll('.activity-filter-btn');

    if (!modalEl || !listContainer) return;

    let currentCategory = 'all';

    async function loadActivityHistory(category = 'all') {
        listContainer.innerHTML = `
            <div class="text-center py-4 text-slate-400">
                <div class="spinner-border spinner-border-sm text-info me-2"></div> Loading activity history...
            </div>`;

        try {
            const res = await fetch(`../api/machine/activity.php?category=${encodeURIComponent(category)}&limit=30`);
            const data = await res.json();

            if (!data.success || !Array.isArray(data.events) || data.events.length === 0) {
                listContainer.innerHTML = `
                    <div class="text-center py-4 text-slate-400">
                        <i class="bi bi-inbox fs-3 d-block mb-1 text-slate-500"></i> No activity events found for this filter.
                    </div>`;
                return;
            }

            listContainer.innerHTML = data.events.map(event => `
                <div class="activity-item-row">
                    <div class="activity-item-content">
                        <i class="bi ${event.event_info.icon} fs-6"></i>
                        <span class="activity-item-title">${escapeHtml(event.event_info.title)}</span>
                    </div>
                    <span class="activity-item-time">${escapeHtml(event.formatted_time)}</span>
                </div>
            `).join('');

        } catch (err) {
            console.error('[VendoLink][ActivityModal] Load error:', err);
            listContainer.innerHTML = `
                <div class="alert alert-danger mb-0">
                    Failed to load activity events. Please try again.
                </div>`;
        }
    }

    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentCategory = btn.getAttribute('data-category') || 'all';
            loadActivityHistory(currentCategory);
        });
    });

    modalEl.addEventListener('show.bs.modal', () => {
        loadActivityHistory(currentCategory);
    });

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
});
