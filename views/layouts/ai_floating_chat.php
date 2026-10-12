<?php
/**
 * ============================================================
 * VendoLink — Floating VendoAI Chatbot Widget
 * ============================================================
 *
 * Renders a sticky floating chat bubble in the bottom-right corner
 * accessible across all dashboard pages for instant AI assistance.
 * Features strict background scroll locking on Mobile & Desktop.
 *
 * @package VendoLink
 * @version 3.1
 */
?>
<!-- VendoAI Floating Chat Button -->
<div id="vendoAiWidgetContainer">

    <!-- Floating Circular Button -->
    <button type="button" id="vendoAiFloatBtn" class="shadow-lg" title="Ask VendoAI Assistant" aria-label="Open VendoAI Chatbot">
        <i class="bi bi-robot fs-4 text-dark"></i>
        <span class="vendo-ai-badge">AI</span>
    </button>

    <!-- Floating Chat Popup Box -->
    <div id="vendoAiChatBox" class="vendo-ai-chat-box shadow-lg d-none">
        <!-- Header (Sticky / Fixed) -->
        <div class="vendo-ai-chat-header">
            <div class="d-flex align-items-center gap-2">
                <div class="p-1.5 bg-info bg-opacity-20 text-info rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                    <i class="bi bi-robot fs-5"></i>
                </div>
                <div>
                    <h6 class="mb-0 text-light font-bold" style="font-size: .92rem;">VendoAI Assistant</h6>
                    <span class="text-success small" style="font-size: .72rem;"><i class="bi bi-circle-fill me-1"></i>Live Telemetry AI Engine</span>
                </div>
            </div>
            <button type="button" id="vendoAiCloseBtn" class="btn-close btn-close-white small" aria-label="Close VendoAI Chat"></button>
        </div>

        <!-- Message Body Stream (Independently Scrollable) -->
        <div id="vendoAiMsgStream" class="vendo-ai-msg-stream p-3">
            <div class="vendo-ai-msg vendo-ai-msg-bot mb-3">
                <div class="msg-author text-cyan small mb-1"><i class="bi bi-robot me-1"></i>VendoAI</div>
                <div class="msg-bubble">
                    Hello! I'm your **VendoAI Smart Assistant**. Ask me about sales revenue forecasts, reservoir refill dates, or cash audit schedules!
                </div>
            </div>
        </div>

        <!-- Quick Prompt Chips (Sticky / Fixed) -->
        <div class="vendo-ai-prompt-chips p-2.5 border-top border-secondary border-opacity-25 bg-dark d-flex flex-wrap align-items-center justify-content-between gap-1.5">
            <button type="button" class="btn btn-xs btn-outline-info text-nowrap rounded-pill font-semibold py-1.5 px-2.5 flex-fill" style="font-size: 0.74rem;" onclick="sendVendoAiPrompt('Predict next month sales revenue')">
                📈 Predict Revenue
            </button>
            <button type="button" class="btn btn-xs btn-outline-warning text-nowrap rounded-pill font-semibold py-1.5 px-2.5 flex-fill" style="font-size: 0.74rem;" onclick="sendVendoAiPrompt('When should I refill tanks')">
                💧 Refill Forecast
            </button>
            <button type="button" class="btn btn-xs btn-outline-success text-nowrap rounded-pill font-semibold py-1.5 px-2.5 flex-fill" style="font-size: 0.74rem;" onclick="sendVendoAiPrompt('Recommend cash collection schedule')">
                💵 Cash Schedule
            </button>
        </div>

        <!-- Footer Input Form (Sticky / Fixed) -->
        <div class="vendo-ai-chat-footer p-2 border-top border-secondary border-opacity-25 bg-dark">
            <form id="vendoAiForm" onsubmit="handleVendoAiSubmit(event)">
                <div class="input-group input-group-sm">
                    <input type="text" id="vendoAiInput" class="form-control bg-dark text-light border-secondary border-opacity-25 font-monospace" placeholder="Type question..." required autocomplete="off">
                    <button type="submit" id="vendoAiSendBtn" class="btn btn-info text-dark font-bold">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
            </form>
    </div>

</div>

