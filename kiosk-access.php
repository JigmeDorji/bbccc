<?php
/**
 * kiosk-access.php — Single-page kiosk entry
 * Shows QR code + direct phone/PIN sign-in on same page.
 */
require_once "include/config.php";
require_once "include/csrf.php";
$csrfToken = csrf_token();

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$base = "{$protocol}://{$host}{$path}";
$mobileBaseUrl = $base . "/kiosk-mobile.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#881b12">
    <meta name="apple-mobile-web-app-title" content="Bhutanese Language and Culture School">
    <title>Bhutanese Language and Culture School</title>
    <link rel="icon" type="image/jpeg" href="bbccassests/img/logo/logo5.jpg">
    <link rel="apple-touch-icon" href="bbccassests/img/logo/logo5.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/kiosk.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        html, body { min-height: 100%; }
        body { min-height: 100dvh; }
        .kiosk-app { min-height: 100dvh; }
        .access-wrap {
            flex: 1;
            padding: clamp(12px, 2.2vw, 24px);
            display: flex;
            flex-direction: column;
            gap: 14px;
            max-width: 820px;
            margin: 0 auto;
            width: 100%;
        }
        .access-card {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: 18px;
            box-shadow: var(--shadow-md);
            padding: 18px;
        }
        .access-main-title {
            text-align: center;
            font-family: var(--font-display);
            color: var(--brand);
            font-size: 1.6rem;
            font-weight: 800;
            margin: 0;
        }
        .access-main-sub {
            text-align: center;
            color: var(--gray-600);
            margin: 2px 0 0;
            font-size: .92rem;
        }
        .qr-frame {
            width: fit-content;
            margin: 8px auto 10px;
            background: #fff;
            border-radius: 14px;
            padding: 12px;
            border: 2px solid var(--brand);
            box-shadow: var(--shadow-sm);
        }
        #qrCode { width: var(--qr-size, 220px); height: var(--qr-size, 220px); }
        #qrCode img, #qrCode canvas {
            width: var(--qr-size, 220px) !important;
            height: var(--qr-size, 220px) !important;
            display: block;
        }
        .qr-meta, .qr-url {
            text-align: center;
            color: var(--gray-600);
            font-size: .85rem;
        }
        .qr-url {
            font-size: .72rem;
            color: var(--gray-500);
            word-break: break-all;
            margin-top: 4px;
        }
        .auth-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .auth-input {
            width: 100%;
            min-height: 56px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            padding: 0 14px;
            font-size: 1rem;
            outline: none;
            transition: var(--transition);
        }
        .auth-input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(136, 27, 18, 0.12);
        }
        .auth-actions {
            margin-top: 10px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }
        #loginBtn {
            min-height: 48px;
            font-size: 0.95rem;
            padding: 10px 18px;
        }
        .auth-error {
            display: none;
            margin-top: 8px;
            background: #fff1f2;
            border: 1px solid #fecdd3;
            color: #9f1239;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: .88rem;
        }
        .auth-error.show { display: block; }
        .children-card { display: none; }
        .children-card.show { display: block; }
        .small-link {
            text-align: center;
            font-size: .86rem;
        }
        .small-link a {
            color: #9a3412;
            text-decoration: underline;
            font-weight: 600;
        }
        @media (min-width: 768px) and (max-width: 1366px) {
            .access-wrap { max-width: 980px; }
            .access-card { padding: 22px; border-radius: 20px; }
            .access-main-title { font-size: 1.8rem; }
            .auth-grid { grid-template-columns: 1.4fr 1fr; gap: 12px; }
            .auth-input { min-height: 58px; font-size: 1.05rem; }
            .kiosk-btn { min-height: 58px; font-size: 1.02rem; }
            #loginBtn { min-height: 50px; font-size: 0.96rem; }
        }
        @media (min-width: 1024px) and (orientation: landscape) {
            .access-wrap { max-width: 1100px; }
        }
        @media (max-width: 760px) {
            .access-wrap { padding: 12px; }
            .auth-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="kiosk-app">
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

    <main class="access-wrap">
        <h1 class="access-main-title">Kiosk</h1>
        <p class="access-main-sub">Scan QR or enter phone and PIN below</p>

        <section class="access-card">
            <h2 class="access-title" style="text-align:center;"><i class="fa-solid fa-qrcode"></i> QR Code</h2>
            <div class="qr-frame"><div id="qrCode"></div></div>
            <div class="qr-meta"><i class="fa-solid fa-rotate"></i> New code in <strong id="qrTimer">--</strong>s</div>
            <div class="qr-url" id="qrUrl"></div>
        </section>

        <section class="access-card" id="authCard">
            <h2 class="access-title"><i class="fa-solid fa-mobile-screen-button"></i> Enter Phone and PIN</h2>
            <div class="auth-grid">
                <input type="tel" class="auth-input" id="phoneInput" placeholder="Phone Number" inputmode="numeric" maxlength="15" autocomplete="off">
                <input type="password" class="auth-input" id="pinInput" placeholder="PIN" inputmode="numeric" maxlength="6" autocomplete="off">
            </div>
            <div class="auth-error" id="authError"></div>
            <div class="auth-actions">
                <button class="kiosk-btn kiosk-btn--primary kiosk-btn--block" id="loginBtn" type="button">
                    <i class="fa-solid fa-right-to-bracket"></i> Log In
                </button>
                <div class="small-link">
                    <a href="forgotKioskPin" target="_blank" rel="noopener">Forgot PIN? Reset with phone number</a>
                </div>
            </div>
        </section>

        <section class="access-card children-card" id="childrenCard">
            <div class="kiosk-children__welcome" style="margin-bottom:10px;">
                <h2 id="welcomeName">Welcome!</h2>
                <p id="welcomeSub">Tap to sign in or out</p>
            </div>
            <div id="childrenList"></div>
            <button class="kiosk-btn kiosk-btn--success kiosk-btn--block" id="childrenDone" type="button" style="margin-top:16px;font-weight:700;">
                <i class="fa-solid fa-check"></i> Done
            </button>
        </section>
    </main>

    <footer class="kiosk-footer" style="display:flex;">
        <div class="kiosk-footer__timeout"><i class="fa-solid fa-circle-info"></i><span>Same page supports QR and direct login</span></div>
        <div>BBCC &copy; <?= date('Y') ?></div>
    </footer>
</div>

<script>
(function () {
    'use strict';

    var API = 'kiosk-api.php';
    var ROTATE_SEC = 120;
    var PHONE_MIN = 8;
    var PIN_MIN = 4;

    var csrf = <?= json_encode($csrfToken) ?>;
    var baseUrl = <?= json_encode($mobileBaseUrl) ?>;
    var countdownId = null;
    var remaining = 0;

    var parentData = null;
    var pendingActions = {};
    var submitting = false;

    function $(s) { return document.querySelector(s); }

    function tickClock() {
        var d = new Date();
        var h = d.getHours(), m = String(d.getMinutes()).padStart(2, '0');
        var ap = h >= 12 ? 'PM' : 'AM';
        $('#clockTime').textContent = (h % 12 || 12) + ':' + m + ' ' + ap;
        var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var mos = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $('#clockDate').textContent = days[d.getDay()] + ', ' + d.getDate() + ' ' + mos[d.getMonth()] + ' ' + d.getFullYear();
    }

    function renderQR(url) {
        var node = $('#qrCode');
        node.innerHTML = '';
        var size = getQrSize();
        document.documentElement.style.setProperty('--qr-size', size + 'px');
        new QRCode(node, {
            text: url,
            width: size,
            height: size,
            colorDark: '#1f2937',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
        $('#qrUrl').textContent = url;
    }

    function getQrSize() {
        var w = Math.max(320, window.innerWidth || 820);
        if (w <= 480) return 190;
        if (w <= 760) return 200;
        if (w <= 1024) return 230; // iPad portrait
        return 250; // iPad landscape / larger
    }

    function setCountdown(seconds) {
        remaining = seconds;
        $('#qrTimer').textContent = String(remaining);
        if (countdownId) clearInterval(countdownId);
        countdownId = setInterval(function () {
            remaining -= 1;
            if (remaining <= 0) {
                clearInterval(countdownId);
                refreshToken();
            } else {
                $('#qrTimer').textContent = String(remaining);
            }
        }, 1000);
    }

    function refreshToken() {
        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'generate_token', _csrf: csrf })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data || !data.ok) throw new Error('Failed');
            var url = baseUrl + '?t=' + data.token;
            renderQR(url);
            setCountdown(ROTATE_SEC);
        })
        .catch(function () {
            setTimeout(refreshToken, 5000);
        });
    }

    function showError(msg) {
        var box = $('#authError');
        box.textContent = msg;
        box.classList.add('show');
    }

    function hideError() {
        $('#authError').classList.remove('show');
    }

    function fmtTime(t) {
        if (!t) return '';
        var p = t.split(':'), h = parseInt(p[0],10), ap = h >= 12 ? 'PM' : 'AM';
        return (h % 12 || 12) + ':' + p[1] + ' ' + ap;
    }

    function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function renderChildren() {
        if (!parentData) return;
        $('#childrenCard').classList.add('show');
        $('#welcomeName').textContent = 'Hi, ' + (parentData.parent_name || 'Parent') + '!';

        var list = $('#childrenList');
        list.innerHTML = '';

        var kids = parentData.children || [];
        if (!kids.length) {
            list.innerHTML = '<div style="text-align:center;padding:20px;color:var(--gray-600);">No enrolled children found.</div>';
            return;
        }

        var actionable = kids.filter(function(c){ return c.status !== 'done'; });

        kids.forEach(function (child) {
            var card = document.createElement('div');
            var isSingle = (actionable.length === 1 && child.status !== 'done');
            card.className = 'kiosk-child-card' + (isSingle ? ' kiosk-child-card--big' : '');

            var initials = (child.student_name || '').split(' ').map(function(w){ return w[0] || ''; }).join('').toUpperCase().slice(0,2);
            var statusHtml = '';
            var actionHtml = '';
            var queuedMode = pendingActions[String(child.id)] || '';

            if (child.status === 'done') {
                var tOut = fmtTime(child.time_out);
                statusHtml = '<span class="kiosk-child-card__status kiosk-child-card__status--done"><i class="fa-solid fa-circle-check"></i> Done (out ' + tOut + ')</span>';
            } else if (queuedMode === 'in') {
                statusHtml = '<span class="kiosk-child-card__status kiosk-child-card__status--in"><i class="fa-solid fa-clock"></i> Sign In selected</span>';
                actionHtml = '<button class="kiosk-btn kiosk-btn--outline' + (isSingle ? ' kiosk-btn--lg' : '') + '" data-child="'+child.id+'" data-mode=""><i class="fa-solid fa-rotate-left"></i> Undo</button>';
            } else if (queuedMode === 'out') {
                statusHtml = '<span class="kiosk-child-card__status kiosk-child-card__status--in"><i class="fa-solid fa-clock"></i> Sign Out selected</span>';
                actionHtml = '<button class="kiosk-btn kiosk-btn--outline' + (isSingle ? ' kiosk-btn--lg' : '') + '" data-child="'+child.id+'" data-mode=""><i class="fa-solid fa-rotate-left"></i> Undo</button>';
            } else if (child.status === 'none') {
                actionHtml = '<button class="kiosk-btn kiosk-btn--success' + (isSingle ? ' kiosk-btn--lg' : '') + '" data-child="'+child.id+'" data-mode="in"><i class="fa-solid fa-right-to-bracket"></i> Sign In</button>';
            } else if (child.status === 'signed_in') {
                var tIn = fmtTime(child.time_in);
                statusHtml = '<span class="kiosk-child-card__status kiosk-child-card__status--in">In at ' + tIn + '</span>';
                actionHtml = '<button class="kiosk-btn kiosk-btn--danger' + (isSingle ? ' kiosk-btn--lg' : '') + '" data-child="'+child.id+'" data-mode="out"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</button>';
            }

            card.innerHTML = '<div class="kiosk-child-card__info">'
                + '<div class="kiosk-child-card__avatar">' + initials + '</div>'
                + '<div><div class="kiosk-child-card__name">' + esc(child.student_name) + '</div>' + statusHtml + '</div></div>'
                + '<div class="kiosk-child-card__action">' + actionHtml + '</div>';
            list.appendChild(card);
        });

        list.querySelectorAll('[data-child]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var childId = parseInt(btn.getAttribute('data-child'), 10);
                var mode = btn.getAttribute('data-mode') || '';
                if (mode === 'in' || mode === 'out') pendingActions[String(childId)] = mode;
                else delete pendingActions[String(childId)];
                renderChildren();
            });
        });

        updateDoneButton();
    }

    function updateDoneButton() {
        var count = Object.keys(pendingActions).length;
        var btn = $('#childrenDone');
        btn.innerHTML = '<i class="fa-solid fa-check"></i> ' + (count > 0 ? ('Done & Submit (' + count + ')') : 'Done');
    }

    function submitQueuedSigns() {
        if (submitting || !parentData) return;
        var queued = Object.keys(pendingActions).map(function(k) {
            return { child_id: parseInt(k, 10), mode: pendingActions[k] };
        }).filter(function(x) { return x.child_id > 0 && (x.mode === 'in' || x.mode === 'out'); });

        if (!queued.length) {
            alert('No actions selected.');
            return;
        }

        submitting = true;
        $('#childrenDone').disabled = true;

        api({
            action: 'sign_batch',
            parent_id: parentData.parent_id,
            actions: JSON.stringify(queued)
        }).then(function (r) {
            submitting = false;
            $('#childrenDone').disabled = false;
            if (r.ok) {
                alert((r.data && r.data.message) ? r.data.message : 'Attendance submitted successfully.');
                pendingActions = {};
                parentData = null;
                $('#childrenCard').classList.remove('show');
                $('#phoneInput').value = '';
                $('#pinInput').value = '';
            } else {
                alert(r.message || 'Unable to submit attendance.');
            }
        });
    }

    function doAuth() {
        if (submitting) return;
        hideError();

        var phone = ($('#phoneInput').value || '').replace(/[^0-9]/g, '');
        var pin = ($('#pinInput').value || '').replace(/[^0-9]/g, '');

        if (phone.length < PHONE_MIN) { showError('Please enter a valid phone number.'); return; }
        if (pin.length < PIN_MIN) { showError('PIN must be at least 4 digits.'); return; }

        submitting = true;
        $('#loginBtn').disabled = true;

        api({ action: 'auth', phone: phone, pin: pin }).then(function (r) {
            submitting = false;
            $('#loginBtn').disabled = false;
            if (r.ok) {
                parentData = r.data;
                pendingActions = {};
                renderChildren();
                $('#pinInput').value = '';
            } else {
                $('#pinInput').value = '';
                showError(r.message || 'Invalid phone number or PIN.');
            }
        });
    }

    function api(data) {
        data._csrf = csrf;
        return fetch(API, { method: 'POST', body: new URLSearchParams(data) })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.csrf_error) {
                    return fetch(API, { method: 'POST', body: new URLSearchParams({ action: 'csrf' }) })
                        .then(function (r) { return r.json(); })
                        .then(function (t) {
                            if (t.ok) {
                                csrf = t.token;
                                data._csrf = csrf;
                                return fetch(API, { method: 'POST', body: new URLSearchParams(data) }).then(function (r) { return r.json(); });
                            }
                            return j;
                        });
                }
                return j;
            })
            .catch(function () { return { ok: false, message: 'Connection error.' }; });
    }

    tickClock();
    setInterval(tickClock, 10000);
    refreshToken();
    window.addEventListener('resize', function () {
        // Re-render QR for orientation/layout changes on iPad.
        refreshToken();
    });

    $('#loginBtn').addEventListener('click', doAuth);
    $('#childrenDone').addEventListener('click', submitQueuedSigns);

    $('#phoneInput').addEventListener('input', hideError);
    $('#pinInput').addEventListener('input', hideError);
    $('#pinInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') doAuth();
    });
})();
</script>
</body>
</html>
