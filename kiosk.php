<?php
/**
 * kiosk.php — iPad Door Kiosk for Parent Sign-In/Out
 *
 * Streamlined 3-screen flow:
 *   1. Welcome (tap anywhere)
 *   2. Phone + PIN combined (single numpad, auto-advance, auto-submit)
 *   3. Children (one-tap sign in/out, auto-action for single child)
 *   + brief confirmation overlay (3s)
 */
require_once "include/config.php";
require_once "include/csrf.php";
$csrfToken = csrf_token();

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$mobileBaseUrl = "{$protocol}://{$host}{$path}/kiosk-mobile.php";
$kioskCssVersion = @filemtime(__DIR__ . '/css/kiosk.css');
if (!$kioskCssVersion) {
    $kioskCssVersion = time();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Bhutanese Language and Culture School">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#881b12">
    <title>Bhutanese Language and Culture School</title>
    <link rel="icon" type="image/jpeg" href="bbccassests/img/logo/logo5.jpg">
    <link rel="apple-touch-icon" href="bbccassests/img/logo/logo5.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/kiosk.css?v=<?= (int)$kioskCssVersion ?>">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head>
<body>

<div class="kiosk-app" id="kioskApp">

    <!-- Header -->
    <header class="kiosk-header">
        <div class="kiosk-header__brand">
            <img src="bbccassests/img/logo/logo5.jpg" alt="BBCC" class="kiosk-header__logo" onerror="this.style.display='none'">
            <div>
                <div class="kiosk-header__title">Bhutanese Language and Culture School</div>
                <div class="kiosk-header__subtitle">Bhutanese Buddhist &amp; Cultural Centre</div>
            </div>
        </div>
        <div class="kiosk-header__clock">
            <div class="kiosk-header__time" id="clockTime">--:--</div>
            <div class="kiosk-header__date" id="clockDate">--</div>
        </div>
    </header>

    <!-- Screens -->
    <div class="kiosk-screens" id="screens">

        <!-- SCREEN 1: Welcome (tap anywhere) -->
        <div class="kiosk-screen active" id="screenIdle">
            <div class="idle-content" id="idleTap">
                <div class="idle-front-card">
                    <img src="bbccassests/img/logo/logo5.jpg" alt="BBCC" class="idle-front-logo" onerror="this.style.display='none'">
                    <div class="idle-front-title">Bhutanese Language and Culture School</div>
                    <h1 class="idle-content__heading">Welcome</h1>
                    <div class="idle-front-qr-wrap">
                        <div id="idleBigQr" class="idle-front-qr"></div>
                    </div>
                    <p class="idle-front-instruction">
                        Scan this QR code with your phone
                        <span>or tap anywhere to sign your child in or out</span>
                    </p>
                    <div class="idle-content__hint">
                        <i class="fa-solid fa-hand-pointer"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- SCREEN 2: Phone + PIN (both visible) -->
        <div class="kiosk-screen" id="screenAuth">
            <div class="kiosk-panel">
                <div style="display:flex;justify-content:center;margin-bottom:10px;">
                    <img src="bbccassests/img/logo/logo5.jpg" alt="BBCC" class="idle-content__logo" style="margin:0;" onerror="this.style.display='none'">
                </div>
                <h2 class="kiosk-panel__heading">Enter your details</h2>

                <!-- Phone field -->
                <div class="kiosk-field" id="phoneSection">
                    <label class="kiosk-field-label"><i class="fa-solid fa-mobile-screen-button"></i> Phone Number</label>
                    <div class="kiosk-display focused" id="phoneDisplay">
                        <span id="phoneValue"></span><span class="cursor"></span>
                    </div>
                </div>

                <!-- PIN field -->
                <div class="kiosk-field" id="pinSection">
                    <label class="kiosk-field-label"><i class="fa-solid fa-lock"></i> PIN</label>
                    <div class="kiosk-display kiosk-display--pin" id="pinDisplay">
                        <span id="pinValue"></span><span class="cursor"></span>
                    </div>
                </div>

                <!-- Error message -->
                <div class="kiosk-error" id="authError">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span id="authErrorText"></span>
                </div>

                <!-- Spinner -->
                <div class="kiosk-spinner" id="authSpinner"></div>

                <!-- Numpad -->
                <div class="kiosk-numpad" id="numpad">
                    <button class="kiosk-numpad__key" data-key="1" type="button">1</button>
                    <button class="kiosk-numpad__key" data-key="2" type="button">2</button>
                    <button class="kiosk-numpad__key" data-key="3" type="button">3</button>
                    <button class="kiosk-numpad__key" data-key="4" type="button">4</button>
                    <button class="kiosk-numpad__key" data-key="5" type="button">5</button>
                    <button class="kiosk-numpad__key" data-key="6" type="button">6</button>
                    <button class="kiosk-numpad__key" data-key="7" type="button">7</button>
                    <button class="kiosk-numpad__key" data-key="8" type="button">8</button>
                    <button class="kiosk-numpad__key" data-key="9" type="button">9</button>
                    <button class="kiosk-numpad__key kiosk-numpad__key--clear" data-key="clear" type="button">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                    <button class="kiosk-numpad__key" data-key="0" type="button">0</button>
                    <button class="kiosk-numpad__key kiosk-numpad__key--back" data-key="back" type="button">
                        <i class="fa-solid fa-delete-left"></i>
                    </button>
                </div>

                <!-- Action buttons -->
                <div class="kiosk-auth-actions">
                    <button class="kiosk-back" id="authBack" type="button">
                        <i class="fa-solid fa-arrow-left"></i> Cancel
                    </button>
                    <button class="kiosk-btn kiosk-btn--primary kiosk-btn--verify" id="authVerify" type="button">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i> Log In
                    </button>
                </div>
                <div style="text-align:center; margin-top:10px;">
                    <a href="forgotKioskPin" target="_blank" rel="noopener" style="font-size:0.86rem; color:#9a3412; text-decoration:underline;">
                        Forgot PIN? Reset with phone number
                    </a>
                </div>
            </div>
            <div class="kiosk-timeout-bar" id="authTimeout"></div>
        </div>

        <!-- SCREEN 3: Children -->
        <div class="kiosk-screen" id="screenChildren">
            <div class="kiosk-children">
                <div class="kiosk-children__welcome">
                    <h2 id="welcomeName">Welcome!</h2>
                    <p id="welcomeSub">Tap to sign in or out</p>
                </div>

                <div id="childrenList"></div>

                <button class="kiosk-btn kiosk-btn--success kiosk-btn--block" id="childrenDone" type="button" style="margin-top:16px;font-weight:700;">
                    <i class="fa-solid fa-right-from-bracket"></i> Done
                </button>
            </div>
            <div class="kiosk-timeout-bar" id="childrenTimeout"></div>
        </div>

    </div>

    <!-- Confirmation Overlay (not a separate screen) -->
    <div class="kiosk-overlay" id="confirmOverlay">
        <div class="kiosk-confirm">
            <div class="kiosk-confirm__icon kiosk-confirm__icon--success" id="confirmIcon">
                <i class="fa-solid fa-check"></i>
            </div>
            <h2 class="kiosk-confirm__title" id="confirmTitle">Done!</h2>
            <p class="kiosk-confirm__detail" id="confirmDetail"></p>
            <div class="kiosk-confirm__time" id="confirmTime"></div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="kiosk-footer" id="kioskFooter" style="display:none;">
        <div class="kiosk-footer__timeout">
            <i class="fa-solid fa-clock"></i>
            <span>Resets in <strong id="footerTimer">45</strong>s</span>
        </div>
        <div>BBCC &copy; <?= date('Y') ?></div>
    </footer>

</div>

<script>
(function () {
    'use strict';

    var API      = 'kiosk-api.php';
    var TIMEOUT  = 45;
    var CONFIRM  = 3;
    var PHONE_MIN = 8;
    var PHONE_MAX = 15;
    var PIN_MIN  = 4;
    var PIN_MAX  = 6;
    var QR_ROTATE_SEC = 120;

    // State
    var csrf       = <?= json_encode($csrfToken) ?>;
    var phone      = '';
    var pin        = '';
    var activeField = 'phone'; // 'phone' or 'pin'
    var parentData = null;
    var timerId    = null;
    var timerSec   = 0;
    var submitting = false;
    var pendingActions = {}; // { childId: 'in' | 'out' }
    var mobileBaseUrl = <?= json_encode($mobileBaseUrl) ?>;
    var miniQrTimerId = null;

    function $(s) { return document.querySelector(s); }
    function $$(s) { return document.querySelectorAll(s); }

    // ═══ CLOCK ═══
    function tick() {
        var d = new Date();
        var h = d.getHours(), m = String(d.getMinutes()).padStart(2,'0');
        var ap = h >= 12 ? 'PM' : 'AM';
        $('#clockTime').textContent = (h%12||12) + ':' + m + ' ' + ap;
        var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var mos = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $('#clockDate').textContent = days[d.getDay()] + ', ' + d.getDate() + ' ' + mos[d.getMonth()] + ' ' + d.getFullYear();
    }
    tick(); setInterval(tick, 10000);

    // ═══ SCREENS ═══
    var cur = 'idle';
    var screens = { idle: $('#screenIdle'), auth: $('#screenAuth'), children: $('#screenChildren') };

    function go(name) {
        if (screens[cur]) {
            screens[cur].classList.remove('active');
            screens[cur].classList.add('slide-out');
            var p = cur;
            setTimeout(function(){ screens[p].classList.remove('slide-out'); }, 350);
        }
        cur = name;
        requestAnimationFrame(function(){ screens[name].classList.add('active'); });
        $('#kioskFooter').style.display = (name === 'idle') ? 'none' : 'flex';
        if (name === 'idle') stopTimer(); else startTimer();
        if (name === 'auth') startMiniQrRefresh();
    }

    // ═══ TIMER ═══
    function startTimer() {
        stopTimer();
        timerSec = TIMEOUT;
        $('#footerTimer').textContent = timerSec;
        timerId = setInterval(function() {
            timerSec--;
            $('#footerTimer').textContent = Math.max(0, timerSec);
            if (timerSec <= 0) idle();
        }, 1000);
    }
    function stopTimer() { if (timerId) { clearInterval(timerId); timerId = null; } }
    function bumpTimer() { if (cur !== 'idle') startTimer(); }
    document.addEventListener('touchstart', bumpTimer, { passive: true });
    document.addEventListener('mousedown', bumpTimer);

    // ═══ IDLE / RESET ═══
    function idle() {
        stopTimer();
        hideOverlay();
        phone = ''; pin = ''; activeField = 'phone'; parentData = null; submitting = false; pendingActions = {};
        updatePhone(); updatePin();
        setFocus('phone');
        hideError();
        $('#authSpinner').classList.remove('show');
        go('idle');
    }

    // ═══ SCREEN 1: IDLE — tap anywhere ═══
    $('#screenIdle').addEventListener('click', function() {
        phone = ''; pin = ''; activeField = 'phone';
        updatePhone(); updatePin();
        setFocus('phone');
        hideError();
        go('auth');
    });

    // ═══ SCREEN 2: AUTH — phone & pin side-by-side ═══
    function setFocus(field) {
        activeField = field;
        if (field === 'phone') {
            $('#phoneDisplay').classList.add('focused');
            $('#pinDisplay').classList.remove('focused');
        } else {
            $('#phoneDisplay').classList.remove('focused');
            $('#pinDisplay').classList.add('focused');
        }
    }

    // Tap field to switch focus
    $('#phoneSection').addEventListener('click', function() { setFocus('phone'); });
    $('#pinSection').addEventListener('click', function() { setFocus('pin'); });

    function formatPhoneDisplay(d) {
        if (d.length <= 4) return d;
        if (d.length <= 7) return d.slice(0,4)+' '+d.slice(4);
        return d.slice(0,4)+' '+d.slice(4,7)+' '+d.slice(7);
    }
    function updatePhone() { $('#phoneValue').textContent = formatPhoneDisplay(phone); }
    function updatePin() {
        var dots = '';
        for (var i = 0; i < pin.length; i++) dots += '\u25CF';
        $('#pinValue').textContent = dots;
    }

    function hideError() { $('#authError').classList.remove('show'); }
    function showError(msg) {
        $('#authErrorText').textContent = msg;
        $('#authError').classList.add('show');
    }

    // Numpad handler
    $$('#numpad .kiosk-numpad__key').forEach(function(key) {
        key.addEventListener('click', function() {
            if (submitting) return;
            hideError();
            var k = key.dataset.key;

            if (activeField === 'phone') {
                if (k === 'clear') { phone = ''; }
                else if (k === 'back') { phone = phone.slice(0,-1); }
                else if (phone.length < PHONE_MAX) { phone += k; }
                updatePhone();
            } else {
                if (k === 'clear') { pin = ''; }
                else if (k === 'back') { pin = pin.slice(0,-1); }
                else if (pin.length < PIN_MAX) { pin += k; }
                updatePin();

                // Auto-submit when PIN reaches min length and phone is valid
                if (pin.length >= PIN_MIN && phone.length >= PHONE_MIN && !submitting) {
                    setTimeout(function() { doAuth(); }, 300);
                }
            }
            bumpTimer();
        });
    });

    $('#authBack').addEventListener('click', idle);
    $('#authVerify').addEventListener('click', function() { doAuth(); });

    // Auth API call
    function doAuth() {
        if (submitting) return;
        if (phone.length < PHONE_MIN) { showError('Phone number too short.'); return; }
        if (pin.length < PIN_MIN) { showError('PIN must be at least 4 digits.'); return; }

        submitting = true;
        $('#authSpinner').classList.add('show');
        $('#numpad').style.pointerEvents = 'none';

        api({ action:'auth', phone:phone, pin:pin }).then(function(r) {
            $('#authSpinner').classList.remove('show');
            $('#numpad').style.pointerEvents = '';
            submitting = false;

            if (r.ok) {
                parentData = r.data;
                renderChildren();
                go('children');
            } else {
                pin = '';
                updatePin();
                showError(r.message);
            }
        });
    }

    // Auto-focus PIN after phone is filled (convenience, not auto-submit)
    // User can still tap fields to switch

    // ═══ SCREEN 3: CHILDREN ═══
    function renderChildren() {
        if (!parentData) return;
        $('#welcomeName').textContent = 'Hi, ' + parentData.parent_name + '!';

        var list = $('#childrenList');
        list.innerHTML = '';

        var kids = parentData.children;
        if (kids.length === 0) {
            list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--gray-600);">'
                + '<i class="fa-solid fa-circle-info" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>'
                + 'No enrolled children found.</div>';
            return;
        }

        // If single child with actionable status, show big action card
        var actionable = kids.filter(function(c) { return c.status !== 'done'; });

        kids.forEach(function(child) {
            var card = document.createElement('div');
            var isSingle = (actionable.length === 1 && child.status !== 'done');
            card.className = 'kiosk-child-card' + (isSingle ? ' kiosk-child-card--big' : '');

            var initials = child.student_name.split(' ').map(function(w){return w[0];}).join('').toUpperCase().slice(0,2);
            var statusHtml = '';
            var actionHtml = '';
            var queuedMode = pendingActions[String(child.id)] || '';

            if (child.status === 'done') {
                var tOut = fmtTime(child.time_out);
                statusHtml = '<span class="kiosk-child-card__status kiosk-child-card__status--done">'
                    + '<i class="fa-solid fa-circle-check"></i> Done (out ' + tOut + ')</span>';
            } else if (queuedMode === 'in') {
                statusHtml = '<span class="kiosk-child-card__status kiosk-child-card__status--in">'
                    + '<i class="fa-solid fa-clock"></i> Sign In selected</span>';
                actionHtml = '<button class="kiosk-btn kiosk-btn--outline' + (isSingle ? ' kiosk-btn--lg' : '') + '" data-child="'+child.id+'" data-mode="">'
                    + '<i class="fa-solid fa-rotate-left"></i> Undo</button>';
            } else if (queuedMode === 'out') {
                statusHtml = '<span class="kiosk-child-card__status kiosk-child-card__status--in">'
                    + '<i class="fa-solid fa-clock"></i> Sign Out selected</span>';
                actionHtml = '<button class="kiosk-btn kiosk-btn--outline' + (isSingle ? ' kiosk-btn--lg' : '') + '" data-child="'+child.id+'" data-mode="">'
                    + '<i class="fa-solid fa-rotate-left"></i> Undo</button>';
            } else if (child.status === 'none') {
                actionHtml = '<button class="kiosk-btn kiosk-btn--success' + (isSingle ? ' kiosk-btn--lg' : '') + '" data-child="'+child.id+'" data-mode="in">'
                    + '<i class="fa-solid fa-right-to-bracket"></i> Sign In</button>';
            } else if (child.status === 'signed_in') {
                var tIn = fmtTime(child.time_in);
                statusHtml = '<span class="kiosk-child-card__status kiosk-child-card__status--in">'
                    + 'In at ' + tIn + '</span>';
                actionHtml = '<button class="kiosk-btn kiosk-btn--danger' + (isSingle ? ' kiosk-btn--lg' : '') + '" data-child="'+child.id+'" data-mode="out">'
                    + '<i class="fa-solid fa-right-from-bracket"></i> Sign Out</button>';
            }

            card.innerHTML = '<div class="kiosk-child-card__info">'
                + '<div class="kiosk-child-card__avatar">' + initials + '</div>'
                + '<div>'
                + '<div class="kiosk-child-card__name">' + esc(child.student_name) + '</div>'
                + statusHtml
                + '</div></div>'
                + '<div class="kiosk-child-card__action">' + actionHtml + '</div>';

            list.appendChild(card);
        });

        // Attach handlers
        list.querySelectorAll('[data-child]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                queueSign(parseInt(btn.dataset.child,10), btn.dataset.mode || '');
            });
        });
        updateDoneButton();
    }

    function queueSign(childId, mode) {
        var key = String(childId);
        if (mode === 'in' || mode === 'out') pendingActions[key] = mode;
        else delete pendingActions[key];
        renderChildren();
    }

    function updateDoneButton() {
        var count = Object.keys(pendingActions).length;
        var btn = $('#childrenDone');
        if (!btn) return;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> '
            + (count > 0 ? ('Done & Submit (' + count + ')') : 'Done');
    }

    function submitQueuedSigns() {
        if (submitting) return;
        var queued = Object.keys(pendingActions).map(function(k) {
            return { child_id: parseInt(k, 10), mode: pendingActions[k] };
        }).filter(function(x) { return x.child_id > 0 && (x.mode === 'in' || x.mode === 'out'); });

        if (queued.length === 0) {
            idle();
            return;
        }

        submitting = true;
        api({
            action: 'sign_batch',
            parent_id: parentData.parent_id,
            actions: JSON.stringify(queued)
        }).then(function(r) {
            submitting = false;
            if (r.ok) {
                pendingActions = {};
                showOverlay({
                    batch: true,
                    message: (r.data && r.data.message) ? r.data.message : 'Attendance submitted successfully.',
                    success_count: (r.data && r.data.success_count) ? r.data.success_count : queued.length,
                    failed_count: (r.data && r.data.failed_count) ? r.data.failed_count : 0
                });
                setTimeout(function() {
                    hideOverlay();
                    idle();
                }, CONFIRM * 1000);
            } else {
                alert(r.message || 'Unable to submit attendance. Please try again.');
            }
        });
    }

    $('#childrenDone').addEventListener('click', submitQueuedSigns);

    // ═══ CONFIRMATION OVERLAY ═══
    function showOverlay(data) {
        var isBatch = !!data.batch;
        var isIn = (data.action === 'in');
        var icon = $('#confirmIcon');
        if (isBatch) {
            icon.className = 'kiosk-confirm__icon kiosk-confirm__icon--success';
            icon.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
            $('#confirmTitle').textContent = 'Submitted';
            $('#confirmDetail').textContent = data.message || 'Attendance submitted.';
            $('#confirmTime').textContent = (data.success_count || 0) + ' successful'
                + ((data.failed_count || 0) > 0 ? (', ' + data.failed_count + ' failed') : '');
        } else {
            icon.className = 'kiosk-confirm__icon ' + (isIn ? 'kiosk-confirm__icon--success' : 'kiosk-confirm__icon--out');
            icon.innerHTML = isIn
                ? '<i class="fa-solid fa-right-to-bracket"></i>'
                : '<i class="fa-solid fa-right-from-bracket"></i>';
            $('#confirmTitle').textContent = isIn ? 'Signed In!' : 'Signed Out!';
            $('#confirmDetail').textContent = data.child_name;
            $('#confirmTime').textContent = data.time;
        }
        $('#confirmOverlay').classList.add('show');
    }

    function hideOverlay() {
        $('#confirmOverlay').classList.remove('show');
    }

    // ═══ API ═══
    function api(data) {
        data._csrf = csrf;
        return fetch(API, { method:'POST', body: new URLSearchParams(data) })
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (j.csrf_error) {
                    return fetch(API, { method:'POST', body: new URLSearchParams({action:'csrf'}) })
                        .then(function(r){return r.json();})
                        .then(function(t){
                            if (t.ok) { csrf=t.token; data._csrf=csrf; return fetch(API,{method:'POST',body:new URLSearchParams(data)}).then(function(r){return r.json();}); }
                            return j;
                        });
                }
                return j;
            })
            .catch(function(){ return {ok:false, message:'Connection error.'}; });
    }

    // ═══ MINI QR ON AUTH SCREEN ═══
    function renderMiniQr(url) {
        var node = $('#authMiniQr');
        if (!node || typeof QRCode === 'undefined') return;
        node.innerHTML = '';
        new QRCode(node, {
            text: url,
            width: 112,
            height: 112,
            colorDark: '#1f2937',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    function renderIdleQr(url) {
        var node = $('#idleBigQr');
        if (!node || typeof QRCode === 'undefined') return;
        node.innerHTML = '';
        new QRCode(node, {
            text: url,
            width: 260,
            height: 260,
            colorDark: '#1f2937',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    function refreshMiniQr() {
        api({ action: 'generate_token' }).then(function(r) {
            if (!r || !r.ok || !r.token) return;
            var url = mobileBaseUrl + '?t=' + encodeURIComponent(r.token);
            renderMiniQr(url);
            renderIdleQr(url);
        });
    }

    function startMiniQrRefresh() {
        refreshMiniQr();
        if (miniQrTimerId) return;
        miniQrTimerId = setInterval(refreshMiniQr, QR_ROTATE_SEC * 1000);
    }

    // ═══ HELPERS ═══
    function fmtTime(t) {
        if (!t) return '';
        var p = t.split(':'), h = parseInt(p[0],10), ap = h>=12?'PM':'AM';
        return (h%12||12)+':'+p[1]+' '+ap;
    }
    function esc(s) { var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

    // ═══ PREVENT DEFAULTS ═══
    window.addEventListener('popstate', function(e){ e.preventDefault(); if(cur!=='idle') idle(); });
    history.pushState(null,'',location.href);
    document.addEventListener('contextmenu', function(e){e.preventDefault();});
    document.addEventListener('gesturestart', function(e){e.preventDefault();});
    document.addEventListener('gesturechange', function(e){e.preventDefault();});

    // ═══ KEYBOARD (desktop testing) ═══
    document.addEventListener('keydown', function(e) {
        if (cur === 'idle' && (e.key === 'Enter' || (e.key >= '0' && e.key <= '9'))) {
            $('#screenIdle').click();
            if (e.key >= '0' && e.key <= '9') {
                setTimeout(function(){ var b=$('#numpad [data-key="'+e.key+'"]'); if(b)b.click(); }, 100);
            }
            return;
        }
        if (cur === 'auth') {
            if (e.key >= '0' && e.key <= '9') { var b=$('#numpad [data-key="'+e.key+'"]'); if(b)b.click(); }
            else if (e.key === 'Backspace') { var b2=$('#numpad [data-key="back"]'); if(b2)b2.click(); }
            else if (e.key === 'Escape') { idle(); }
        }
    });

    // Preload mini QR once on page load.
    startMiniQrRefresh();

})();
</script>

</body>
</html>