<!-- Session Inactivity Warning Modal -->
<div class="modal fade" id="inactivityWarningModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="inactivityModalLabel" aria-hidden="true" style="z-index: 10050;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px; width: 90%; margin: 1.75rem auto;">
        <div class="modal-content text-light text-center p-3" style="background: #0f172a; border: 1px solid rgba(245, 158, 11, 0.5) !important; border-radius: 16px; box-shadow: 0 16px 40px rgba(0,0,0,0.8) !important;">
            <div class="modal-body py-3">
                <div class="p-3 bg-warning bg-opacity-10 text-warning rounded-circle d-inline-flex mb-3">
                    <i class="bi bi-shield-exclamation fs-1"></i>
                </div>
                <h5 class="modal-title font-bold text-light mb-2" id="inactivityModalLabel">Session Inactivity Warning</h5>
                <p class="text-secondary small mb-3">
                    You have been inactive for 14 minutes. For security, your session will expire in:
                </p>
                <div class="display-6 font-monospace text-warning font-bold my-2" id="inactivityTimerCount">00:59</div>
                <p class="text-muted small mb-3" style="font-size: 0.76rem;">Click below or move mouse to stay logged in.</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-warning text-dark font-bold py-2 shadow" onclick="resetInactivityTimer()">
                        <i class="bi bi-check-circle-fill me-1"></i> Stay Logged In
                    </button>
                    <a href="<?= BASE_URL ?>/auth/logout.php?reason=inactivity" class="btn btn-sm btn-outline-secondary">
                        Log Out Now
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true" style="z-index: 10040;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px; width: 90%; margin: 1.75rem auto;">
        <div class="modal-content text-light text-center p-3" style="background: #0f172a; border: 1px solid var(--vl-border) !important; border-radius: 16px; box-shadow: 0 16px 40px rgba(0,0,0,0.8) !important;">
            <div class="modal-body py-3">
                <div class="p-3 bg-danger bg-opacity-10 text-danger rounded-circle d-inline-flex mb-3">
                    <i class="bi bi-box-arrow-right fs-1"></i>
                </div>
                <h5 class="modal-title font-bold text-light mb-2" id="logoutModalLabel">Sign Out Confirmation</h5>
                <p class="text-secondary small mb-4">
                    Are you sure you want to sign out of your VendoLink session?
                </p>
                <div class="d-grid gap-2">
                    <button id="logoutConfirmActionBtn" type="button"
                            data-logout-url="<?= BASE_URL ?>/auth/logout.php"
                            onclick="window.location.href='<?= BASE_URL ?>/auth/logout.php';"
                            class="btn btn-danger font-bold py-2 shadow w-100">
                        <i class="bi bi-box-arrow-right me-1"></i> Yes, Sign Out
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-2" data-bs-dismiss="modal">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Save Changes Confirmation Modal -->
<div class="modal fade" id="saveConfirmModal" tabindex="-1" aria-labelledby="saveModalLabel" aria-hidden="true" style="z-index: 10040;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px; width: 90%; margin: 1.75rem auto;">
        <div class="modal-content text-light text-center p-3" style="background: #0f172a; border: 1px solid rgba(56, 189, 248, 0.4) !important; border-radius: 16px; box-shadow: 0 16px 40px rgba(0,0,0,0.8) !important;">
            <div class="modal-body py-3">
                <div class="p-3 bg-info bg-opacity-10 text-info rounded-circle d-inline-flex mb-3">
                    <i class="bi bi-floppy-fill fs-1"></i>
                </div>
                <h5 class="modal-title font-bold text-light mb-2" id="saveModalLabel">Save Settings</h5>
                <p class="text-secondary small mb-4" id="saveModalMessage">
                    Are you sure you want to save these updated configurations?
                </p>
                <div class="d-grid gap-2">
                    <button type="button" id="saveConfirmActionBtn" class="btn btn-info text-dark font-bold py-2 shadow">
                        <i class="bi bi-check-circle-fill me-1"></i> Confirm & Save
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-2" data-bs-dismiss="modal">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Custom Compact Modal CSS -->
<style>
#inactivityWarningModal .modal-dialog,
#logoutConfirmModal .modal-dialog,
#saveConfirmModal .modal-dialog {
    max-width: 380px !important;
    width: 90% !important;
    margin: 1.75rem auto !important;
}
</style>

<!-- VendoAI Floating Widget Styles -->
<style>
/* Scroll lock class applied to body when chatbot panel is open */
body.chat-open {
    overscroll-behavior: none !important;
}

#vendoAiFloatBtn {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 54px;
    height: 54px;
    border-radius: 50%;
    background: linear-gradient(135deg, #38bdf8, #0eac5e);
    border: 2px solid rgba(255,255,255,0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 9999;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
#vendoAiFloatBtn:hover {
    transform: scale(1.08);
    box-shadow: 0 10px 25px rgba(56, 189, 248, 0.5) !important;
}
.vendo-ai-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #ef4444;
    color: #fff;
    font-size: 0.65rem;
    font-weight: 800;
    padding: 2px 6px;
    border-radius: 10px;
    border: 1px solid #ffffff;
}

