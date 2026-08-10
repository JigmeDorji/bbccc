<?php
// donate.php — Public donation page. No account required: anyone can
// submit a donation with proof of bank transfer, reviewed by admin the
// same way fee payments are. Patron account creation stays available
// separately (see patronRegistration.php) for people who want an account.
require_once "include/config.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";

function bbcc_donate_bytes_label(int $bytes): string {
    if ($bytes >= 1024 * 1024) {
        $mb = $bytes / (1024 * 1024);
        return rtrim(rtrim(number_format($mb, 1), '0'), '.') . ' MB';
    }
    return round($bytes / 1024) . ' KB';
}

$pdo = pcm_pdo();
$maxProofBytes = 5 * 1024 * 1024;
$maxProofLabel = bbcc_donate_bytes_label($maxProofBytes);

$settings = $pdo->query("SELECT * FROM donation_settings WHERE id = 1 LIMIT 1")->fetch() ?: [];
$hasBankDetails =
    trim((string)($settings['account_name'] ?? '')) !== '' ||
    trim((string)($settings['bsb'] ?? '')) !== '' ||
    trim((string)($settings['account_number'] ?? '')) !== '';

$flash = '';
$ok = false;
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $donorName  = trim((string)($_POST['donor_name'] ?? ''));
    $donorEmail = trim((string)($_POST['donor_email'] ?? ''));
    $donorPhone = trim((string)($_POST['donor_phone'] ?? ''));
    $amount     = (float)($_POST['amount'] ?? 0);
    $ref        = trim((string)($_POST['payment_ref'] ?? ''));
    $message    = trim((string)($_POST['message'] ?? ''));

    if ($donorName === '') {
        $flash = 'Please enter your full name.';
    } elseif ($donorEmail === '' || !filter_var($donorEmail, FILTER_VALIDATE_EMAIL)) {
        $flash = 'Please enter a valid email address.';
    } elseif ($amount <= 0) {
        $flash = 'Please enter a donation amount.';
    } elseif (empty($_FILES['proof']['name']) || ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $flash = 'Please upload proof of payment.';
    } elseif (($_FILES['proof']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $flash = 'Payment proof upload failed. Please try again.';
    } else {
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $flash = 'Proof must be JPG, PNG, or PDF.';
        } elseif ($_FILES['proof']['size'] > $maxProofBytes) {
            $flash = 'File must be under ' . h($maxProofLabel) . '.';
        } else {
            $dir = 'uploads/donations';
            $dirAbs = __DIR__ . '/' . $dir;
            try {
                pcm_ensure_dir($dirAbs);
                $filename = 'donation_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
                $proofPath = $dir . '/' . $filename;
                $targetPath = $dirAbs . '/' . $filename;
                if (!is_uploaded_file($_FILES['proof']['tmp_name']) || !move_uploaded_file($_FILES['proof']['tmp_name'], $targetPath)) {
                    throw new RuntimeException('Failed to save uploaded file.');
                }

                $ins = $pdo->prepare("
                    INSERT INTO donations (donor_name, donor_email, donor_phone, amount, payment_ref, proof_path, message)
                    VALUES (:name, :email, :phone, :amt, :ref, :proof, :msg)
                ");
                $ins->execute([
                    ':name'  => $donorName,
                    ':email' => $donorEmail,
                    ':phone' => $donorPhone ?: null,
                    ':amt'   => $amount,
                    ':ref'   => $ref ?: null,
                    ':proof' => $proofPath,
                    ':msg'   => $message ?: null,
                ]);

                pcm_notify_admin_donation($donorName, $amount);

                $donorHtml = pcm_email_wrap('Thank You for Your Donation', "
                    <p style='margin:0 0 14px;'>Dear " . htmlspecialchars($donorName) . ",</p>
                    <p style='margin:0 0 14px;'>Thank you so much for your donation to the Bhutanese Buddhist and Cultural Centre. We are truly grateful for your kindness and support.</p>
                    <p style='margin:0;'>We will follow up shortly to confirm the amount received.</p>
                ");
                bbcc_queue_mail($donorEmail, $donorName, 'Thank You for Your Donation', $donorHtml);

                $submitted = true;
                $ok = true;
                $flash = 'Thank you so much for your generous donation!';
            } catch (Throwable $e) {
                error_log('[BBCC] donation submit error: ' . $e->getMessage());
                $flash = 'Something went wrong saving your donation. Please try again or contact us directly.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donate — Bhutanese Buddhist &amp; Cultural Centre</title>
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .bk-grid { display: grid; grid-template-columns: 5fr 7fr; gap: 40px; align-items: flex-start; }
        .bk-detail {
            background: var(--white); border: 1px solid var(--gray-200); border-radius: var(--radius-lg); overflow: hidden;
        }
        .bk-detail__header {
            background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; padding: 24px 28px;
        }
        .bk-detail__header h3 { font-size: 1.3rem; font-weight: 700; margin: 0 0 8px; color: #fff; }
        .bk-detail__body { padding: 24px 28px; }
        .bk-detail__body table { width: 100%; }
        .bk-detail__body td { padding: 10px 0; font-size: .92rem; vertical-align: top; color: var(--gray-700); }
        .bk-detail__body td:first-child { width: 40%; font-weight: 600; color: var(--gray-900); }
        .bk-detail__body td i { color: var(--brand); margin-right: 6px; font-size: .8rem; }
        .bk-detail__body .desc { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--gray-200); font-size: .92rem; color: var(--gray-600); line-height: 1.7; }
        .bk-detail__body .copy-btn { cursor: pointer; color: var(--brand); font-size: .78rem; }

        .bk-form { background: var(--gray-100); border-radius: var(--radius-lg); padding: 32px; }
        .bk-form h4 { font-size: 1.15rem; font-weight: 700; margin-bottom: 24px; }
        .bk-form .fg { margin-bottom: 18px; }
        .bk-form label { display: block; font-size: .8rem; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .3px; }
        .bk-form input, .bk-form textarea {
            width: 100%; padding: 12px 16px; border: 1.5px solid var(--gray-200); border-radius: var(--radius-md);
            font-size: .92rem; font-family: var(--font-body); color: var(--gray-900); background: #fff;
            transition: var(--transition-fast);
        }
        .bk-form input[type="file"] { padding: 10px 16px; }
        .bk-form input:focus, .bk-form textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(136,27,18,.08); }
        .bk-form textarea { min-height: 90px; resize: vertical; }
        .bk-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .req { color: var(--brand); }
        .hint { font-size: .78rem; color: var(--gray-500); margin-top: 4px; }

        .success-card { text-align: center; padding: 48px; background: #ecfdf5; border-radius: var(--radius-lg); border: 1px solid #a7f3d0; }
        .success-card i { font-size: 3rem; color: var(--success); }
        .success-card h3 { margin: 16px 0 8px; }
        .success-card p { color: var(--gray-600); max-width: 500px; margin: 0 auto 24px; }

        .alert-err { background: #fef2f2; border-left: 4px solid var(--brand); color: #7f1d1d; padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 20px; font-size: .9rem; }

        .patron-note { text-align: center; margin-top: 20px; font-size: .88rem; color: var(--gray-600); }

        @media (max-width: 991px) { .bk-grid { grid-template-columns: 1fr; } }
        @media (max-width: 576px) { .bk-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-heart"></i> Donate</h1>
        <p class="bbcc-page-hero__subtitle">Support the Bhutanese Buddhist &amp; Cultural Centre</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li>Donate</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="bk-grid">

            <!-- Bank Details -->
            <div>
                <div class="bk-detail fade-up">
                    <div class="bk-detail__header">
                        <h3><i class="fa-solid fa-university"></i> Bank Details</h3>
                    </div>
                    <div class="bk-detail__body">
                        <?php if ($hasBankDetails): ?>
                        <table>
                            <?php if (!empty($settings['bank_name'])): ?>
                            <tr><td><i class="fa-solid fa-building-columns"></i> Bank</td><td><?= htmlspecialchars($settings['bank_name']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($settings['account_name'])): ?>
                            <tr><td><i class="fa-solid fa-user"></i> Account Name</td><td><?= htmlspecialchars($settings['account_name']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($settings['bsb'])): ?>
                            <tr><td><i class="fa-solid fa-hashtag"></i> BSB</td><td><?= htmlspecialchars($settings['bsb']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($settings['account_number'])): ?>
                            <tr><td><i class="fa-solid fa-credit-card"></i> Account #</td><td><?= htmlspecialchars($settings['account_number']) ?></td></tr>
                            <?php endif; ?>
                        </table>
                        <?php if (!empty($settings['bank_notes'])): ?>
                        <div class="desc"><?= nl2br(htmlspecialchars($settings['bank_notes'])) ?></div>
                        <?php endif; ?>
                        <span class="copy-btn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars(trim(($settings['account_name'] ?? '') . ' | BSB: ' . ($settings['bsb'] ?? '') . ' | Acc: ' . ($settings['account_number'] ?? ''))) ?>').then(()=>this.textContent='Copied!')">
                            <i class="fa-solid fa-copy"></i> Copy Details
                        </span>
                        <?php else: ?>
                        <p style="color:var(--gray-600);margin:0;">Bank details will be published here shortly. Please contact us at <a href="mailto:bhutanesecentrecanberra@gmail.com">bhutanesecentrecanberra@gmail.com</a> in the meantime.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="index" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm" style="margin-top:16px;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Home
                </a>
            </div>

            <!-- Donation Form -->
            <div>
                <?php if ($submitted): ?>
                <div class="success-card fade-up">
                    <i class="fa-solid fa-circle-check"></i>
                    <h3>Thank You!</h3>
                    <p><?= htmlspecialchars($flash) ?></p>
                    <a href="index" class="bbcc-btn bbcc-btn--primary">Back to Home</a>
                </div>

                <?php else: ?>

                <?php if ($flash): ?>
                <div class="alert-err"><i class="fa-solid fa-exclamation-circle" style="margin-right:6px;"></i><?= htmlspecialchars($flash) ?></div>
                <?php endif; ?>

                <div class="bk-form fade-up">
                    <h4><i class="fa-solid fa-hand-holding-heart" style="color:var(--brand);margin-right:8px;"></i> Donation Details</h4>
                    <form method="POST" enctype="multipart/form-data" id="donateForm">
                        <?= csrf_field() ?>

                        <div class="fg">
                            <label>Full Name <span class="req">*</span></label>
                            <input type="text" name="donor_name" required placeholder="Enter your full name" value="<?= htmlspecialchars($_POST['donor_name'] ?? '') ?>">
                        </div>
                        <div class="bk-row">
                            <div class="fg">
                                <label>Email Address <span class="req">*</span></label>
                                <input type="email" name="donor_email" required placeholder="you@example.com" value="<?= htmlspecialchars($_POST['donor_email'] ?? '') ?>">
                            </div>
                            <div class="fg">
                                <label>Phone Number (Optional)</label>
                                <input type="tel" name="donor_phone" placeholder="e.g. 0402 096 551" value="<?= htmlspecialchars($_POST['donor_phone'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="bk-row">
                            <div class="fg">
                                <label>Donation Amount (AUD) <span class="req">*</span></label>
                                <input type="number" name="amount" min="1" step="0.01" required placeholder="e.g. 50" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                            </div>
                            <div class="fg">
                                <label>Payment Reference (Optional)</label>
                                <input type="text" name="payment_ref" placeholder="e.g. your name" value="<?= htmlspecialchars($_POST['payment_ref'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="fg">
                            <label>Proof of Payment <span class="req">*</span></label>
                            <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required>
                            <div class="hint">JPG, PNG, or PDF — max <?= htmlspecialchars($maxProofLabel) ?></div>
                        </div>
                        <div class="fg">
                            <label>Message (Optional)</label>
                            <textarea name="message" placeholder="Any message you'd like to share..."><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="bbcc-btn bbcc-btn--primary" style="width:100%;justify-content:center;" id="donateSubmitBtn">
                            <i class="fa-solid fa-paper-plane"></i> Submit Donation
                        </button>
                    </form>
                </div>
                <p class="patron-note">
                    Want to support us on an ongoing basis? <a href="patronRegistration">Become a Patron</a> instead.
                </p>
                <?php endif; ?>
            </div>

        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

<script>
var donateForm = document.getElementById('donateForm');
if (donateForm) {
    donateForm.addEventListener('submit', function() {
        var btn = document.getElementById('donateSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    });
}
</script>

</body>
</html>
