<?php
/**
 * kiosk-qr.php — QR Code Display for Second iPad / Screen
 *
 * Shows a large QR code that parents can scan with their phone
 * to access the mobile sign-in/out page (kiosk-mobile.php).
 * Auto-detects the server URL so it works in any environment.
 */
require_once "include/config.php";
require_once "include/csrf.php";
$csrfToken = csrf_token();

// Build the mobile kiosk URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$mobileBaseUrl = "{$protocol}://{$host}{$path}/kiosk-mobile.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="BBCC QR">
    <meta name="theme-color" content="#881b12">
    <title>BBCC — Scan to Sign In/Out</title>
    <link rel="icon" type="image/jpeg" href="bbccassests/img/logo/logo5.jpg">
    <link rel="apple-touch-icon" href="bbccassests/img/logo/logo5.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/kiosk.css">
    <!-- QR Code generator (client-side, no external API needed) -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        /* QR Display Page Overrides */
        .qr-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 32px;
            gap: 24px;
        }

        .qr-logo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--brand);
            box-shadow: 0 0 0 6px rgba(136,27,18,.1);
        }

        .qr-heading {
            font-family: var(--font-display);
            font-size: 2rem;
            font-weight: 800;
            color: var(--brand);
            line-height: 1.2;
        }

        .qr-sub {
            font-size: 1.05rem;
            color: var(--gray-700);
            max-width: 400px;
        }

        .qr-frame {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: 24px;
            box-shadow: var(--shadow-lg);
            border: 3px solid var(--brand);
            display: inline-block;
        }

        .qr-frame #qrCode {
            width: 280px;
            height: 280px;
        }

        .qr-frame #qrCode img,
        .qr-frame #qrCode canvas {
            display: block;
            width: 280px !important;
            height: 280px !important;
        }

        .qr-hint {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--white);
            padding: 16px 24px;
            border-radius: var(--radius-full);
            box-shadow: var(--shadow-sm);
            font-size: .95rem;
            color: var(--gray-700);
            font-weight: 500;
        }

        .qr-hint i {
            font-size: 1.3rem;
            color: var(--brand);
        }

        .qr-steps {
            display: flex;
            gap: 32px;
            margin-top: 8px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .qr-step {
            text-align: center;
            max-width: 140px;
        }

        .qr-step__num {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--brand);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0 auto 8px;
        }

        .qr-step__text {
            font-size: .82rem;
            color: var(--gray-600);
            line-height: 1.4;
        }

        .qr-url {
            font-size: .72rem;
            color: var(--gray-400);
            word-break: break-all;
            margin-top: 8px;
        }

        .qr-footer {
            background: var(--white);
            border-top: 1px solid var(--gray-200);
            padding: 10px 24px;
            text-align: center;
            font-size: .72rem;
            color: var(--gray-600);
            flex-shrink: 0;
        }

        /* Gentle pulse on QR frame */
        @keyframes qrPulse {
            0%, 100% { box-shadow: 0 12px 40px rgba(136,27,18,.12); }
            50% { box-shadow: 0 12px 40px rgba(136,27,18,.22); }
        }

        .qr-frame { animation: qrPulse 3s ease-in-out infinite; }

        .qr-countdown {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .85rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        .qr-countdown i {
            color: var(--brand);
        }

        .qr-countdown strong {
            font-variant-numeric: tabular-nums;
            color: var(--brand);
        }

        .qr-fading {
            opacity: .3;
            transition: opacity .3s ease;
        }
    </style>
</head>
<body>

<div class="kiosk-app">
    <!-- Header -->
    <header class="kiosk-header">
        <div class="kiosk-header__brand">
            <img src="bbccassests/img/logo/logo5.jpg" alt="BBCC" class="kiosk-header__logo" onerror="this.style.display='none'">
            <div>
                <div class="kiosk-header__title">BBCC Kiosk</div>
                <div class="kiosk-header__subtitle">Bhutanese Buddhist &amp; Cultural Centre</div>
            </div>
        </div>
        <div class="kiosk-header__clock">
            <div class="kiosk-header__time" id="clockTime">--:--</div>
            <div class="kiosk-header__date" id="clockDate">--</div>
        </div>
    </header>

    <!-- QR Display -->
    <div class="qr-container">
        <img src="bbccassests/img/logo/logo5.jpg" alt="BBCC" class="qr-logo" onerror="this.style.display='none'">

        <h1 class="qr-heading">Scan to Sign In / Out</h1>
        <p class="qr-sub">Use your phone camera to scan the QR code below — no app needed!</p>

        <div class="qr-frame">
            <div id="qrCode"></div>
        </div>

        <div class="qr-countdown" id="qrCountdown">
            <i class="fa-solid fa-rotate"></i> New code in <strong id="qrTimer">--</strong>s
        </div>

        <div class="qr-steps">
            <div class="qr-step">
                <div class="qr-step__num">1</div>
                <div class="qr-step__text">Open your phone camera</div>
            </div>
            <div class="qr-step">
                <div class="qr-step__num">2</div>
                <div class="qr-step__text">Point at the QR code</div>
            </div>
            <div class="qr-step">
                <div class="qr-step__num">3</div>
                <div class="qr-step__text">Tap the link &amp; sign in/out</div>
            </div>
        </div>

        <div class="qr-url" id="qrUrl"></div>
    </div>

    <!-- Footer -->
    <div class="qr-footer">
        BBCC &copy; <?= date('Y') ?> &middot; Need help? Ask a staff member.
    </div>
</div>

<script>
(function () {
    'use strict';

    var API         = 'kiosk-api.php';
    var ROTATE_SEC  = 120;  // new token every 2 minutes
    var csrf        = <?= json_encode($csrfToken) ?>;
    var baseUrl     = <?= json_encode($mobileBaseUrl) ?>;
    var qrInstance  = null;
    var countdownId = null;
    var remaining   = 0;

    // Clock
    function tick() {
        var d = new Date();
        var h = d.getHours(), m = String(d.getMinutes()).padStart(2,'0');
        var ap = h >= 12 ? 'PM' : 'AM';
        document.getElementById('clockTime').textContent = (h%12||12) + ':' + m + ' ' + ap;
        var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var mos  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        document.getElementById('clockDate').textContent = days[d.getDay()] + ', ' + d.getDate() + ' ' + mos[d.getMonth()] + ' ' + d.getFullYear();
    }
    tick();
    setInterval(tick, 10000);

    // Fetch a new token from the server and update the QR code
    function refreshToken() {
        // Fade out current QR
        var el = document.getElementById('qrCode');
        el.classList.add('qr-fading');

        fetch(API, {
            method: 'POST',
            body: new URLSearchParams({ action: 'generate_token', _csrf: csrf })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_error) {
                // Refresh CSRF first
                return fetch(API, { method: 'POST', body: new URLSearchParams({ action: 'csrf' }) })
                    .then(function(r) { return r.json(); })
                    .then(function(t) {
                        if (t.ok) csrf = t.token;
                        return fetch(API, {
                            method: 'POST',
                            body: new URLSearchParams({ action: 'generate_token', _csrf: csrf })
                        }).then(function(r) { return r.json(); });
                    });
            }
            return data;
        })
        .then(function(data) {
            if (!data || !data.ok) {
                console.error('Token generation failed', data);
                setTimeout(refreshToken, 5000); // retry in 5s
                return;
            }

            var url = baseUrl + '?t=' + data.token;

            // Clear and regenerate QR code
            el.innerHTML = '';
            el.classList.remove('qr-fading');

            if (typeof QRCode !== 'undefined') {
                qrInstance = new QRCode(el, {
                    text: url,
                    width: 560,
                    height: 560,
                    colorDark: '#1a1a2e',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            } else {
                var img = document.createElement('img');
                img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=560x560&data=' + encodeURIComponent(url);
                img.alt = 'QR Code';
                img.style.width = '280px';
                img.style.height = '280px';
                el.appendChild(img);
            }

            document.getElementById('qrUrl').textContent = url;

            // Start countdown
            startCountdown();
        })
        .catch(function(err) {
            console.error('Token fetch error:', err);
            setTimeout(refreshToken, 5000);
        });
    }

    function startCountdown() {
        if (countdownId) clearInterval(countdownId);
        remaining = ROTATE_SEC;
        document.getElementById('qrTimer').textContent = remaining;

        countdownId = setInterval(function() {
            remaining--;
            document.getElementById('qrTimer').textContent = Math.max(0, remaining);
            if (remaining <= 0) {
                clearInterval(countdownId);
                refreshToken();
            }
        }, 1000);
    }

    // Initial load
    refreshToken();

    // Prevent interactions on display iPad
    document.addEventListener('contextmenu', function(e){ e.preventDefault(); });

})();
</script>

</body>
</html>
