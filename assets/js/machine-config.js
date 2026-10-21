'use strict';

document.addEventListener('DOMContentLoaded', function () {
    // 1. Initialize Steppers globally across any container
    function initSteppers(container) {
        const root = container || document;
        root.querySelectorAll('.stepper').forEach(function (stepper) {
            if (stepper.dataset.stepperInitialized === 'true') return;
            stepper.dataset.stepperInitialized = 'true';

            const input = stepper.querySelector('input[type="hidden"], input[name*="service_seconds"], input[name]');
            const timeEntry = stepper.querySelector('.time-entry');
            const step = Number(stepper.dataset.step || 1);
            const min = Number(stepper.dataset.min || 0);
            const max = Number(stepper.dataset.max || Number.MAX_SAFE_INTEGER);
            let holdTimer;
            let holdStarted;

            function updateButtonStates(safeValue) {
                const downBtn = stepper.querySelector('[data-stepper="down"]');
                const upBtn = stepper.querySelector('[data-stepper="up"]');
                if (downBtn) downBtn.disabled = (safeValue <= min);
                if (upBtn) upBtn.disabled = (safeValue >= max);
            }

            function render(value) {
                const safeValue = Math.max(min, Math.min(max, value));
                if (input) {
                    input.value = safeValue;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (timeEntry) {
                    const minutes = Math.floor(safeValue / 60);
                    const seconds = safeValue % 60;
                    timeEntry.value = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
                }
                updateButtonStates(safeValue);
            }

            function change(direction, accelerated) {
                if (!input) return;
                render(Number(input.value) + (direction * step * (accelerated ? 3 : 1)));
            }

            stepper.querySelectorAll('[data-stepper]').forEach(function (button) {
                const direction = button.dataset.stepper === 'up' ? 1 : -1;
                button.addEventListener('pointerdown', function (event) {
                    event.preventDefault();
                    if (button.disabled) return;
                    change(direction, false);
                    holdStarted = Date.now();
                    holdTimer = setInterval(function () {
                        change(direction, Date.now() - holdStarted > 800);
                    }, 120);
                });
                ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (eventName) {
                    button.addEventListener(eventName, function () { clearInterval(holdTimer); });
                });
                button.addEventListener('click', function (event) { event.preventDefault(); });
            });

            if (timeEntry) {
                timeEntry.addEventListener('change', function () {
                    const match = timeEntry.value.trim().match(/^(\d{1,2}):(\d{2})$/);
                    if (!match || Number(match[2]) > 59) {
                        if (input) render(Number(input.value));
                        if (window.VendoLink) VendoLink.toast('Enter time as MM:SS, for example 04:00.', 'warning');
                        return;
                    }
                    const totalSecs = (Number(match[1]) * 60) + Number(match[2]);
                    if (totalSecs < min || totalSecs > max) {
                        if (input) render(Number(input.value));
                        if (window.VendoLink) VendoLink.toast('Service time must be between 00:10 (10 seconds) and 10:00 (10 minutes).', 'warning');
                        return;
                    }
                    render(totalSecs);
                });
            } else if (input) {
                input.addEventListener('change', function () { render(Number(input.value)); });
            }

            // Initialize button states on load
            if (input) render(Number(input.value));
        });
    }

    initSteppers(document);

    // 2. Track changes & handle confirmation for legacy / multi-card service forms
    const forms = document.querySelectorAll('.service-config-card:not(#unifiedHardwareForm), #unifiedServiceForm');
    forms.forEach(function (form) {
        const state = form.querySelector('.save-state');
        const submit = form.querySelector('.btn-save-config');
        const trackedInputs = form.querySelectorAll('input[name], select[name], textarea[name]');
        const original = Array.from(trackedInputs, function (input) {
            return input.type === 'checkbox' ? String(input.checked) : input.value;
        });

        function hasChanges() {
            return Array.from(trackedInputs).some(function (input, index) {
                const value = input.type === 'checkbox' ? String(input.checked) : input.value;
                return value !== original[index];
            });
        }

        function updateSaveState() {
            const label = submit ? submit.querySelector('.btn-text') : null;
            if (hasChanges()) {
                if (state) {
                    state.textContent = 'You have unsaved changes.';
                    state.classList.add('has-changes');
                }
                if (label) {
                    label.innerHTML = '<i class="bi bi-save2 me-2"></i>Save Configuration';
                }
            } else {
                if (state) {
                    state.textContent = 'No changes yet.';
                    state.classList.remove('has-changes');
                }
                if (label) {
                    label.innerHTML = '<i class="bi bi-check2-circle me-2"></i>Save Configuration';
                }
            }
        }

        // Live status badge pills on toggle switch changes
        form.querySelectorAll('input[type="checkbox"]').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                if (toggle.dataset.targetPill) {
                    const pill = document.getElementById(toggle.dataset.targetPill);
                    if (pill) {
                        pill.textContent = toggle.checked ? 'Enabled' : 'Disabled';
                        pill.className = 'badge rounded-pill px-3 py-1.5 fw-semibold service-status-pill ' + (toggle.checked ? 'bg-success' : 'bg-secondary');
                    }
                }
            });
        });

        trackedInputs.forEach(function (input) {
            input.addEventListener('change', updateSaveState);
            input.addEventListener('input', updateSaveState);
        });

        form.addEventListener('submit', function (event) {
            if (!hasChanges()) {
                event.preventDefault();
                if (window.VendoLink && typeof VendoLink.toast === 'function') {
                    if (!document.querySelector('.vendolink-toast, .toast.show')) {
                        VendoLink.toast('No configuration changes to save.', 'info');
                    }
                }
                return;
            }

            // Client-side validation: validate all service times
            let validationError = null;
            form.querySelectorAll('.stepper').forEach(function (stepper) {
                const hiddenInput = stepper.querySelector('input[type="hidden"], input[name*="service_seconds"], input[name]');
                const serviceName = stepper.dataset.serviceName || 'Service';
                if (hiddenInput) {
                    const secs = Number(hiddenInput.value);
                    const min = Number(stepper.dataset.min || 10);
                    const max = Number(stepper.dataset.max || 600);
                    if (isNaN(secs) || secs < min || secs > max) {
                        validationError = serviceName + ': Please enter a valid service time between 00:10 (10 seconds) and 10:00 (10 minutes).';
                    }
                }
            });

            if (validationError) {
                event.preventDefault();
                if (window.VendoLink) {
                    VendoLink.toast(validationError, 'warning');
                } else {
                    alert(validationError);
                }
                return;
            }

            if (form.dataset.confirmed === 'true') {
                if (submit) {
                    submit.disabled = true;
                    const textEl = submit.querySelector('.btn-text');
                    const loadEl = submit.querySelector('.btn-loading');
                    if (textEl) textEl.classList.add('d-none');
                    if (loadEl) loadEl.classList.remove('d-none');
                }
                return;
            }

            event.preventDefault();
            const customMessage = form.dataset.confirmMessage || 'Are you sure you want to save the updated service configuration?';
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

                        if (submit) {
                            submit.disabled = true;
                            const textEl = submit.querySelector('.btn-text');
                            const loadEl = submit.querySelector('.btn-loading');
                            if (textEl) textEl.classList.add('d-none');
                            if (loadEl) loadEl.classList.remove('d-none');
                        }

                        form.submit();
                    };
                }
            } else {
                if (confirm(customMessage)) {
                    form.dataset.confirmed = 'true';
                    if (submit) {
                        submit.disabled = true;
                        const textEl = submit.querySelector('.btn-text');
                        const loadEl = submit.querySelector('.btn-loading');
                        if (textEl) textEl.classList.add('d-none');
                        if (loadEl) loadEl.classList.remove('d-none');
                    }
                    form.submit();
                }
            }
        });
    });
});
