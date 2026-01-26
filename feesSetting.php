<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

/**
 * ✅ Admin-only guard
 */
$role = strtolower(trim($_SESSION['role'] ?? ''));
$allowedRoles = ['administrator', 'company_admin', 'system_owner'];

if (!in_array($role, $allowedRoles, true)) {
    header("Location: index-admin.php");
    exit;
}

$message = "";
$success = false;
$reload = false;

// ---------------- DB CONNECTION ----------------
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

// Load settings
$stmt = $pdo->query("SELECT * FROM fees_settings WHERE id = 1 LIMIT 1");
$settings = $stmt->fetch();

if (!$settings) {
    // Create default row safely
    $pdo->exec("INSERT INTO fees_settings (id) VALUES (1)");
    $stmt = $pdo->query("SELECT * FROM fees_settings WHERE id = 1 LIMIT 1");
    $settings = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $bank_name      = trim($_POST['bank_name'] ?? '');
        $account_name   = trim($_POST['account_name'] ?? '');
        $bsb            = trim($_POST['bsb'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $bank_notes     = trim($_POST['bank_notes'] ?? '');

        // Due dates (YYYY-MM-DD)
        $due_term1 = trim($_POST['due_term1'] ?? '');
        $due_term2 = trim($_POST['due_term2'] ?? '');
        $due_term3 = trim($_POST['due_term3'] ?? '');
        $due_term4 = trim($_POST['due_term4'] ?? '');

        // Convert empty to NULL
        $due_term1 = $due_term1 === '' ? null : $due_term1;
        $due_term2 = $due_term2 === '' ? null : $due_term2;
        $due_term3 = $due_term3 === '' ? null : $due_term3;
        $due_term4 = $due_term4 === '' ? null : $due_term4;

        // ✅ Amounts (NEW)
        $amount_termwise   = (float)($_POST['amount_termwise'] ?? 0);
        $amount_halfyearly = (float)($_POST['amount_halfyearly'] ?? 0);
        $amount_yearly     = (float)($_POST['amount_yearly'] ?? 0);

        if ($amount_termwise < 0 || $amount_halfyearly < 0 || $amount_yearly < 0) {
            throw new Exception("Amounts cannot be negative.");
        }

        $upd = $pdo->prepare("
            UPDATE fees_settings
            SET bank_name = :bank_name,
                account_name = :account_name,
                bsb = :bsb,
                account_number = :account_number,
                bank_notes = :bank_notes,
                due_term1 = :due_term1,
                due_term2 = :due_term2,
                due_term3 = :due_term3,
                due_term4 = :due_term4,
                amount_termwise = :amount_termwise,
                amount_halfyearly = :amount_halfyearly,
                amount_yearly = :amount_yearly
            WHERE id = 1
        ");
        $upd->execute([
            ':bank_name' => ($bank_name === '' ? null : $bank_name),
            ':account_name' => ($account_name === '' ? null : $account_name),
            ':bsb' => ($bsb === '' ? null : $bsb),
            ':account_number' => ($account_number === '' ? null : $account_number),
            ':bank_notes' => ($bank_notes === '' ? null : $bank_notes),
            ':due_term1' => $due_term1,
            ':due_term2' => $due_term2,
            ':due_term3' => $due_term3,
            ':due_term4' => $due_term4,
            ':amount_termwise' => $amount_termwise,
            ':amount_halfyearly' => $amount_halfyearly,
            ':amount_yearly' => $amount_yearly,
        ]);

        $message = "Fees settings updated successfully.";
        $success = true;
        $reload = true;

        // reload updated settings
        $stmt = $pdo->query("SELECT * FROM fees_settings WHERE id = 1 LIMIT 1");
        $settings = $stmt->fetch();

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Fees Settings</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .hint { font-size:12px; color:#6c757d; }
        .box { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:10px; padding:14px; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <h1 class="h3 mb-3 text-gray-800">Fees Settings</h1>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const msg = <?php echo json_encode($message); ?>;
                        const ok  = <?php echo $success ? 'true' : 'false'; ?>;
                        const reload = <?php echo $reload ? 'true' : 'false'; ?>;

                        if (msg) {
                            Swal.fire({
                                icon: ok ? 'success' : 'error',
                                title: msg,
                                showConfirmButton: true,
                                timer: ok ? 1400 : 6000
                            }).then(()=> { if (ok && reload) window.location.href = 'feesSettings.php'; });
                        }
                    });
                </script>

                <form method="POST">
                    <div class="row">
                        <!-- BANK DETAILS -->
                        <div class="col-lg-6 mb-3">
                            <div class="card shadow">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Bank Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="box">
                                        <div class="form-group">
                                            <label>Bank Name (optional)</label>
                                            <input type="text" class="form-control" name="bank_name" value="<?php echo h($settings['bank_name'] ?? ''); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label>Account Name</label>
                                            <input type="text" class="form-control" name="account_name" value="<?php echo h($settings['account_name'] ?? ''); ?>">
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group col-md-6">
                                                <label>BSB</label>
                                                <input type="text" class="form-control" name="bsb" value="<?php echo h($settings['bsb'] ?? ''); ?>">
                                            </div>
                                            <div class="form-group col-md-6">
                                                <label>Account Number</label>
                                                <input type="text" class="form-control" name="account_number" value="<?php echo h($settings['account_number'] ?? ''); ?>">
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label>Notes (optional)</label>
                                            <input type="text" class="form-control" name="bank_notes" value="<?php echo h($settings['bank_notes'] ?? ''); ?>" placeholder="e.g., Use reference exactly">
                                            <div class="hint mt-1">This will show on the Parent Fees Payment page.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- DUE DATES + AMOUNTS -->
                        <div class="col-lg-6 mb-3">
                            <div class="card shadow">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Due Dates & Fees Amounts</h6>
                                </div>
                                <div class="card-body">
                                    <div class="box">
                                        <div class="alert alert-info mb-3">
                                            <strong>Rules:</strong>
                                            <ul class="mb-0">
                                                <li><strong>TERM1 = HALF1 = YEARLY</strong></li>
                                                <li><strong>TERM3 = HALF2</strong></li>
                                            </ul>
                                        </div>

                                        <!-- ✅ AMOUNTS (NEW) -->
                                        <div class="form-row">
                                            <div class="form-group col-md-4">
                                                <label>Term-wise Amount</label>
                                                <input type="number" step="0.01" min="0" class="form-control"
                                                       name="amount_termwise"
                                                       value="<?php echo h($settings['amount_termwise'] ?? '65.00'); ?>">
                                            </div>

                                            <div class="form-group col-md-4">
                                                <label>Half-yearly Amount</label>
                                                <input type="number" step="0.01" min="0" class="form-control"
                                                       name="amount_halfyearly"
                                                       value="<?php echo h($settings['amount_halfyearly'] ?? '125.00'); ?>">
                                            </div>

                                            <div class="form-group col-md-4">
                                                <label>Yearly Amount</label>
                                                <input type="number" step="0.01" min="0" class="form-control"
                                                       name="amount_yearly"
                                                       value="<?php echo h($settings['amount_yearly'] ?? '250.00'); ?>">
                                            </div>
                                        </div>

                                        <hr>

                                        <div class="form-group">
                                            <label>Due Date — Term 1 (also Half 1 + Yearly)</label>
                                            <input type="date" class="form-control" name="due_term1" value="<?php echo h($settings['due_term1'] ?? ''); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label>Due Date — Term 2</label>
                                            <input type="date" class="form-control" name="due_term2" value="<?php echo h($settings['due_term2'] ?? ''); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label>Due Date — Term 3 (also Half 2)</label>
                                            <input type="date" class="form-control" name="due_term3" value="<?php echo h($settings['due_term3'] ?? ''); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label>Due Date — Term 4</label>
                                            <input type="date" class="form-control" name="due_term4" value="<?php echo h($settings['due_term4'] ?? ''); ?>">
                                        </div>

                                        <div class="hint">Parents will see the due date in the column headers.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                    <a href="feesManagement.php" class="btn btn-secondary ml-2">Back to Fees Management</a>
                </form>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
