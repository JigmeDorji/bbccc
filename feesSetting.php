<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/pcm_helpers.php";
require_login();

/**
 * ✅ Admin-only guard
 */
$role = strtolower(trim($_SESSION['role'] ?? ''));
$allowedRoles = ['administrator', 'admin', 'company_admin', 'system_owner', 'staff'];

if (!in_array($role, $allowedRoles, true)) {
    header("Location: index-admin");
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
    bbcc_fail_db($e);
}

function bbcc_ensure_term_class_total_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $cols = [
        'term1_total_classes',
        'term2_total_classes',
        'term3_total_classes',
        'term4_total_classes',
    ];
    foreach ($cols as $col) {
        $stmt = $pdo->query("SHOW COLUMNS FROM fees_settings LIKE " . $pdo->quote($col));
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE fees_settings ADD COLUMN {$col} INT NULL");
        }
    }
    $done = true;
}

function fs_ensure_class_charge_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pcm_class_fee_charges (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            charge_title VARCHAR(120) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            description VARCHAR(500) DEFAULT NULL,
            due_date DATE DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_class_charge_class (class_id),
            KEY idx_class_charge_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hasChargeCol = $pdo->query("SHOW COLUMNS FROM pcm_fee_payments LIKE 'class_charge_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasChargeCol) {
        $pdo->exec("ALTER TABLE pcm_fee_payments ADD COLUMN class_charge_id INT NULL AFTER enrolment_id");
    }
    $hasChargeIdx = $pdo->query("SHOW INDEX FROM pcm_fee_payments WHERE Key_name='idx_fee_class_charge'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasChargeIdx) {
        $pdo->exec("CREATE INDEX idx_fee_class_charge ON pcm_fee_payments (class_charge_id)");
    }
    $done = true;
}

