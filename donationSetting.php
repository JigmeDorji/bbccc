<?php
// donationSetting.php — Admin-editable bank details shown on the public donate page.
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized"); exit; }

$pdo = pcm_pdo();
$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $bankName      = trim((string)($_POST['bank_name'] ?? ''));
    $accountName   = trim((string)($_POST['account_name'] ?? ''));
    $bsb           = trim((string)($_POST['bsb'] ?? ''));
    $accountNumber = trim((string)($_POST['account_number'] ?? ''));
    $bankNotes     = trim((string)($_POST['bank_notes'] ?? ''));

    $pdo->prepare("
        INSERT INTO donation_settings (id, bank_name, account_name, bsb, account_number, bank_notes, updated_by)
        VALUES (1, :bank_name, :account_name, :bsb, :account_number, :bank_notes, :updated_by)
        ON DUPLICATE KEY UPDATE
            bank_name = VALUES(bank_name),
            account_name = VALUES(account_name),
            bsb = VALUES(bsb),
            account_number = VALUES(account_number),
            bank_notes = VALUES(bank_notes),
            updated_by = VALUES(updated_by)
    ")->execute([
        ':bank_name' => $bankName ?: null,
        ':account_name' => $accountName ?: null,
        ':bsb' => $bsb ?: null,
        ':account_number' => $accountNumber ?: null,
        ':bank_notes' => $bankNotes ?: null,
        ':updated_by' => $_SESSION['username'] ?? 'admin',
    ]);

    $message = 'Donation bank details updated.';
    $success = true;
}

$settings = $pdo->query("SELECT * FROM donation_settings WHERE id = 1 LIMIT 1")->fetch() ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Donation Settings</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body id="page-top">
<div id="wrapper">
<?php include 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid py-3">

<?php if ($message): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    Swal.fire({icon:'<?= $success ? "success" : "error" ?>',title:<?= json_encode($message) ?>,timer:1800,showConfirmButton:false});
});
</script>
<?php endif; ?>

<h1 class="h3 mb-4 text-gray-800">Donation Settings</h1>

<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-university mr-1"></i>Bank Details for Donations</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label>Bank Name (optional)</label>
                        <input type="text" class="form-control" name="bank_name" value="<?= h($settings['bank_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Account Name</label>
                        <input type="text" class="form-control" name="account_name" value="<?= h($settings['account_name'] ?? '') ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>BSB</label>
                            <input type="text" class="form-control" name="bsb" value="<?= h($settings['bsb'] ?? '') ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Account Number</label>
                            <input type="text" class="form-control" name="account_number" value="<?= h($settings['account_number'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label>Notes (optional)</label>
                        <textarea class="form-control" name="bank_notes" rows="3" placeholder="e.g., Use your name as the payment reference"><?= h($settings['bank_notes'] ?? '') ?></textarea>
                        <small class="form-text text-muted">Shown on the public Donate page under the bank details.</small>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Bank Details</button>
                    <a href="donate" target="_blank" class="btn btn-outline-secondary"><i class="fas fa-external-link-alt mr-1"></i>View Donate Page</a>
                </form>
            </div>
        </div>
    </div>
</div>

</div>
</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>
</body>
</html>
