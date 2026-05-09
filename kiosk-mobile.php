<?php
/**
 * kiosk-mobile.php — Mobile Sign-In/Out for Parents (QR scan entry)
 *
 * Parents scan a QR code and land here on their phone.
 * Flow: Phone + PIN → Children → Sign In/Out → Confirmation
 * Uses the same kiosk-api.php backend as the iPad kiosk.
 */
require_once "include/config.php";
require_once "include/csrf.php";
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="theme-color" content="#881b12">
    <title>Bhutanese Language and Culture School</title>
    <link rel="icon" type="image/jpeg" href="bbccassests/img/logo/logo5.jpg">
    <link rel="apple-touch-icon" href="bbccassests/img/logo/logo5.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/kiosk.css">
    <link rel="stylesheet" href="css/kiosk-mobile.css">
</head>
<body>

<div class="km-app" id="mobileApp">

    <!-- Header -->
    <header class="km-header">
        <img src="bbccassests/img/logo/logo5.jpg" alt="BBCC" class="km-header__logo" onerror="this.style.display='none'">
        <div>
            <div class="km-header__title">Bhutanese Language and Culture School</div>
            <div class="km-header__sub">Bhutanese Buddhist &amp; Cultural Centre</div>
        </div>
    </header>

    <!-- Screens wrapper -->
    <div class="km-body">

        <!-- SCREEN 0: Token validation (shown first) -->
        <div class="km-screen active" id="mScreenValidate">
            <div style="text-align:center; padding:40px 16px;">
                <div class="km-spinner show" style="margin:0 auto 20px;"></div>
                <h2 class="km-section-title">Verifying QR Code…</h2>
                <p style="font-size:.88rem; color:var(--gray-600);">Please wait while we verify your scan.</p>
            </div>
        </div>

        <!-- SCREEN 0b: Token invalid -->
        <div class="km-screen" id="mScreenInvalid">
            <div style="text-align:center; padding:40px 16px;">
                <div style="width:72px;height:72px;border-radius:50%;background:#fef2f2;color:var(--danger);display:flex;align-items:center;justify-content:center;font-size:2rem;margin:0 auto 16px;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <h2 class="km-section-title" style="color:var(--danger);" id="mInvalidTitle">QR Code Invalid</h2>
                <p style="font-size:.9rem; color:var(--gray-700); margin-bottom:24px;" id="mInvalidMsg">This QR code has expired or already been used. Please go back to the door and scan the new QR code.</p>
                <p style="font-size:.82rem; color:var(--gray-500);"><i class="fa-solid fa-qrcode"></i> The QR code updates every 2 minutes for security.</p>
            </div>
        </div>

        <!-- SCREEN 1: Auth (Phone + PIN) -->
        <div class="km-screen" id="mScreenAuth">
            <h2 class="km-section-title">Enter your details</h2>

            <div class="km-field-group">
                <label class="km-label"><i class="fa-solid fa-mobile-screen-button"></i> Phone Number</label>
                <input type="tel" class="km-input" id="mPhone" placeholder="04xx xxx xxx" maxlength="15" inputmode="numeric" autocomplete="off">
            </div>

            <div class="km-field-group">
                <label class="km-label"><i class="fa-solid fa-lock"></i> PIN</label>
                <input type="password" class="km-input km-input--pin" id="mPin" placeholder="••••" inputmode="numeric" autocomplete="off">
            </div>

            <div class="km-error" id="mAuthError">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span id="mAuthErrorText"></span>
            </div>

            <button class="km-btn km-btn--primary km-btn--block" id="mVerifyBtn" type="button">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Log In
            </button>
            <div style="text-align:center; margin-top:10px;">
                <a href="forgotKioskPin" target="_blank" rel="noopener" style="font-size:0.86rem; color:#9a3412; text-decoration:underline;">
                    Forgot PIN? Reset with phone number
                </a>
            </div>

            <div class="km-spinner" id="mAuthSpinner"></div>
        </div>

        <!-- SCREEN 2: Children -->
        <div class="km-screen" id="mScreenChildren">
            <div class="km-welcome" id="mWelcome">
                <h2 id="mWelcomeName">Welcome!</h2>
                <p>Tap to sign your child in or out</p>
            </div>

            <div id="mChildrenList"></div>

            <button class="km-btn km-btn--outline km-btn--block" id="mDoneBtn" type="button" style="margin-top:16px;">
                <i class="fa-solid fa-right-from-bracket"></i> Done
            </button>
        </div>

        <!-- SCREEN 3: Confirmation -->
        <div class="km-screen" id="mScreenConfirm">
            <div class="km-confirm">
                <div class="km-confirm__icon" id="mConfirmIcon">
                    <i class="fa-solid fa-check"></i>
                </div>
                <h2 class="km-confirm__title" id="mConfirmTitle">Done!</h2>
                <p class="km-confirm__detail" id="mConfirmDetail"></p>
                <div class="km-confirm__time" id="mConfirmTime"></div>
                <p class="km-confirm__auto" id="mConfirmAuto">Returning in <span id="mConfirmCount">3</span>s…</p>
            </div>
        </div>

    </div>

    <!-- Footer -->
    <footer class="km-footer">
        BBCC &copy; <?= date('Y') ?>
    </footer>