function fs_apply_class_charge(PDO $pdo, int $chargeId): int {
    $chargeStmt = $pdo->prepare("SELECT * FROM pcm_class_fee_charges WHERE id=:id AND is_active=1 LIMIT 1");
    $chargeStmt->execute([':id' => $chargeId]);
    $charge = $chargeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$charge) throw new Exception("Charge not found or inactive.");

    $rowsStmt = $pdo->prepare("
        SELECT e.id AS enrolment_id, e.student_id, e.parent_id
        FROM class_assignments ca
        INNER JOIN pcm_enrolments e ON e.student_id = ca.student_id
        WHERE ca.class_id = :cid AND e.status = 'Approved'
        GROUP BY e.id, e.student_id, e.parent_id
    ");
    $rowsStmt->execute([':cid' => (int)$charge['class_id']]);
    $targets = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $inserted = 0;
    $ins = $pdo->prepare("
        INSERT INTO pcm_fee_payments
            (enrolment_id, class_charge_id, student_id, parent_id, plan_type, instalment_label, due_amount, paid_amount, due_date, status)
        VALUES
            (:eid, :ccid, :sid, :pid, 'Additional', :label, :due, 0, :due_date, 'Unpaid')
    ");
    foreach ($targets as $t) {
        $exists = $pdo->prepare("SELECT id FROM pcm_fee_payments WHERE enrolment_id=:eid AND class_charge_id=:ccid LIMIT 1");
        $exists->execute([':eid' => (int)$t['enrolment_id'], ':ccid' => $chargeId]);
        if ($exists->fetch(PDO::FETCH_ASSOC)) continue;
        $ins->execute([
            ':eid' => (int)$t['enrolment_id'],
            ':ccid' => $chargeId,
            ':sid' => (int)$t['student_id'],
            ':pid' => (int)$t['parent_id'],
            ':label' => (string)$charge['charge_title'],
            ':due' => (float)$charge['amount'],
            ':due_date' => !empty($charge['due_date']) ? $charge['due_date'] : null,
        ]);
        $inserted++;
    }
    return $inserted;
}

// Load settings
pcm_ensure_fees_campus_columns($pdo);
bbcc_ensure_term_class_total_columns($pdo);
fs_ensure_class_charge_schema($pdo);
$stmt = $pdo->query("SELECT * FROM fees_settings WHERE id = 1 LIMIT 1");
$settings = $stmt->fetch();

if (!$settings) {
    // Create default row safely
    $pdo->exec("INSERT INTO fees_settings (id) VALUES (1)");
    $stmt = $pdo->query("SELECT * FROM fees_settings WHERE id = 1 LIMIT 1");
    $settings = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['class_charge_action'])) {
    try {
        $act = trim((string)($_POST['class_charge_action'] ?? ''));
        if ($act === 'add') {
            $classId = (int)($_POST['charge_class_id'] ?? 0);
            $title = trim((string)($_POST['charge_title'] ?? ''));
            $amount = (float)($_POST['charge_amount'] ?? 0);
            $dueDate = trim((string)($_POST['charge_due_date'] ?? ''));
            $desc = trim((string)($_POST['charge_description'] ?? ''));
            if ($classId <= 0) throw new Exception("Please select a class.");
            if ($title === '') throw new Exception("Charge name is required.");
            if ($amount <= 0) throw new Exception("Amount must be greater than zero.");
            $dueDate = $dueDate === '' ? null : $dueDate;

            $pdo->beginTransaction();
            $insCharge = $pdo->prepare("
                INSERT INTO pcm_class_fee_charges
                (class_id, charge_title, amount, description, due_date, is_active, created_by)
                VALUES (:cid, :title, :amount, :descr, :due_date, 1, :by)
            ");
            $insCharge->execute([
                ':cid' => $classId, ':title' => $title, ':amount' => $amount,
                ':descr' => ($desc === '' ? null : $desc), ':due_date' => $dueDate,
                ':by' => (string)($_SESSION['username'] ?? 'admin'),
            ]);
            $newChargeId = (int)$pdo->lastInsertId();
            $applied = fs_apply_class_charge($pdo, $newChargeId);
            $pdo->commit();
            $message = "New class charge created and applied to {$applied} student(s).";
            $success = true; $reload = true;
        } elseif ($act === 'apply') {
            $chargeId = (int)($_POST['charge_id'] ?? 0);
            if ($chargeId <= 0) throw new Exception("Invalid charge.");
            $applied = fs_apply_class_charge($pdo, $chargeId);
            $message = "Charge applied to {$applied} missing student(s).";
            $success = true; $reload = true;
        } elseif ($act === 'toggle') {
            $chargeId = (int)($_POST['charge_id'] ?? 0);
            if ($chargeId <= 0) throw new Exception("Invalid charge.");
            $pdo->prepare("UPDATE pcm_class_fee_charges SET is_active=IF(is_active=1,0,1) WHERE id=:id")->execute([':id' => $chargeId]);
            $message = "Charge status updated.";
            $success = true; $reload = true;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
        $success = false; $reload = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['class_charge_action'])) {
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
        $term1_total_classes = (int)($_POST['term1_total_classes'] ?? 0);
        $term2_total_classes = (int)($_POST['term2_total_classes'] ?? 0);
        $term3_total_classes = (int)($_POST['term3_total_classes'] ?? 0);
        $term4_total_classes = (int)($_POST['term4_total_classes'] ?? 0);
        $campus_one_name   = trim($_POST['campus_one_name'] ?? '');
        $campus_two_name   = trim($_POST['campus_two_name'] ?? '');

        if ($amount_termwise < 0 || $amount_halfyearly < 0 || $amount_yearly < 0) {
            throw new Exception("Amounts cannot be negative.");
        }
        if ($term1_total_classes < 0 || $term2_total_classes < 0 || $term3_total_classes < 0 || $term4_total_classes < 0) {
            throw new Exception("Term total classes cannot be negative.");
        }
        if ($campus_one_name === '' || $campus_two_name === '') {
            throw new Exception("Both campus names are required.");
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
                amount_yearly = :amount_yearly,
                term1_total_classes = :term1_total_classes,
                term2_total_classes = :term2_total_classes,
                term3_total_classes = :term3_total_classes,
                term4_total_classes = :term4_total_classes,
                campus_one_name = :campus_one_name,
                campus_two_name = :campus_two_name
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
            ':term1_total_classes' => $term1_total_classes,
            ':term2_total_classes' => $term2_total_classes,
            ':term3_total_classes' => $term3_total_classes,
            ':term4_total_classes' => $term4_total_classes,
            ':campus_one_name' => $campus_one_name,
            ':campus_two_name' => $campus_two_name,
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

if (!function_exists('h')) {
    function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$classHasStatus = $pdo->query("SHOW COLUMNS FROM classes LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
if ($classHasStatus) {
    $classOptions = $pdo->query("SELECT id, class_name FROM classes WHERE status='Active' ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classOptions = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}
$classCharges = $pdo->query("
    SELECT cc.*, c.class_name,
           (SELECT COUNT(*) FROM pcm_fee_payments fp WHERE fp.class_charge_id = cc.id) AS applied_students
    FROM pcm_class_fee_charges cc
    LEFT JOIN classes c ON c.id = cc.class_id
    ORDER BY cc.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
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
                            }).then(()=> { if (ok && reload) window.location.href = 'feesSetting.php'; });
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

                                        <hr>

                                        <div class="form-group">
                                            <label>Campus 1 Name</label>
                                            <input type="text" class="form-control" name="campus_one_name" value="<?php echo h($settings['campus_one_name'] ?? 'Afred Deakin HS Campus'); ?>" required>
                                        </div>

                                        <div class="form-group">
                                            <label>Campus 2 Name</label>
                                            <input type="text" class="form-control" name="campus_two_name" value="<?php echo h($settings['campus_two_name'] ?? 'Hawker College Campus'); ?>" required>
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

                                        <h6 class="font-weight-bold text-primary mb-3">Term Class Targets (for Parent Dashboard)</h6>
                                        <div class="form-row">
                                            <div class="form-group col-md-6">
                                                <label>Total Classes — Term 1</label>
                                                <input type="number" min="0" step="1" class="form-control" name="term1_total_classes" value="<?php echo h($settings['term1_total_classes'] ?? '0'); ?>">
                                            </div>
                                            <div class="form-group col-md-6">
                                                <label>Total Classes — Term 2</label>
                                                <input type="number" min="0" step="1" class="form-control" name="term2_total_classes" value="<?php echo h($settings['term2_total_classes'] ?? '0'); ?>">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group col-md-6">
                                                <label>Total Classes — Term 3</label>
                                                <input type="number" min="0" step="1" class="form-control" name="term3_total_classes" value="<?php echo h($settings['term3_total_classes'] ?? '0'); ?>">
                                            </div>
                                            <div class="form-group col-md-6">
                                                <label>Total Classes — Term 4</label>
                                                <input type="number" min="0" step="1" class="form-control" name="term4_total_classes" value="<?php echo h($settings['term4_total_classes'] ?? '0'); ?>">
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
                    <a href="feesManagement" class="btn btn-secondary ml-2">Back to Fees Management</a>
                </form>

                <div class="card shadow mb-4 mt-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-book mr-1"></i>Class-Based Additional Charges</h6>
                        <span class="hint">Example: Textbook charge by class</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-3">
                            <input type="hidden" name="class_charge_action" value="add">
                            <div class="form-row">
                                <div class="form-group col-lg-3">
                                    <label class="mb-1">Class</label>
                                    <select name="charge_class_id" class="form-control" required>
                                        <option value="">Select class...</option>
                                        <?php foreach ($classOptions as $co): ?>
                                            <option value="<?php echo (int)$co['id']; ?>"><?php echo h($co['class_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group col-lg-3">
                                    <label class="mb-1">Charge Name</label>
                                    <input type="text" name="charge_title" class="form-control" maxlength="120" placeholder="Textbook Charge" required>
                                </div>
                                <div class="form-group col-lg-2">
                                    <label class="mb-1">Amount</label>
                                    <input type="number" step="0.01" min="0.01" name="charge_amount" class="form-control" required>
                                </div>
                                <div class="form-group col-lg-2">
                                    <label class="mb-1">Due Date</label>
                                    <input type="date" name="charge_due_date" class="form-control">
                                </div>
                                <div class="form-group col-lg-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-plus-circle mr-1"></i>Add & Apply</button>
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label class="mb-1">Description (optional)</label>
                                <input type="text" name="charge_description" class="form-control" maxlength="500" placeholder="Optional note">
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th><th>Class</th><th>Charge</th><th>Amount</th><th>Due Date</th><th>Applied Students</th><th>Status</th><th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($classCharges)): ?>
                                    <tr><td colspan="8" class="text-center text-muted">No class-based charges added yet.</td></tr>
                                <?php else: foreach ($classCharges as $i => $cc): ?>
                                    <tr>
                                        <td><?php echo (int)$i + 1; ?></td>
                                        <td><?php echo h((string)($cc['class_name'] ?? 'Unknown Class')); ?></td>
                                        <td><?php echo h((string)$cc['charge_title']); ?></td>
                                        <td>$<?php echo number_format((float)$cc['amount'], 2); ?></td>
                                        <td><?php echo h((string)($cc['due_date'] ?? '-')); ?></td>
                                        <td><?php echo (int)($cc['applied_students'] ?? 0); ?></td>
                                        <td><span class="badge badge-<?php echo ((int)$cc['is_active'] === 1) ? 'success' : 'secondary'; ?>"><?php echo ((int)$cc['is_active'] === 1) ? 'Active' : 'Inactive'; ?></span></td>
                                        <td class="nowrap">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="class_charge_action" value="apply">
                                                <input type="hidden" name="charge_id" value="<?php echo (int)$cc['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Apply Missing</button>
                                            </form>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="class_charge_action" value="toggle">
                                                <input type="hidden" name="charge_id" value="<?php echo (int)$cc['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo ((int)$cc['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