.vendo-ai-chat-box {
    position: fixed;
    bottom: 90px;
    right: 24px;
    width: 410px;
    max-width: calc(100vw - 48px);
    height: 480px;
    max-height: calc(100vh - 110px);
    background: #0f172a;
    border: 1px solid var(--vl-border);
    border-radius: 16px;
    z-index: 9999;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 16px 40px rgba(0,0,0,0.6) !important;
    animation: vendoAiSlideUp 0.25s ease-out;
}

@media (max-width: 575.98px) {
    #vendoAiFloatBtn {
        bottom: 16px;
        right: 16px;
        width: 48px;
        height: 48px;
    }
    .vendo-ai-chat-box {
        bottom: 74px;
        right: 16px;
        width: calc(100vw - 32px);
        max-width: 380px;
        height: 460px;
        max-height: calc(100vh - 90px);
        border-radius: 14px;
    }
}

@keyframes vendoAiSlideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.vendo-ai-chat-header {
    flex-shrink: 0;
    background: var(--vl-surface-2);
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--vl-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.vendo-ai-prompt-chips,
.vendo-ai-chat-footer {
    flex-shrink: 0;
}

.vendo-ai-msg-stream {
    flex: 1 1 auto;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
    -webkit-overflow-scrolling: touch !important;
    touch-action: pan-y !important;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    background: rgba(6, 24, 38, 0.6);
}

.vendo-ai-msg-user {
    align-self: flex-end;
    max-width: 85%;
}
.vendo-ai-msg-user .msg-bubble {
    background: rgba(56, 189, 248, 0.22) !important;
    border: 1px solid rgba(56, 189, 248, 0.5) !important;
    color: #ffffff !important;
    padding: 0.65rem 0.95rem !important;
    border-radius: 14px 14px 2px 14px !important;
    font-size: 0.86rem !important;
    font-weight: 600 !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3) !important;
}

.vendo-ai-msg-bot {
    align-self: flex-start;
    max-width: 88%;
}
.vendo-ai-msg-bot .msg-bubble {
    background: var(--vl-surface-2) !important;
    color: var(--vl-text-primary) !important;
    border: 1px solid var(--vl-border) !important;
    padding: 0.7rem 0.95rem !important;
    border-radius: 14px 14px 14px 2px !important;
    font-size: 0.84rem !important;
    line-height: 1.5 !important;
    white-space: pre-line;
}
</style>

<!-- VendoAI Floating Chat Logic -->
<script>
let vendoAiSavedScrollY = 0;
let isVendoAiChatOpen = false;

function onAiWheelLock(e) {
    if (!isVendoAiChatOpen) return;
    const stream = document.getElementById('vendoAiMsgStream');
    if (stream && stream.contains(e.target)) {
        const delta = e.deltaY;
        const isAtTop = stream.scrollTop <= 0 && delta < 0;
        const isAtBottom = (stream.scrollTop + stream.clientHeight >= stream.scrollHeight - 1) && delta > 0;
        if (isAtTop || isAtBottom) {
            e.preventDefault();
        }
        return;
    }
    e.preventDefault();
}

function onAiTouchMoveLock(e) {
    if (!isVendoAiChatOpen) return;
    const stream = document.getElementById('vendoAiMsgStream');
    if (stream && stream.contains(e.target)) {
        return;
    }
    e.preventDefault();
}

function onAiKeyDownLock(e) {
    if (!isVendoAiChatOpen) return;
    if (e.key === 'Escape') {
        closeVendoAiChat();
        return;
    }
    const scrollKeys = ['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' '];
    if (scrollKeys.includes(e.key)) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        const stream = document.getElementById('vendoAiMsgStream');
        if (stream && stream.contains(e.target)) return;
        e.preventDefault();
    }
}

function onAiScrollLock() {
    if (!isVendoAiChatOpen) return;
    const currentY = window.pageYOffset || document.documentElement.scrollTop || window.scrollY || 0;
    if (Math.abs(currentY - vendoAiSavedScrollY) > 0) {
        window.scrollTo({ top: vendoAiSavedScrollY, behavior: 'instant' });
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const floatBtn = document.getElementById('vendoAiFloatBtn');
    const chatBox  = document.getElementById('vendoAiChatBox');
    const closeBtn = document.getElementById('vendoAiCloseBtn');

    if (floatBtn && chatBox) {
        floatBtn.addEventListener('click', function () {
            if (chatBox.classList.contains('d-none')) {
                openVendoAiChat();
            } else {
                closeVendoAiChat();
            }
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            closeVendoAiChat();
        });
    }
});

