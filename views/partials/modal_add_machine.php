<?php
/**
 * ============================================================
 * VendoLink — Add Another Machine Modal Component
 * ============================================================
 *
 * Reusable modal for authenticated customers to register and claim
 * an additional vending machine using a valid Product Activation Key.
 * Supports manual key entry or camera QR scanning directly from
 * the hardware packaging sticker or controller screen.
 *
 * @package VendoLink
 * @version 1.1.0
 */
?>
<!-- Add Another Machine Modal -->
<div class="modal fade" id="addMachineModal" tabindex="-1" aria-labelledby="addMachineModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary border-opacity-25 shadow-lg" style="background: #0b1329; color: #f8fafc; border-radius: 14px;">
            <div class="modal-header border-secondary border-opacity-25 pb-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                        <i class="bi bi-plus-circle-fill fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-light mb-0" id="addMachineModalLabel">Add Another Machine</h5>
                        <div class="text-secondary small">Connect an additional vending unit to your account.</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="btnCloseAddMachineModal"></button>
            </div>

            <form method="POST" action="<?= BASE_URL ?>/dashboard/machines.php" id="formAddMachine" onsubmit="return handleAddMachineSubmit(this);">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="claim_machine">
                <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? (BASE_URL . '/dashboard/machines.php')) ?>">

                <div class="modal-body py-3">
                    <p class="text-secondary small mb-3">
                        Enter the 16-character <strong>Product Activation Key</strong> from your machine's packaging sticker, or scan its QR code directly using your camera.
                    </p>

                    <!-- Product Key Field with Scan QR Button -->
                    <div class="mb-3">
                        <label for="modalProductKey" class="form-label text-slate-300 small fw-bold d-flex justify-content-between align-items-center">
                            <span>PRODUCT ACTIVATION KEY <span class="text-danger">*</span></span>
                            <span class="text-secondary small fw-normal">Sticker or Display</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary border-opacity-25 text-warning font-monospace">
                                <i class="bi bi-key-fill"></i>
                            </span>
                            <input type="text"
                                   id="modalProductKey"
                                   name="product_key"
                                   class="form-control bg-dark text-light border-secondary border-opacity-25 font-monospace text-uppercase"
                                   placeholder="e.g. VL-2026-ABCD-1234"
                                   required
                                   autocomplete="off"
                                   maxlength="32"
                                   oninput="this.value = this.value.toUpperCase();">
                            <button type="button" class="btn btn-outline-info d-flex align-items-center gap-1 px-3" id="btnToggleAddMachineQr" title="Scan QR Code from packaging sticker" style="border-color: #0ea5e9; color: #38bdf8; background: rgba(14, 165, 233, 0.1);">
                                <i class="bi bi-qr-code-scan"></i>
                                <span class="d-none d-sm-inline">Scan QR</span>
                                <span class="d-inline d-sm-none">Scan</span>
                            </button>
                        </div>
                        <div class="form-text text-muted small mt-1">
                            Format: <code>VL-2026-XXXX-XXXX</code> (Found on the machine registration sticker).
                        </div>

                        <!-- Scanned Confirmation Alert -->
                        <div id="modalQrSuccessAlert" class="alert alert-success py-2 px-3 mt-2 d-none align-items-center justify-content-between" role="alert" style="background: rgba(34, 197, 94, 0.15); border: 1px solid #22c55e; color: #86efac; font-size: 0.84rem; border-radius: 8px;">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-check-circle-fill text-success fs-6"></i>
                                <div>
                                    <strong>QR Code Scanned!</strong> Key filled: <span class="font-monospace fw-bold text-white ms-1" id="modalQrSuccessKeyText"></span>
                                </div>
                            </div>
                            <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.7rem;" onclick="document.getElementById('modalQrSuccessAlert').classList.add('d-none')" aria-label="Close"></button>
                        </div>
                    </div>

                    <!-- Embedded QR Scanner Box (Collapsible) -->
                    <div id="modalScannerBox" class="d-none mb-3 p-3 rounded-3" style="background: rgba(15, 23, 42, 0.95); border: 1px solid rgba(56, 189, 248, 0.3);">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25 px-2 py-1">
                                    <i class="bi bi-camera me-1"></i>Camera Active
                                </span>
                                <span class="small text-secondary" style="font-size: 0.75rem;">Point at QR sticker</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-info py-0 px-2 text-info" id="btnModalUploadPhoto" title="Take photo with phone camera">
                                    <i class="bi bi-camera me-1"></i>Take Photo
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 text-slate-300" id="btnCloseModalScanner" title="Close Camera Scanner">
                                    <i class="bi bi-x-lg me-1"></i>Close
                                </button>
                            </div>
                        </div>

                        <!-- Camera Switcher Dropdown (if multiple cameras available) -->
                        <div id="modalCameraSelectBox" class="mb-2 d-none text-start">
                            <label for="modalCameraSelect" class="form-label small text-secondary mb-1" style="font-size: 0.75rem;">
                                <i class="bi bi-camera-video me-1"></i>Select Camera:
                            </label>
                            <select id="modalCameraSelect" class="form-select form-select-sm bg-dark text-light border-secondary border-opacity-25" style="font-size: 0.8rem;"></select>
                        </div>

                        <!-- Viewfinder Wrapper -->
                        <div class="modal-scanner-viewport position-relative rounded-3 overflow-hidden" id="modalScannerViewport">
                            <!-- Html5Qrcode video stream destination -->
                            <div id="modalQrReader" style="width: 100%;"></div>

                            <!-- Reticle Crosshair & Laser Beam -->
                            <div id="modalScannerReticle" class="modal-scanner-reticle position-absolute top-50 start-50 translate-middle d-none" style="pointer-events: none; width: 190px; height: 190px;">
                                <div class="modal-reticle-corner modal-reticle-tl"></div>
                                <div class="modal-reticle-corner modal-reticle-tr"></div>
                                <div class="modal-reticle-corner modal-reticle-bl"></div>
                                <div class="modal-reticle-corner modal-reticle-br"></div>
                                <div class="modal-scanner-laser"></div>
                            </div>

                            <!-- Initializing / Loading Spinner -->
                            <div id="modalScannerLoading" class="position-absolute top-50 start-50 translate-middle text-center w-100 px-3">
                                <div class="spinner-border text-info mb-2" style="width: 2.2rem; height: 2.2rem;" role="status"></div>
                                <div class="text-white fw-semibold small">Initializing camera...</div>
                                <div class="text-secondary small mt-1" style="font-size: 0.75rem;">Please allow camera permission if prompted</div>
                            </div>

                            <!-- Error Fallback Overlay -->
                            <div id="modalScannerError" class="position-absolute top-0 start-0 w-100 h-100 p-3 text-center d-none" style="overflow-y: auto; background: rgba(15, 23, 42, 0.98); z-index: 20;">
                                <i class="bi bi-camera-video-off text-danger fs-3 mb-1"></i>
                                <h6 id="modalScannerErrorTitle" class="text-white fw-bold mb-1 small">Camera Access Issue</h6>
                                <p id="modalScannerErrorMsg" class="text-secondary small mb-2" style="font-size: 0.78rem; line-height: 1.35;">Unable to access the camera.</p>

                                <!-- Browser Settings Guide (if permission denied/blocked) -->
                                <div id="modalScannerSettingsGuide" class="p-2 mb-2 rounded-2 text-start small d-none" style="background: rgba(15, 23, 42, 0.95); border: 1px solid #334155; font-size: 0.74rem;">
                                    <div class="fw-bold text-info mb-1 d-flex align-items-center gap-1">
                                        <i class="bi bi-sliders"></i> To enable camera in browser:
                                    </div>
                                    <ol class="ps-3 mb-0 text-slate-300" style="padding-left: 1.1rem; line-height: 1.4;">
                                        <li>Tap the <i class="bi bi-sliders text-info"></i> or <i class="bi bi-lock-fill text-warning"></i> lock or site info icon beside the address bar.</li>
                                        <li>Set <strong>Camera</strong> to <strong>Allow</strong>.</li>
                                        <li>Tap <strong>Try Again</strong> below, or tap <strong>Take Photo / Upload</strong>.</li>
                                    </ol>
                                </div>

                                <div class="d-flex flex-wrap justify-content-center gap-2 mt-2">
                                    <button type="button" class="btn btn-sm btn-success px-3 py-1 small" id="btnModalUploadPhotoFromError">
                                        <i class="bi bi-camera-fill me-1"></i>Take Photo / Upload
                                    </button>
                                    <button type="button" class="btn btn-sm btn-info px-3 py-1 small" id="btnModalRetryCamera">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Try Again
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary px-3 py-1 small" id="btnModalEnterManual">
                                        <i class="bi bi-keyboard me-1"></i>Type Key
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Hidden native file input -->
                        <input type="file" id="modalScannerFileInput" accept="image/*" capture="environment" class="d-none">

                        <!-- Scanner Status Text -->
                        <div id="modalScannerStatus" class="mt-2 text-secondary small text-center" style="font-size: 0.76rem;">
                            <i class="bi bi-info-circle me-1 text-info"></i>Align the QR code from the ESP32 sticker inside the frame.
                        </div>

                        <!-- Temporary Error/Warning Feedback -->
                        <div id="modalScannerFeedback" class="alert alert-warning py-1 px-2 mt-2 small d-none text-center" style="background: rgba(245, 158, 11, 0.15); border: 1px solid #f59e0b; color: #fde68a; font-size: 0.78rem;" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i><span id="modalScannerFeedbackText">Invalid QR code.</span>
                        </div>
                    </div>

                    <!-- Machine Nickname Field -->
                    <div class="mb-2">
                        <label for="modalMachineNickname" class="form-label text-slate-300 small fw-bold">
                            MACHINE NICKNAME OR BRANCH <span class="text-secondary fw-normal">(OPTIONAL)</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary border-opacity-25 text-info">
                                <i class="bi bi-tag-fill"></i>
                            </span>
                            <input type="text"
                                   id="modalMachineNickname"
                                   name="machine_nickname"
                                   class="form-control bg-dark text-light border-secondary border-opacity-25"
                                   placeholder="e.g. Branch 2 — Carwash Bay"
                                   maxlength="50">
                        </div>
                        <div class="form-text text-muted small">
                            A friendly label to easily identify this machine in your dashboard and reports.
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-secondary border-opacity-25 pt-3">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-warning fw-bold text-dark px-4" id="btnAddMachineSubmit">
                        <i class="bi bi-plus-lg me-1"></i> Add Machine
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Scoped Styling for Add Machine QR Scanner */
.modal-scanner-viewport {
    position: relative;
    background: #020617;
    border: 2px solid #334155;
    border-radius: 8px;
    min-height: 240px;
    max-height: 320px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
#modalQrReader {
    width: 100% !important;
    border: none !important;
}
#modalQrReader video {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
    border-radius: 6px;
    display: block;
}
#modalQrReader__scan_region {
    background: transparent !important;
}
#modalQrReader__header_message, #modalQrReader img[alt="Info icon"] {
    display: none !important;
}
.modal-scanner-reticle {
    position: absolute;
    width: 180px;
    height: 180px;
    pointer-events: none;
    z-index: 10;
}
.modal-reticle-corner {
    position: absolute;
    width: 22px;
    height: 22px;
    border-color: #0ea5e9;
    border-style: solid;
}
.modal-reticle-tl { top: 0; left: 0; border-width: 3px 0 0 3px; border-top-left-radius: 5px; }
.modal-reticle-tr { top: 0; right: 0; border-width: 3px 3px 0 0; border-top-right-radius: 5px; }
.modal-reticle-bl { bottom: 0; left: 0; border-width: 0 0 3px 3px; border-bottom-left-radius: 5px; }
.modal-reticle-br { bottom: 0; right: 0; border-width: 0 3px 3px 0; border-bottom-right-radius: 5px; }
.modal-scanner-laser {
    position: absolute;
    left: 4px;
    right: 4px;
    height: 2px;
    background: linear-gradient(90deg, rgba(14,165,233,0), #38bdf8 50%, rgba(14,165,233,0));
    box-shadow: 0 0 8px #0ea5e9;
    animation: modalScannerLaser 2s infinite ease-in-out;
}
@keyframes modalScannerLaser {
    0% { top: 4px; opacity: 0.2; }
    50% { top: calc(100% - 6px); opacity: 1; }
    100% { top: 4px; opacity: 0.2; }
}
</style>

<script>
/**
 * Lazy-load html5-qrcode library if not yet present on page
 */
(function() {
    if (typeof Html5Qrcode === 'undefined') {
        var s = document.createElement('script');
        s.src = '<?= ASSETS_URL ?>/js/html5-qrcode.min.js';
        s.onerror = function() {
            var cdn = document.createElement('script');
            cdn.src = 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js';
            document.head.appendChild(cdn);
        };
        document.head.appendChild(s);
    }
})();

/**
 * Robustly extract and validate an activation key from a QR payload.
 */
if (typeof window.extractActivationKey !== 'function') {
    window.extractActivationKey = function(rawPayload) {
        if (!rawPayload || typeof rawPayload !== 'string') return null;
        var trimmed = rawPayload.trim();
        if (!trimmed) return null;
        if (/[<>]|[\x00-\x1F\x7F]/.test(trimmed)) return null;

        function isValidKeyFormat(k) {
            if (!k || typeof k !== 'string') return false;
            var clean = k.trim();
            if (clean.length < 5 || clean.length > 64) return false;
            if (!/^[A-Za-z0-9_-]+$/.test(clean)) return false;
            var lower = clean.toLowerCase();
            if (/^(https?|ftp|true|false|null|undefined|wifi|mailto|tel)$/.test(lower)) return false;
            return true;
        }

        function normalizeKey(k) {
            return k.trim().toUpperCase();
        }

        // 1. URL parsing
        try {
            var urlObj = null;
            if (/^https?:\/\//i.test(trimmed)) {
                urlObj = new URL(trimmed);
            } else if (trimmed.indexOf('?') !== -1) {
                urlObj = new URL('http://dummy.local/' + (trimmed.startsWith('/') ? trimmed.slice(1) : trimmed));
            }
            if (urlObj && urlObj.searchParams) {
                var candidate = urlObj.searchParams.get('key') ||
                                urlObj.searchParams.get('product_key') ||
                                urlObj.searchParams.get('activation_key') ||
                                urlObj.searchParams.get('code');
                if (candidate && isValidKeyFormat(candidate)) {
                    return normalizeKey(candidate);
                }
            }
        } catch (e) {}

        // 2. Query param regex fallback
        var matchQuery = trimmed.match(/[?&](?:key|product_key|activation_key|code)=([A-Za-z0-9_\-]+)/i);
        if (matchQuery && matchQuery[1] && isValidKeyFormat(matchQuery[1])) {
            return normalizeKey(matchQuery[1]);
        }

        // 3. JSON payload
        if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
            try {
                var parsed = JSON.parse(trimmed);
                var jsonKey = parsed.key || parsed.product_key || parsed.activation_key || parsed.code;
                if (jsonKey && typeof jsonKey === 'string' && isValidKeyFormat(jsonKey)) {
                    return normalizeKey(jsonKey);
                }
            } catch (e) {}
        }

        // 4. Direct key format
        if (isValidKeyFormat(trimmed)) {
            return normalizeKey(trimmed);
        }

        // 5. Embedded VL-XXXX pattern match
        if (!/[{}[\];'"\\$%^&*!~`+=]/.test(trimmed)) {
            var embedded = trimmed.match(/\b(VL-[A-Za-z0-9\-]+)\b/i);
            if (embedded && embedded[1] && isValidKeyFormat(embedded[1])) {
                return normalizeKey(embedded[1]);
            }
        }

        return null;
    };
}

(function() {
    'use strict';

    var modalEl              = document.getElementById('addMachineModal');
    var btnToggleQr          = document.getElementById('btnToggleAddMachineQr');
    var btnCloseScanner      = document.getElementById('btnCloseModalScanner');
    var scannerBox           = document.getElementById('modalScannerBox');
    var cameraSelectBox      = document.getElementById('modalCameraSelectBox');
    var cameraSelect         = document.getElementById('modalCameraSelect');
    var loadingOverlay       = document.getElementById('modalScannerLoading');
    var errorOverlay         = document.getElementById('modalScannerError');
    var settingsGuide        = document.getElementById('modalScannerSettingsGuide');
    var reticle              = document.getElementById('modalScannerReticle');
    var statusText           = document.getElementById('modalScannerStatus');
    var feedbackBox          = document.getElementById('modalScannerFeedback');
    var feedbackText         = document.getElementById('modalScannerFeedbackText');
    var btnRetryCamera       = document.getElementById('btnModalRetryCamera');
    var btnEnterManual       = document.getElementById('btnModalEnterManual');
    var inputKey             = document.getElementById('modalProductKey');
    var inputNickname        = document.getElementById('modalMachineNickname');
    var successAlert         = document.getElementById('modalQrSuccessAlert');
    var successKeyText       = document.getElementById('modalQrSuccessKeyText');
    var fileInput            = document.getElementById('modalScannerFileInput');
    var btnUploadPhoto       = document.getElementById('btnModalUploadPhoto');
    var btnUploadPhotoError  = document.getElementById('btnModalUploadPhotoFromError');

    var html5QrCode          = null;
    var isScanning           = false;
    var isRequestingPerm     = false;
    var scanCooldown         = false;
    var denialCount          = 0;

    function playBeep() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.15);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.15);
        } catch (e) {}
    }

    function resetScannerUI() {
        if (loadingOverlay) loadingOverlay.classList.remove('d-none');
        if (errorOverlay)   errorOverlay.classList.add('d-none');
        if (settingsGuide)  settingsGuide.classList.add('d-none');
        if (reticle)        reticle.classList.add('d-none');
        if (feedbackBox)    feedbackBox.classList.add('d-none');
        if (btnRetryCamera) btnRetryCamera.classList.remove('d-none');
        if (statusText)     statusText.innerHTML = '<i class="bi bi-info-circle me-1 text-info"></i>Align the QR code from the ESP32 sticker inside the frame.';
    }

    function categorizeError(err) {
        var errName = (err && err.name) ? String(err.name) : '';
        var errStr  = ((err && err.message) ? err.message : String(err || '')).toLowerCase();

        if (errName === 'InsecureContextError' || errName === 'NotSupportedError' ||
            !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia ||
            /insecure context|secure context|not supported|mediadevices/i.test(errStr)) {
            return {
                title: 'HTTPS Required or Browser Unsupported',
                message: 'Camera access requires HTTPS or localhost. Please type the key manually.',
                canRetry: false,
                showGuide: false
            };
        }
        if (errName === 'NotAllowedError' || errName === 'PermissionDeniedError' || /notallowederror|permission denied|denied/i.test(errStr)) {
            denialCount++;
            return {
                title: 'Camera Permission Denied',
                message: denialCount >= 2
                    ? 'Camera permission is blocked. Check your browser site settings or enter the key manually.'
                    : 'Camera permission was denied. Tap "Try Again" to allow access.',
                canRetry: true,
                showGuide: denialCount >= 2
            };
        }
        if (errName === 'NotFoundError' || errName === 'DevicesNotFoundError' || /notfounderror|no camera|devices not found/i.test(errStr)) {
            return {
                title: 'No Camera Detected',
                message: 'No video camera was found on your device. Please type the key manually.',
                canRetry: false,
                showGuide: false
            };
        }
        if (errName === 'NotReadableError' || errName === 'TrackStartError' || /notreadableerror|trackstart|in use/i.test(errStr)) {
            return {
                title: 'Camera In Use',
                message: 'The camera is being used by another application. Please close other camera apps and retry.',
                canRetry: true,
                showGuide: false
            };
        }
        return {
            title: 'Camera Access Error',
            message: (err && err.message) ? err.message : 'Unable to access camera preview. Please type the key manually.',
            canRetry: true,
            showGuide: false
        };
    }

    function handleCameraError(err) {
        var cat = categorizeError(err);
        if (loadingOverlay) loadingOverlay.classList.add('d-none');
        if (reticle)        reticle.classList.add('d-none');
        if (errorOverlay)   errorOverlay.classList.remove('d-none');

        var t = document.getElementById('modalScannerErrorTitle');
        var m = document.getElementById('modalScannerErrorMsg');
        if (t) t.textContent = cat.title;
        if (m) m.textContent = cat.message;

        if (settingsGuide) {
            if (cat.showGuide) settingsGuide.classList.remove('d-none');
            else settingsGuide.classList.add('d-none');
        }
        if (btnRetryCamera) {
            if (cat.canRetry) btnRetryCamera.classList.remove('d-none');
            else btnRetryCamera.classList.add('d-none');
        }
        if (statusText) {
            statusText.innerHTML = '<i class="bi bi-exclamation-circle text-danger me-1"></i>Scanner unavailable.';
        }
    }

    function populateCameras(cameras, selectedId) {
        if (!cameraSelect || !cameraSelectBox) return;
        cameraSelect.innerHTML = '';
        if (!cameras || cameras.length <= 1) {
            cameraSelectBox.classList.add('d-none');
            return;
        }
        cameras.forEach(function(cam, idx) {
            var opt = document.createElement('option');
            opt.value = cam.id;
            opt.textContent = cam.label || ('Camera ' + (idx + 1));
            if (selectedId && cam.id === selectedId) {
                opt.selected = true;
            } else if (!selectedId && /back|rear|environment/i.test(cam.label)) {
                opt.selected = true;
            }
            cameraSelect.appendChild(opt);
        });
        cameraSelectBox.classList.remove('d-none');
    }

    function startHtml5Scanner(preferredCameraId) {
        if (isScanning) return;
        if (typeof Html5Qrcode === 'undefined') {
            setTimeout(function() {
                if (typeof Html5Qrcode !== 'undefined') startHtml5Scanner(preferredCameraId);
                else handleCameraError({ name: 'ScannerLibraryUnavailable', message: 'QR scanner module loading... please try again.' });
            }, 300);
            return;
        }

        if (!html5QrCode) {
            try {
                html5QrCode = new Html5Qrcode("modalQrReader");
            } catch (e) {
                handleCameraError(e);
                return;
            }
        }

        var config = {
            fps: 10,
            qrbox: function(viewfinderWidth, viewfinderHeight) {
                var minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                var edge = Math.floor(minEdge * 0.72);
                return { width: Math.max(edge, 160), height: Math.max(edge, 160) };
            },
            aspectRatio: 1.0,
            experimentalFeatures: { useBarCodeDetectorIfSupported: true }
        };

        function startWithCam(camConfig) {
            return html5QrCode.start(
                camConfig,
                config,
                onScanSuccess,
                function() {} // onScanFailure silent
            ).then(function() {
                isScanning = true;
                if (loadingOverlay) loadingOverlay.classList.add('d-none');
                if (reticle)        reticle.classList.remove('d-none');
                if (statusText)     statusText.innerHTML = '<i class="bi bi-camera me-1 text-success"></i>Camera active. Position QR code inside the frame.';
            }).catch(function(err) {
                // If environment failed, try user/front facing camera fallback
                if (camConfig && (camConfig === "environment" || (typeof camConfig === 'object' && camConfig.facingMode === "environment"))) {
                    return html5QrCode.start(
                        { facingMode: "user" },
                        config,
                        onScanSuccess,
                        function() {}
                    ).then(function() {
                        isScanning = true;
                        if (loadingOverlay) loadingOverlay.classList.add('d-none');
                        if (reticle)        reticle.classList.remove('d-none');
                        if (statusText)     statusText.innerHTML = '<i class="bi bi-camera me-1 text-success"></i>Camera active. Position QR code inside the frame.';
                    }).catch(function(err2) {
                        handleCameraError(err2);
                    });
                }
                handleCameraError(err);
            });
        }

        Html5Qrcode.getCameras().then(function(cameras) {
            if (cameras && cameras.length > 0) {
                populateCameras(cameras, preferredCameraId);
                var chosen = preferredCameraId ? preferredCameraId : { facingMode: "environment" };
                startWithCam(chosen);
            } else {
                startWithCam({ facingMode: "environment" });
            }
        }).catch(function() {
            startWithCam({ facingMode: "environment" });
        });
    }

    function requestCameraAndStart(isRetry) {
        if (isScanning) return;
        if (isRequestingPerm && !isRetry) return;

        isRequestingPerm = true;
        resetScannerUI();

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            isRequestingPerm = false;
            handleCameraError({ name: 'InsecureContextError' });
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" } } }).then(function(stream) {
            isRequestingPerm = false;
            if (stream && stream.getTracks) {
                stream.getTracks().forEach(function(track) { track.stop(); });
            }
            setTimeout(function() {
                startHtml5Scanner();
            }, 200);
        }).catch(function(err) {
            isRequestingPerm = false;
            // Overconstrained fallback
            if (err && (err.name === 'OverconstrainedError' || err.name === 'ConstraintNotSatisfiedError')) {
                navigator.mediaDevices.getUserMedia({ video: true }).then(function(s2) {
                    if (s2 && s2.getTracks) {
                        s2.getTracks().forEach(function(track) { track.stop(); });
                    }
                    setTimeout(function() {
                        startHtml5Scanner();
                    }, 200);
                }).catch(function(err2) {
                    handleCameraError(err2);
                });
                return;
            }
            handleCameraError(err);
        });
    }

    function stopScanner() {
        isRequestingPerm = false;
        var p = Promise.resolve();
        if (html5QrCode && isScanning) {
            isScanning = false;
            p = html5QrCode.stop().then(function() {
                return html5QrCode.clear();
            }).catch(function(err) {
                console.warn("QR scanner stop error:", err);
            });
        }
        try {
            var vEls = document.querySelectorAll('#modalQrReader video');
            vEls.forEach(function(v) {
                if (v && v.srcObject && v.srcObject.getTracks) {
                    v.srcObject.getTracks().forEach(function(t) { t.stop(); });
                }
            });
        } catch (e) {}
        return p;
    }

    function showTemporaryFeedback(msg) {
        if (feedbackBox && feedbackText) {
            feedbackText.textContent = msg;
            feedbackBox.classList.remove('d-none');
            setTimeout(function() {
                feedbackBox.classList.add('d-none');
            }, 3000);
        }
    }

    function onScanSuccess(decodedText) {
        if (scanCooldown) return;
        scanCooldown = true;

        var key = window.extractActivationKey(decodedText);
        if (!key) {
            showTemporaryFeedback("Scanned QR code does not contain a valid VendoLink activation key.");
            setTimeout(function() { scanCooldown = false; }, 2000);
            return;
        }

        // Valid key obtained!
        playBeep();
        if (navigator.vibrate) {
            try { navigator.vibrate(80); } catch (e) {}
        }

        if (inputKey) {
            inputKey.value = key;
            inputKey.dispatchEvent(new Event('input', { bubbles: true }));
            inputKey.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Hide scanner and display confirmation alert
        stopScanner().then(function() {
            if (scannerBox) scannerBox.classList.add('d-none');
            if (btnToggleQr) {
                btnToggleQr.innerHTML = '<i class="bi bi-qr-code-scan"></i> <span class="d-none d-sm-inline">Scan QR</span>';
            }
            if (successAlert) {
                if (successKeyText) successKeyText.textContent = key;
                successAlert.classList.remove('d-none');
                successAlert.classList.add('d-flex');
            }
            if (inputNickname) {
                inputNickname.focus();
            }
        }).finally(function() {
            scanCooldown = false;
        });
    }

    // Toggle button in input group
    if (btnToggleQr) {
        btnToggleQr.addEventListener('click', function() {
            if (scannerBox && !scannerBox.classList.contains('d-none')) {
                // Close scanner
                stopScanner().then(function() {
                    scannerBox.classList.add('d-none');
                    btnToggleQr.innerHTML = '<i class="bi bi-qr-code-scan"></i> <span class="d-none d-sm-inline">Scan QR</span>';
                });
            } else {
                // Open scanner
                if (scannerBox) scannerBox.classList.remove('d-none');
                btnToggleQr.innerHTML = '<i class="bi bi-x-lg"></i> <span class="d-none d-sm-inline">Close</span>';
                requestCameraAndStart(false);
            }
        });
    }

    // Close buttons inside scanner box
    if (btnCloseScanner) {
        btnCloseScanner.addEventListener('click', function() {
            stopScanner().then(function() {
                if (scannerBox) scannerBox.classList.add('d-none');
                if (btnToggleQr) {
                    btnToggleQr.innerHTML = '<i class="bi bi-qr-code-scan"></i> <span class="d-none d-sm-inline">Scan QR</span>';
                }
            });
        });
    }

    if (btnEnterManual) {
        btnEnterManual.addEventListener('click', function() {
            stopScanner().then(function() {
                if (scannerBox) scannerBox.classList.add('d-none');
                if (btnToggleQr) {
                    btnToggleQr.innerHTML = '<i class="bi bi-qr-code-scan"></i> <span class="d-none d-sm-inline">Scan QR</span>';
                }
                if (inputKey) inputKey.focus();
            });
        });
    }

    if (btnRetryCamera) {
        btnRetryCamera.addEventListener('click', function() {
            requestCameraAndStart(true);
        });
    }

    function triggerModalPhotoScan() {
        if (fileInput) fileInput.click();
    }
    if (btnUploadPhoto) {
        btnUploadPhoto.addEventListener('click', triggerModalPhotoScan);
    }
    if (btnUploadPhotoError) {
        btnUploadPhotoError.addEventListener('click', triggerModalPhotoScan);
    }

    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            var file = e.target.files && e.target.files[0];
            if (!file) return;

            if (!html5QrCode) {
                try { html5QrCode = new Html5Qrcode("modalQrReader"); } catch (err) {}
            }

            if (statusText) {
                statusText.innerHTML = '<span class="spinner-border spinner-border-sm me-1 text-info"></span>Scanning image...';
            }

            html5QrCode.scanFile(file, false)
                .then(function(decodedText) {
                    onScanSuccess(decodedText);
                })
                .catch(function(err) {
                    showTemporaryFeedback("Could not find a valid QR code in photo. Please take a clear, close-up picture.");
                    if (statusText) {
                        statusText.innerHTML = '<i class="bi bi-exclamation-triangle text-warning me-1"></i>No QR code detected in photo.';
                    }
                })
                .finally(function() {
                    fileInput.value = '';
                });
        });
    }

    if (cameraSelect) {
        cameraSelect.addEventListener('change', function() {
            var newId = this.value;
            stopScanner().then(function() {
                startHtml5Scanner(newId);
            });
        });
    }

    // Modal lifecycle: stop camera when modal closes
    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function() {
            stopScanner().then(function() {
                if (scannerBox) scannerBox.classList.add('d-none');
                if (btnToggleQr) {
                    btnToggleQr.innerHTML = '<i class="bi bi-qr-code-scan"></i> <span class="d-none d-sm-inline">Scan QR</span>';
                }
                resetScannerUI();
            });
        });
    }

    window.addEventListener('beforeunload', stopScanner);
    window.addEventListener('pagehide', stopScanner);

})();

function handleAddMachineSubmit(form) {
    const btn = document.getElementById('btnAddMachineSubmit');
    const input = document.getElementById('modalProductKey');
    if (!input || !input.value.trim()) {
        alert('Please enter a valid Product Activation Key.');
        if (input) input.focus();
        return false;
    }
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Activating...';
    }
    return true;
}
</script>