</div>

<script>
(function () {
    'use strict';

    var API       = 'kiosk-api.php';
    var PIN_MIN   = 4;
    var CONFIRM_S = 3;

    var csrf       = <?= json_encode($csrfToken) ?>;
    var phone      = '';
    var pin        = '';
    var parentData = null;
    var submitting = false;
    var qrSession  = '';  // session key from token validation
    var actionBusy = {}; // { childId: true }

    function $(s)  { return document.querySelector(s); }
    function $$(s) { return document.querySelectorAll(s); }

    // ═══ SCREENS ═══
    var curScreen = 'validate';
    var screenMap = {
        validate: $('#mScreenValidate'),
        invalid:  $('#mScreenInvalid'),
        auth:     $('#mScreenAuth'),
        children: $('#mScreenChildren'),
        confirm:  $('#mScreenConfirm')
    };

    function go(name) {
        if (screenMap[curScreen]) screenMap[curScreen].classList.remove('active');
        curScreen = name;
        screenMap[name].classList.add('active');
        window.scrollTo(0, 0);
    }

    // ═══ TOKEN VALIDATION ON LOAD ═══
    (function validateToken() {
        var params = new URLSearchParams(window.location.search);
        var token  = params.get('t');

        if (!token) {
            $('#mInvalidTitle').textContent = 'No QR Code Detected';
            $('#mInvalidMsg').textContent = 'Please scan the QR code displayed at the door to access sign-in/out.';
            go('invalid');
            return;
        }

        api({ action: 'validate_token', qr_token: token }).then(function(r) {
            if (r.ok) {
                qrSession = r.session_key;
                go('auth');
                var mPhone = $('#mPhone');
                if (mPhone) mPhone.focus();
            } else {
                $('#mInvalidMsg').textContent = r.message || 'This QR code is invalid. Please scan again at the door.';
                go('invalid');
            }
        });
    })();

    // ═══ AUTH ═══
    var mPhone = $('#mPhone');
    var mPin   = $('#mPin');

    function hideError() { $('#mAuthError').classList.remove('show'); }
    function showError(msg) {
        $('#mAuthErrorText').textContent = msg;
        $('#mAuthError').classList.add('show');
    }

    mPin.addEventListener('input', function() { hideError(); });

    mPhone.addEventListener('input', function() { hideError(); });

    // Verify button fallback
    $('#mVerifyBtn').addEventListener('click', doAuth);

    function doAuth() {
        if (submitting) return;
        phone = mPhone.value.replace(/[^0-9]/g, '');
        pin   = mPin.value.replace(/[^0-9]/g, '');

        if (phone.length < 8) { showError('Please enter a valid phone number.'); mPhone.focus(); return; }
        if (pin.length < PIN_MIN) { showError('PIN must be at least 4 digits.'); mPin.focus(); return; }

        submitting = true;
        $('#mVerifyBtn').disabled = true;
        $('#mAuthSpinner').classList.add('show');

        api({ action: 'auth', phone: phone, pin: pin, qr_session: qrSession }).then(function(r) {
            $('#mAuthSpinner').classList.remove('show');
            $('#mVerifyBtn').disabled = false;
            submitting = false;

            if (r.ok) {
                parentData = r.data;
                actionBusy = {};
                renderChildren();
                go('children');
            } else if (r.token_expired) {
                $('#mInvalidTitle').textContent = 'Session Expired';
                $('#mInvalidMsg').textContent = r.message;
                go('invalid');
            } else {
                mPin.value = '';
                showError(r.message);
                mPin.focus();
            }
        });
    }

    // ═══ CHILDREN ═══
    function renderChildren() {
        if (!parentData) return;
        $('#mWelcomeName').textContent = 'Hi, ' + parentData.parent_name + '!';

        var list = $('#mChildrenList');
        list.innerHTML = '';

        var kids = parentData.children;
        if (kids.length === 0) {
            list.innerHTML = '<div class="km-empty"><i class="fa-solid fa-circle-info"></i> No enrolled children found.</div>';
            return;
        }

        kids.forEach(function(child) {
            var card = document.createElement('div');
            card.className = 'km-child';

            var initials = child.student_name.split(' ').map(function(w){ return w[0]; }).join('').toUpperCase().slice(0,2);
            var statusHtml = '';
            var actionHtml = '';
            var busy = !!actionBusy[String(child.id)];

            if (child.status === 'done') {
                statusHtml = '<span class="km-child__status km-child__status--done">'
                    + '<i class="fa-solid fa-circle-check"></i> Done (out ' + fmtTime(child.time_out) + ')</span>';
            } else if (child.status === 'none') {
                actionHtml = '<button class="km-btn km-btn--success km-btn--sm" data-child="'+child.id+'" data-mode="in" ' + (busy ? 'disabled' : '') + '>'
                    + '<i class="fa-solid fa-right-to-bracket"></i> ' + (busy ? 'Signing In...' : 'Sign In') + '</button>';
            } else if (child.status === 'signed_in') {
                statusHtml = '<span class="km-child__status km-child__status--in">In at ' + fmtTime(child.time_in) + '</span>';
                actionHtml = '<button class="km-btn km-btn--danger km-btn--sm" data-child="'+child.id+'" data-mode="out" ' + (busy ? 'disabled' : '') + '>'
                    + '<i class="fa-solid fa-right-from-bracket"></i> ' + (busy ? 'Signing Out...' : 'Sign Out') + '</button>';
            }

            card.innerHTML = '<div class="km-child__info">'
                + '<div class="km-child__avatar">' + initials + '</div>'
                + '<div>'
                + '<div class="km-child__name">' + esc(child.student_name) + '</div>'
                + statusHtml
                + '</div></div>'
                + '<div class="km-child__action">' + actionHtml + '</div>';

            list.appendChild(card);
        });

        list.querySelectorAll('[data-child]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                submitSign(parseInt(btn.dataset.child, 10), btn.dataset.mode || '');
            });
        });
    }

    function submitSign(childId, mode) {
        if (!parentData || !childId || (mode !== 'in' && mode !== 'out')) return;
        var key = String(childId);
        if (actionBusy[key]) return;
        actionBusy[key] = true;
        renderChildren();

        api({
            action: 'sign',
            parent_id: parentData.parent_id,
            child_id: childId,
            mode: mode,
            qr_session: qrSession
        }).then(function(r) {
            actionBusy[key] = false;
            if (r.ok) {
                var c = (parentData.children || []).find(function(x){ return String(x.id) === key; });
                if (c) {
                    if (mode === 'in') {
                        c.status = 'signed_in';
                        c.time_in = (new Date()).toTimeString().slice(0,8);
                    } else {
                        c.status = 'done';
                        c.time_out = (new Date()).toTimeString().slice(0,8);
                    }
                }
                renderChildren();
                showConfirm(r.data || {});
            } else if (r.token_expired) {
                $('#mInvalidTitle').textContent = 'Session Expired';
                $('#mInvalidMsg').textContent = r.message;
                go('invalid');
            } else {
                renderChildren();
                alert(r.message || 'Unable to submit attendance. Please try again.');
            }
        });
    }

    $('#mDoneBtn').addEventListener('click', resetToAuth);

    // ═══ CONFIRMATION ═══
    function showConfirm(data) {
        var isBatch = false;
        var isIn = (data.action === 'in');
        var icon = $('#mConfirmIcon');
        if (isBatch) {
            icon.className = 'km-confirm__icon km-confirm__icon--success';
            icon.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
            $('#mConfirmTitle').textContent = 'Submitted';
            $('#mConfirmDetail').textContent = data.message || 'Attendance submitted.';
            $('#mConfirmTime').textContent = (data.success_count || 0) + ' successful'
                + ((data.failed_count || 0) > 0 ? (', ' + data.failed_count + ' failed') : '');
        } else {
            icon.className = 'km-confirm__icon ' + (isIn ? 'km-confirm__icon--success' : 'km-confirm__icon--out');
            icon.innerHTML = isIn
                ? '<i class="fa-solid fa-right-to-bracket"></i>'
                : '<i class="fa-solid fa-right-from-bracket"></i>';
            $('#mConfirmTitle').textContent = isIn ? 'Signed In!' : 'Signed Out!';
            $('#mConfirmDetail').textContent = data.child_name;
            $('#mConfirmTime').textContent = data.time;
        }

        go('confirm');

        // Countdown back to auth
        var sec = CONFIRM_S;
        $('#mConfirmCount').textContent = sec;
        var iv = setInterval(function() {
            sec--;
            $('#mConfirmCount').textContent = Math.max(0, sec);
            if (sec <= 0) {
                clearInterval(iv);
                resetToAuth();
            }
        }, 1000);
    }

    // ═══ RESET ═══
    function resetToAuth() {
        parentData = null;
        pendingActions = {};
        submitting = false;
        mPhone.value = '';
        mPin.value = '';
        hideError();
        go('auth');
        mPhone.focus();
    }

    // ═══ API ═══
    function api(data) {
        data._csrf = csrf;
        return fetch(API, { method: 'POST', body: new URLSearchParams(data) })
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (j.csrf_error) {
                    return fetch(API, { method: 'POST', body: new URLSearchParams({ action: 'csrf' }) })
                        .then(function(r) { return r.json(); })
                        .then(function(t) {
                            if (t.ok) { csrf = t.token; data._csrf = csrf; return fetch(API, { method: 'POST', body: new URLSearchParams(data) }).then(function(r) { return r.json(); }); }
                            return j;
                        });
                }
                return j;
            })
            .catch(function() { return { ok: false, message: 'Connection error. Please try again.' }; });
    }

    // ═══ HELPERS ═══
    function fmtTime(t) {
        if (!t) return '';
        var p = t.split(':'), h = parseInt(p[0], 10), ap = h >= 12 ? 'PM' : 'AM';
        return (h % 12 || 12) + ':' + p[1] + ' ' + ap;
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

})();
</script>

</body>
</html>