function openVendoAiChat() {
    const chatBox = document.getElementById('vendoAiChatBox');
    if (!chatBox || !chatBox.classList.contains('d-none')) return;

    // 1. Capture exact background scroll position
    vendoAiSavedScrollY = window.pageYOffset || document.documentElement.scrollTop || window.scrollY || 0;
    isVendoAiChatOpen = true;

    // 2. Show chat box
    chatBox.classList.remove('d-none');
    document.body.classList.add('chat-open');

    // 3. Attach non-passive listeners to strictly lock background movement
    window.addEventListener('wheel', onAiWheelLock, { passive: false });
    window.addEventListener('touchmove', onAiTouchMoveLock, { passive: false });
    window.addEventListener('keydown', onAiKeyDownLock, { passive: false });
    window.addEventListener('scroll', onAiScrollLock, { passive: true });

    // 4. Focus input and scroll message stream to latest message
    document.getElementById('vendoAiInput')?.focus();
    const stream = document.getElementById('vendoAiMsgStream');
    if (stream) stream.scrollTop = stream.scrollHeight;
}

function closeVendoAiChat() {
    const chatBox = document.getElementById('vendoAiChatBox');
    if (!chatBox || chatBox.classList.contains('d-none')) return;

    // 1. Remove active listeners
    window.removeEventListener('wheel', onAiWheelLock);
    window.removeEventListener('touchmove', onAiTouchMoveLock);
    window.removeEventListener('keydown', onAiKeyDownLock);
    window.removeEventListener('scroll', onAiScrollLock);

    isVendoAiChatOpen = false;

    // 2. Hide chat box
    chatBox.classList.add('d-none');
    document.body.classList.remove('chat-open');

    // 3. Restore background scroll position with zero jumping
    window.scrollTo({ top: vendoAiSavedScrollY, behavior: 'instant' });
}

function parseAiMarkdown(text) {
    if (!text) return '';
    let formatted = escapeHtml(text);
    // Convert **bold** to <strong>bold</strong>
    formatted = formatted.replace(/\*\*(.*?)\*\*/g, '<strong class="text-cyan">$1</strong>');
    // Convert * bullet to •
    formatted = formatted.replace(/^\*\s+/gm, '• ');
    // Convert newlines to <br>
    formatted = formatted.replace(/\n/g, '<br>');
    return formatted;
}

function sendVendoAiPrompt(promptText) {
    const input = document.getElementById('vendoAiInput');
    if (input) {
        input.value = promptText;
        document.getElementById('vendoAiForm')?.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    }
}

function handleVendoAiSubmit(e) {
    e.preventDefault();
    const input = document.getElementById('vendoAiInput');
    const stream = document.getElementById('vendoAiMsgStream');
    const prompt = input.value.trim();
    if (!prompt || !stream) return;

    // 1. Append User Message
    const userDiv = document.createElement('div');
    userDiv.className = 'vendo-ai-msg vendo-ai-msg-user mb-2';
    userDiv.innerHTML = `<div class="msg-bubble">${escapeHtml(prompt)}</div>`;
    stream.appendChild(userDiv);

    input.value = '';
    stream.scrollTop = stream.scrollHeight;

    // 2. Append Loading Placeholder
    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'vendo-ai-msg vendo-ai-msg-bot mb-2';
    loadingDiv.innerHTML = `<div class="msg-author text-cyan small mb-1"><i class="bi bi-robot me-1"></i>VendoAI</div><div class="msg-bubble text-muted small"><i class="bi bi-three-dots loading-dots me-1"></i>Analyzing telemetry...</div>`;
    stream.appendChild(loadingDiv);
    stream.scrollTop = stream.scrollHeight;

    // 3. Call AJAX API Endpoint
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    const csrfToken = csrfInput ? csrfInput.value : '';

    fetch('<?= BASE_URL ?>/api/ai/chat.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ prompt: prompt })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadingDiv.querySelector('.msg-bubble').innerHTML = parseAiMarkdown(data.answer);
        } else {
            loadingDiv.querySelector('.msg-bubble').innerHTML = '⚠️ Error: Could not retrieve AI telemetry.';
        }
        stream.scrollTop = stream.scrollHeight;
    })
    .catch(() => {
        loadingDiv.querySelector('.msg-bubble').innerHTML = '⚠️ Connection error. Please try again.';
        stream.scrollTop = stream.scrollHeight;
    });
}
</script>
