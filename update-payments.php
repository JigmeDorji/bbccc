<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role === 'parent') {
    header("Location: index-admin");
    exit;
}

$message = "";
$success = false;
$reload  = false;
$updateOnlyMode = true; // Dedicated Update Payments page

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

// ---------------- LOAD FEES SETTINGS ----------------
$stmtSet = $pdo->query("SELECT * FROM fees_settings WHERE id = 1 LIMIT 1");
$feesSettings = $stmtSet->fetch() ?: [];

// ---------------- HELPERS ----------------
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fm_ensure_class_charge_schema(PDO $pdo): void {
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

    $colPlan = $pdo->query("SHOW COLUMNS FROM pcm_fee_payments LIKE 'plan_type'")->fetch(PDO::FETCH_ASSOC);
    $planType = strtolower((string)($colPlan['Type'] ?? ''));
    if ($planType !== '' && strpos($planType, 'additional') === false) {
        $pdo->exec("ALTER TABLE pcm_fee_payments MODIFY COLUMN plan_type ENUM('Term-wise','Half-yearly','Yearly','Additional') NOT NULL");
    }

    $colLabel = $pdo->query("SHOW COLUMNS FROM pcm_fee_payments LIKE 'instalment_label'")->fetch(PDO::FETCH_ASSOC);
    $labelType = strtolower((string)($colLabel['Type'] ?? ''));
    if ($labelType !== '' && preg_match('/varchar\((\d+)\)/', $labelType, $m)) {
        if ((int)$m[1] < 120) {
            $pdo->exec("ALTER TABLE pcm_fee_payments MODIFY COLUMN instalment_label VARCHAR(120) NOT NULL");
        }
    }

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

function fm_apply_class_charge(PDO $pdo, int $chargeId): int {
    $chargeStmt = $pdo->prepare("
        SELECT cc.*, c.class_name
        FROM pcm_class_fee_charges cc
        LEFT JOIN classes c ON c.id = cc.class_id
        WHERE cc.id = :id AND cc.is_active = 1
        LIMIT 1
    ");
    $chargeStmt->execute([':id' => $chargeId]);
    $charge = $chargeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$charge) {
        throw new Exception("Charge not found or inactive.");
    }

    $rowsStmt = $pdo->prepare("
        SELECT e.id AS enrolment_id, e.student_id, e.parent_id
        FROM class_assignments ca
        INNER JOIN pcm_enrolments e ON e.student_id = ca.student_id
        WHERE ca.class_id = :cid
          AND e.status = 'Approved'
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
        $exists = $pdo->prepare("
            SELECT id
            FROM pcm_fee_payments
            WHERE enrolment_id = :eid
              AND class_charge_id = :ccid
            LIMIT 1
        ");
        $exists->execute([
            ':eid' => (int)$t['enrolment_id'],
            ':ccid' => $chargeId
        ]);
        if ($exists->fetch(PDO::FETCH_ASSOC)) {
            continue;
        }

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

function badge_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'approved') return 'success';
    if ($s === 'rejected') return 'danger';
    return 'warning';
}

function installment_label(string $code): string {
    return match ($code) {
        'TERM1' => 'Term 1',
        'TERM2' => 'Term 2',
        'TERM3' => 'Term 3',
        'TERM4' => 'Term 4',
        'HALF1' => 'Half 1',
        'HALF2' => 'Half 2',
        'YEARLY' => 'Yearly',
        default => $code
    };
}

function installment_code_from_label(string $label): string {
    $l = strtolower(trim($label));
    return match ($l) {
        'term 1', 'term1' => 'TERM1',
        'term 2', 'term2' => 'TERM2',
        'term 3', 'term3' => 'TERM3',
        'term 4', 'term4' => 'TERM4',
        'half 1', 'half1', 'half-year 1', 'half-yearly 1' => 'HALF1',
        'half 2', 'half2', 'half-year 2', 'half-yearly 2' => 'HALF2',
        'yearly' => 'YEARLY',
        default => '',
    };
}

function proof_type(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return ($ext === 'pdf') ? 'pdf' : 'img';
}

/**
 * Due date rules requested:
 * TERM1 = HALF1 = YEARLY -> due_term1
 * TERM3 = HALF2 -> due_term3
 * TERM2 -> due_term2
 * TERM4 -> due_term4
 */
function installment_due_date(array $settings, string $installmentCode): ?string {
    return match ($installmentCode) {
        'TERM1','HALF1','YEARLY' => ($settings['due_term1'] ?? null),
        'TERM2' => ($settings['due_term2'] ?? null),
        'TERM3','HALF2' => ($settings['due_term3'] ?? null),
        'TERM4' => ($settings['due_term4'] ?? null),
        default => null
    };
}

function pretty_date(?string $d): string {
    return $d ? htmlspecialchars($d) : '-';
}

/**
 * ✅ First installment codes per plan (used for paid counts & reference column)
 * Term-wise -> TERM1
 * Half-yearly -> HALF1
 * Yearly -> YEARLY
 */
function first_installment_code(string $planType): string {
    return match ($planType) {
        'Term-wise' => 'TERM1',
        'Half-yearly' => 'HALF1',
        'Yearly' => 'YEARLY',
        default => 'TERM1'
    };
}

function normalize_status($v): string {
    return strtolower(trim((string)$v));
}

fm_ensure_class_charge_schema($pdo);

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

            $dup = $pdo->prepare("
                SELECT id
                FROM pcm_class_fee_charges
                WHERE class_id = :cid
                  AND LOWER(charge_title) = LOWER(:title)
                LIMIT 1
            ");
            $dup->execute([':cid' => $classId, ':title' => $title]);
            if ($dup->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("A charge with this name already exists for this class.");
            }

            $pdo->beginTransaction();
            $insCharge = $pdo->prepare("
                INSERT INTO pcm_class_fee_charges
                    (class_id, charge_title, amount, description, due_date, is_active, created_by)
                VALUES
                    (:cid, :title, :amount, :descr, :due_date, 1, :by)
            ");
            $insCharge->execute([
                ':cid' => $classId,
                ':title' => $title,
                ':amount' => $amount,
                ':descr' => ($desc === '' ? null : $desc),
                ':due_date' => $dueDate,
                ':by' => (string)($_SESSION['username'] ?? 'admin'),
            ]);
            $newChargeId = (int)$pdo->lastInsertId();
            $applied = fm_apply_class_charge($pdo, $newChargeId);
            $pdo->commit();

            $message = "New class charge created and applied to {$applied} student(s).";
            $success = true;
            $reload = true;
        } elseif ($act === 'apply') {
            $chargeId = (int)($_POST['charge_id'] ?? 0);
            if ($chargeId <= 0) throw new Exception("Invalid charge.");
            $applied = fm_apply_class_charge($pdo, $chargeId);
            $message = "Charge applied to {$applied} missing student(s).";
            $success = true;
            $reload = true;
        } elseif ($act === 'toggle') {
            $chargeId = (int)($_POST['charge_id'] ?? 0);
            if ($chargeId <= 0) throw new Exception("Invalid charge.");
            $pdo->prepare("UPDATE pcm_class_fee_charges SET is_active = IF(is_active=1,0,1) WHERE id=:id")->execute([':id' => $chargeId]);
            $message = "Charge status updated.";
            $success = true;
            $reload = true;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- ACTIONS: APPROVE / REJECT / UPDATE ----------------
// NOTE: "Update" here will open an inline modal to change status (Approved/Rejected/Pending) + reset verify meta.
// If you want update to edit due_amount, remarks, etc, tell me and I will add those fields too.

if (isset($_GET['fee_action'], $_GET['fee_id'])) {
    try {
        $feeId = (int)$_GET['fee_id'];
        $act   = strtolower(trim($_GET['fee_action']));

        if ($feeId <= 0) throw new Exception("Invalid fee ID.");

        // approve / reject keep as before
        if (in_array($act, ['approve','reject'], true)) {
            $newStatus = ($act === 'approve') ? 'Approved' : 'Rejected';
            $verifiedBy = $_SESSION['username'] ?? 'admin';

            $stmt = $pdo->prepare("
                UPDATE fees_payments
                SET status = :st,
                    verified_by = :vb,
                    verified_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':st'=>$newStatus, ':vb'=>$verifiedBy, ':id'=>$feeId]);

            $message = "Installment {$newStatus} successfully.";
            $success = true;
            $reload  = true;
        }

        // update: handled via POST (safer), but keep GET to open modal only (UI)
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload  = false;
    }
}

// ---------------- UPDATE (POST) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fee_id'])) {
    try {
        $feeId = (int)($_POST['update_fee_id'] ?? 0);
        $newStatus = trim((string)($_POST['new_status'] ?? ''));

        if ($feeId <= 0) throw new Exception("Invalid fee ID.");
        if (!in_array($newStatus, ['Pending','Approved','Rejected'], true)) {
            throw new Exception("Invalid status.");
        }

        $verifiedBy = $_SESSION['username'] ?? 'admin';

        // If Approved/Rejected => set verified_*; if Pending => clear verified_*
        if ($newStatus === 'Pending') {
            $stmt = $pdo->prepare("
                UPDATE fees_payments
                SET status = 'Pending',
                    verified_by = NULL,
                    verified_at = NULL
                WHERE id = :id
            ");
            $stmt->execute([':id'=>$feeId]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE fees_payments
                SET status = :st,
                    verified_by = :vb,
                    verified_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':st'=>$newStatus, ':vb'=>$verifiedBy, ':id'=>$feeId]);
        }

        $message = "Installment updated successfully.";
        $success = true;
        $reload  = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload  = false;
    }
}

// ---------------- UPDATE PAYMENTS (DEDICATED MODE) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_payment_row') {
    try {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $due = (float)($_POST['due_amount'] ?? 0);
        $paid = (float)($_POST['paid_amount'] ?? 0);
        $ref = trim((string)($_POST['payment_ref'] ?? ''));
        $st = trim((string)($_POST['status'] ?? 'Unpaid'));
        $allowed = ['Unpaid','Pending','Verified','Rejected'];

        if ($pid <= 0) throw new Exception("Invalid payment record.");
        if ($due < 0 || $paid < 0) throw new Exception("Amounts cannot be negative.");
        if (!in_array($st, $allowed, true)) throw new Exception("Invalid status.");

        $reviewer = (string)($_SESSION['username'] ?? 'admin');
        if (in_array($st, ['Verified', 'Rejected'], true)) {
            $stmt = $pdo->prepare("
                UPDATE pcm_fee_payments
                SET due_amount = :due,
                    paid_amount = :paid,
                    payment_ref = :ref,
                    status = :st,
                    verified_by = :by,
                    verified_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':due' => $due,
                ':paid' => $paid,
                ':ref' => ($ref !== '' ? $ref : null),
                ':st' => $st,
                ':by' => $reviewer,
                ':id' => $pid
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE pcm_fee_payments
                SET due_amount = :due,
                    paid_amount = :paid,
                    payment_ref = :ref,
                    status = :st,
                    verified_by = NULL,
                    verified_at = NULL
                WHERE id = :id
            ");
            $stmt->execute([
                ':due' => $due,
                ':paid' => $paid,
                ':ref' => ($ref !== '' ? $ref : null),
                ':st' => $st,
                ':id' => $pid
            ]);
        }

        $message = "Payment updated successfully.";
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- LOAD ALL FEE DATA ----------------
// Parent column can be either parentId (legacy) or parent_id (newer)
$studentParentColumn = 'parentId';
$colStmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'parent_id'");
if ($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC)) {
    $studentParentColumn = 'parent_id';
}

// 1) Enrollment base rows: ensures student details appear under each payment method
$stmtEnroll = $pdo->prepare("
    SELECT
        e.id AS enrolment_id,
        e.student_id,
        e.fee_plan AS plan_type,
        e.status AS enrolment_status,
        e.fee_amount AS enrollment_amount,
        e.payment_ref AS enrollment_reference,
        e.proof_path AS enrollment_proof,
        s.student_id AS public_student_id,
        s.student_name,
        p.full_name AS parent_name,
        p.email AS parent_email,
        p.phone AS parent_phone,
        p.address AS parent_address
    FROM pcm_enrolments e
    JOIN students s ON s.id = e.student_id
    LEFT JOIN parents p ON p.id = s.`{$studentParentColumn}`
    WHERE e.fee_plan IN ('Term-wise','Half-yearly','Yearly')
    ORDER BY s.id DESC, e.id DESC
");
$stmtEnroll->execute();
$enrollmentRows = $stmtEnroll->fetchAll();

// 2) Installment rows: keep payment-status details/actions from existing fees_payments table
$stmt = $pdo->prepare("
    SELECT
        fp.*,
        s.student_id AS public_student_id,
        s.student_name,
        s.approval_status AS enrollment_status,
        s.payment_plan,
        s.payment_reference AS enrollment_reference,
        s.payment_proof AS enrollment_proof,
        p.full_name AS parent_name,
        p.email AS parent_email,
        p.phone AS parent_phone,
        p.address AS parent_address
    FROM fees_payments fp
    JOIN students s ON s.id = fp.student_id
    LEFT JOIN parents p ON p.id = s.`{$studentParentColumn}`
    ORDER BY s.id DESC, fp.id ASC
");
$stmt->execute();
$rows = $stmt->fetchAll();

// Group by plan -> student -> installment
$group = [
    'Term-wise' => [],
    'Half-yearly' => [],
    'Yearly' => [],
];

// Seed group from enrollments first (so students always appear per payment method)
foreach ($enrollmentRows as $r) {
    $plan = (string)$r['plan_type'];
    if (!isset($group[$plan])) $group[$plan] = [];

    $sid = (string)$r['student_id'];
    if ($sid === '') continue;

    if (!isset($group[$plan][$sid])) {
        $group[$plan][$sid] = [
            'student_db_id' => $sid,
            'public_student_id' => $r['public_student_id'] ?? '',
            'student_name' => $r['student_name'] ?? '',
            'payment_plan' => $r['plan_type'] ?? $plan,
            'enrollment_status' => $r['enrolment_status'] ?? 'Pending',
            'enrollment_amount' => (float)($r['enrollment_amount'] ?? 0),
            'enrollment_reference' => $r['enrollment_reference'] ?? '',
            'enrollment_proof' => $r['enrollment_proof'] ?? '',
            'parent_name' => $r['parent_name'] ?? '',
            'parent_email' => $r['parent_email'] ?? '',
            'parent_phone' => $r['parent_phone'] ?? '',
            'parent_address' => $r['parent_address'] ?? '',
            'installments' => []
        ];
    }
}

foreach ($rows as $r) {
    $plan = (string)$r['plan_type'];
    if (!isset($group[$plan])) $group[$plan] = [];

    $sid = (string)$r['student_id']; // students.id stored in fees_payments
    if (!isset($group[$plan][$sid])) {
        $group[$plan][$sid] = [
            'student_db_id' => $sid,
            'public_student_id' => $r['public_student_id'] ?? '',
            'student_name' => $r['student_name'] ?? '',
            'payment_plan' => $r['payment_plan'] ?? $plan,
            'enrollment_status' => $r['enrollment_status'] ?? 'Pending',
            'enrollment_amount' => 0.0,
            'enrollment_reference' => $r['enrollment_reference'] ?? '',
            'enrollment_proof' => $r['enrollment_proof'] ?? '',
            'parent_name' => $r['parent_name'] ?? '',
            'parent_email' => $r['parent_email'] ?? '',
            'parent_phone' => $r['parent_phone'] ?? '',
            'parent_address' => $r['parent_address'] ?? '',
            'installments' => []
        ];
    }

    $code = (string)($r['installment_code'] ?? '');
    if ($code === '') {
        $code = installment_code_from_label((string)($r['instalment_label'] ?? ''));
    }
    if ($code === '') {
        continue;
    }
    $group[$plan][$sid]['installments'][$code] = $r;
}

// Plans
$plans = [
    'Term-wise' => ['TERM1','TERM2','TERM3','TERM4'],
    'Half-yearly' => ['HALF1','HALF2'],
    'Yearly' => ['YEARLY'],
];

if ($updateOnlyMode) {
    foreach ($plans as $planName => $codes) {
        if (empty($group[$planName])) {
            continue;
        }
        foreach ($group[$planName] as $sid => $info) {
            $hasEditable = false;
            foreach ($codes as $c) {
                $inst = $info['installments'][$c] ?? null;
                if (is_array($inst) && (int)($inst['id'] ?? 0) > 0) {
                    $hasEditable = true;
                    break;
                }
            }
            if (!$hasEditable) {
                unset($group[$planName][$sid]);
            }
        }
    }
}

// ---------------- GLOBAL SUMMARY (students/enrollments/paid counts) ----------------
// count unique students shown in grouped plan tables
$seenStudents = [];
foreach ($group as $planRows) {
    foreach ($planRows as $sid => $_v) {
        $seenStudents[(string)$sid] = true;
    }
}
$totalStudents = count($seenStudents);

// Paid counts by installment code (unique students who have that installment Approved)
$paidCounts = [
    'TERM1'=>0,'TERM2'=>0,'TERM3'=>0,'TERM4'=>0,
    'HALF1'=>0,'HALF2'=>0,
    'YEARLY'=>0
];
$paidSeen = [
    'TERM1'=>[],'TERM2'=>[],'TERM3'=>[],'TERM4'=>[],
    'HALF1'=>[],'HALF2'=>[],
    'YEARLY'=>[]
];

foreach ($rows as $r) {
    $code = (string)($r['installment_code'] ?? '');
    $sid  = (string)($r['student_id'] ?? '');
    if ($sid === '' || $code === '') continue;

    if (normalize_status($r['status'] ?? '') === 'approved') {
        $paidSeen[$code][$sid] = true;
    }
}
foreach ($paidSeen as $code => $set) {
    $paidCounts[$code] = count($set);
}

// ---------------- New collection summary requested ----------------
// Source of truth: confirmed enrollments (pcm_enrolments.status = 'Approved')
$totalFeesCollected = 0.0;
$paidStudentsAll = [];
$paidStudentsByPlan = [
    'Term-wise' => [],
    'Half-yearly' => [],
    'Yearly' => [],
];

try {
    $stmtConfirmed = $pdo->query("
        SELECT student_id, fee_plan, fee_amount
        FROM pcm_enrolments
        WHERE status = 'Approved'
    ");
    $confirmedRows = $stmtConfirmed->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $confirmedRows = [];
}

foreach ($confirmedRows as $r) {
    $sid = (string)($r['student_id'] ?? '');
    if ($sid === '') continue;

    $totalFeesCollected += (float)($r['fee_amount'] ?? 0);
    $paidStudentsAll[$sid] = true;

    $plan = (string)($r['fee_plan'] ?? '');
    if (isset($paidStudentsByPlan[$plan])) {
        $paidStudentsByPlan[$plan][$sid] = true;
    }
}

$totalStudentsPaid = count($paidStudentsAll);
$paidTermWise = count($paidStudentsByPlan['Term-wise']);
$paidHalfYearly = count($paidStudentsByPlan['Half-yearly']);
$paidYearly = count($paidStudentsByPlan['Yearly']);

// ---------------- Summary values (bank + due) ----------------
$bankName = $feesSettings['bank_name'] ?? '';
$accName  = $feesSettings['account_name'] ?? '';
$bsb      = $feesSettings['bsb'] ?? '';
$accNo    = $feesSettings['account_number'] ?? '';
$notes    = $feesSettings['bank_notes'] ?? '';

$due1 = $feesSettings['due_term1'] ?? null;
$due2 = $feesSettings['due_term2'] ?? null;
$due3 = $feesSettings['due_term3'] ?? null;
$due4 = $feesSettings['due_term4'] ?? null;

$classOptions = $pdo->query("SELECT id, class_name FROM classes WHERE active = 1 ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$classCharges = $pdo->query("
    SELECT cc.*,
           c.class_name,
           (
             SELECT COUNT(*)
             FROM pcm_fee_payments fp
             WHERE fp.class_charge_id = cc.id
           ) AS applied_students
    FROM pcm_class_fee_charges cc
    LEFT JOIN classes c ON c.id = cc.class_id
    ORDER BY cc.created_at DESC, cc.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$updatePayments = [];
$updateCounts = ['Pending' => 0, 'Verified' => 0, 'Rejected' => 0, 'Unpaid' => 0];
if ($updateOnlyMode) {
    $stmtUpd = $pdo->query("
        SELECT f.*, s.student_name, s.student_id AS stu_code, p.full_name AS parent_name
        FROM pcm_fee_payments f
        JOIN students s ON s.id = f.student_id
        LEFT JOIN parents p ON p.id = f.parent_id
        WHERE f.plan_type IN ('Term-wise','Half-yearly','Yearly')
        ORDER BY FIELD(f.status,'Pending','Rejected','Unpaid','Verified'), f.submitted_at DESC, f.id DESC
        LIMIT 500
    ");
    $updatePayments = $stmtUpd->fetchAll(PDO::FETCH_ASSOC);
    foreach ($updatePayments as $row) {
        $k = (string)($row['status'] ?? '');
        if (isset($updateCounts[$k])) $updateCounts[$k]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Update Payments</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .mini { font-size:12px; color:#6c757d; }
        .nowrap { white-space:nowrap; }
        td.wrap { white-space: normal !important; max-width: 260px; }

        .summary-box { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:12px; padding:14px; }
        .due-pill { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#1b4fd6; font-size:11px; font-weight:700; margin-right:6px; margin-bottom:6px; }
        .kv strong { display:inline-block; min-width:120px; }

        /* Top summary cards */
        .stat-card { border:1px solid #e3e6f0; border-radius:12px; padding:14px; background:#fff; }
        .stat-label { font-size:12px; font-weight:800; text-transform:uppercase; color:#6c757d; }
        .stat-value { font-size:28px; font-weight:900; line-height:1.1; }
        .stat-sub { font-size:12px; color:#6c757d; }

        /* Proof UI */
        .proof-thumb {
            display:inline-flex;
            align-items:center;
            gap:8px;
            cursor:pointer;
            text-decoration:none;
        }
        .thumb-img {
            width:42px;
            height:42px;
            object-fit:cover;
            border-radius:8px;
            border:1px solid #e3e6f0;
            background:#fff;
        }
        .thumb-icon {
            width:42px;
            height:42px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:8px;
            border:1px solid #e3e6f0;
            background:#fff;
            color:#d93025;
            font-size:18px;
        }

        /* SweetAlert modal sizing */
        .swal2-popup { width: 920px !important; max-width: 96vw !important; }
        .proof-frame { width: 100%; height: 70vh; border: 1px solid #e3e6f0; border-radius: 12px; }

        .proof-stage {
            width: 100%;
            max-height: 72vh;
            overflow: auto;
            border: 1px solid #e3e6f0;
            border-radius: 12px;
            padding: 10px;
            background: #fafbff;
        }
        .proof-img {
            display:block;
            transform-origin: top left;
            border-radius: 10px;
            border: 1px solid #e3e6f0;
            background:#fff;
        }
        .swal-toolbar {
            display:flex;
            gap:8px;
            justify-content:center;
            flex-wrap:wrap;
            margin-top:10px;
        }

        thead th { vertical-align: middle !important; }
        thead .mini { font-size:11px; font-weight:700; }

        .ref-col { max-width: 220px; }

        /* Payment method tabs (dzoClass-style filter pills) */
        .method-tabs-wrap { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:12px; padding:14px 16px; margin-bottom:16px; }
        .method-pill {
            border-radius:20px !important;
            font-weight:600;
            font-size:.82rem;
            padding:6px 16px;
            border:2px solid transparent;
            margin-right:6px;
            margin-bottom:6px;
        }
        .method-pill.is-active-all { background:#881b12; color:#fff; border-color:#881b12; }
        .method-pill.is-active-term-wise { background:#4e73df; color:#fff; border-color:#4e73df; }
        .method-pill.is-active-half-yearly { background:#36b9cc; color:#fff; border-color:#36b9cc; }
        .method-pill.is-active-yearly { background:#1cc88a; color:#fff; border-color:#1cc88a; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h1 class="h3 text-gray-800 mb-0">Update Payments</h1>

                    <a href="feesManagement" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-table"></i> Fees Overview
                    </a>
                </div>

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
                            }).then(()=> { if (ok && reload) window.location.href = 'update-payments.php'; });
                        }
                    });
                </script>

                <?php if (!$updateOnlyMode): ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-book mr-1"></i>Class-Based Additional Charges</h6>
                        <span class="mini">Example: Textbook charge by class</span>
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
                                <input type="text" name="charge_description" class="form-control" maxlength="500" placeholder="Optional note shown for admin reference">
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Class</th>
                                        <th>Charge</th>
                                        <th>Amount</th>
                                        <th>Due Date</th>
                                        <th>Applied Students</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($classCharges)): ?>
                                    <tr><td colspan="8" class="text-center text-muted">No class-based charges added yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($classCharges as $i => $cc): ?>
                                        <tr>
                                            <td><?php echo (int)$i + 1; ?></td>
                                            <td><?php echo h((string)($cc['class_name'] ?? 'Unknown Class')); ?></td>
                                            <td><?php echo h((string)$cc['charge_title']); ?></td>
                                            <td>$<?php echo number_format((float)$cc['amount'], 2); ?></td>
                                            <td><?php echo h((string)($cc['due_date'] ?? '-')); ?></td>
                                            <td><?php echo (int)($cc['applied_students'] ?? 0); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo ((int)$cc['is_active'] === 1) ? 'success' : 'secondary'; ?>">
                                                    <?php echo ((int)$cc['is_active'] === 1) ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td class="nowrap">
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="class_charge_action" value="apply">
                                                    <input type="hidden" name="charge_id" value="<?php echo (int)$cc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Apply Missing</button>
                                                </form>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="class_charge_action" value="toggle">
                                                    <input type="hidden" name="charge_id" value="<?php echo (int)$cc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                        <?php echo ((int)$cc['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ✅ SUMMARY: COLLECTED FEES + PAID STUDENTS -->
                <div class="row mb-3">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="stat-card shadow-sm">
                            <div class="stat-label text-success">Total Fees Collected</div>
                            <div class="stat-value">$<?php echo number_format($totalFeesCollected, 2); ?></div>
                            <div class="stat-sub">Based on confirmed enrollments</div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="stat-card shadow-sm">
                            <div class="stat-label text-primary">Students Paid</div>
                            <div class="stat-value"><?php echo (int)$totalStudentsPaid; ?></div>
                            <div class="stat-sub">Unique confirmed enrollments</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="stat-card shadow-sm">
                            <div class="stat-label text-info">Term-wise</div>
                            <div class="stat-value"><?php echo (int)$paidTermWise; ?></div>
                            <div class="stat-sub">Students paid</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="stat-card shadow-sm">
                            <div class="stat-label text-info">Half-yearly</div>
                            <div class="stat-value"><?php echo (int)$paidHalfYearly; ?></div>
                            <div class="stat-sub">Students paid</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="stat-card shadow-sm">
                            <div class="stat-label text-info">Yearly</div>
                            <div class="stat-value"><?php echo (int)$paidYearly; ?></div>
                            <div class="stat-sub">Students paid</div>
                        </div>
                    </div>
                </div>

                <!-- ✅ SUMMARY: ENROLLMENTS + PAID COUNTS -->
                <div class="row mb-3">
                    <div class="col-lg-3 mb-3">
                        <div class="stat-card shadow-sm">
                            <div class="stat-label text-primary">Enrollments</div>
                            <div class="stat-value"><?php echo (int)$totalStudents; ?></div>
                            <div class="stat-sub">Students in fees system</div>
                        </div>
                    </div>

                    <div class="col-lg-9 mb-3">
                        <div class="stat-card shadow-sm">
                            <div class="stat-label text-info">Paid students by installment (Approved)</div>
                            <div class="mt-2 mini">
                                <span class="due-pill">Term 1: <?php echo (int)$paidCounts['TERM1']; ?></span>
                                <span class="due-pill">Term 2: <?php echo (int)$paidCounts['TERM2']; ?></span>
                                <span class="due-pill">Term 3: <?php echo (int)$paidCounts['TERM3']; ?></span>
                                <span class="due-pill">Term 4: <?php echo (int)$paidCounts['TERM4']; ?></span>
                                <span class="due-pill">Half 1: <?php echo (int)$paidCounts['HALF1']; ?></span>
                                <span class="due-pill">Half 2: <?php echo (int)$paidCounts['HALF2']; ?></span>
                                <span class="due-pill">Yearly: <?php echo (int)$paidCounts['YEARLY']; ?></span>
                            </div>
                            <div class="stat-sub mt-2">
                                Counts are unique students whose installment status is <strong>Approved</strong>.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ✅ BANK + DUE SUMMARY BOX -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-info-circle"></i> Current Bank & Due Dates Summary
                        </h6>
                        <span class="mini">These values come from Fees Settings</span>
                    </div>
                    <div class="card-body">
                        <div class="summary-box">
                            <div class="row">
                                <div class="col-lg-6 mb-3 mb-lg-0">
                                    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-university"></i> Bank Details</h6>
                                    <div class="kv mini"><strong>Bank:</strong> <?php echo h($bankName ?: '-'); ?></div>
                                    <div class="kv mini"><strong>Account Name:</strong> <?php echo h($accName ?: '-'); ?></div>
                                    <div class="kv mini"><strong>BSB:</strong> <?php echo h($bsb ?: '-'); ?></div>
                                    <div class="kv mini"><strong>Account No:</strong> <?php echo h($accNo ?: '-'); ?></div>
                                    <?php if (!empty($notes)): ?>
                                        <div class="mini mt-2"><strong>Notes:</strong> <?php echo h($notes); ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-lg-6">
                                    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-calendar-alt"></i> Due Dates</h6>
                                    <div class="mini mb-2">
                                        <span class="due-pill">TERM1 / HALF1 / YEARLY: <?php echo pretty_date($due1); ?></span>
                                        <span class="due-pill">TERM2: <?php echo pretty_date($due2); ?></span>
                                        <span class="due-pill">TERM3 / HALF2: <?php echo pretty_date($due3); ?></span>
                                        <span class="due-pill">TERM4: <?php echo pretty_date($due4); ?></span>
                                    </div>
                                    <div class="mini text-muted">
                                        Rule applied: Term1 = Half1 = Yearly, and Term3 = Half2.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($updateOnlyMode): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-edit mr-1"></i>Update Payments</h6>
                            <a href="feesManagement" class="btn btn-sm btn-outline-secondary">Back to Full Fees</a>
                        </div>
                        <div class="card-body">
                            <div class="mb-3" style="display:flex;gap:14px;flex-wrap:wrap;font-size:.84rem;">
                                <span><strong>Pending:</strong> <?= (int)$updateCounts['Pending'] ?></span>
                                <span><strong>Verified:</strong> <?= (int)$updateCounts['Verified'] ?></span>
                                <span><strong>Rejected:</strong> <?= (int)$updateCounts['Rejected'] ?></span>
                                <span><strong>Unpaid:</strong> <?= (int)$updateCounts['Unpaid'] ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>#</th><th>Child</th><th>Parent</th><th>Plan</th><th>Instalment</th><th>Due</th><th>Paid</th><th>Ref</th><th>Status</th><th>Proof</th><th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($updatePayments)): ?>
                                        <tr><td colspan="11" class="text-muted">No payment rows found.</td></tr>
                                    <?php else: foreach ($updatePayments as $i => $up): ?>
                                        <tr>
                                            <form method="POST">
                                                <td><?= $i + 1 ?></td>
                                                <td><?= h($up['student_name']) ?> <small class="text-muted">(<?= h($up['stu_code']) ?>)</small></td>
                                                <td><?= h($up['parent_name'] ?? '-') ?></td>
                                                <td><?= h($up['plan_type']) ?></td>
                                                <td><?= h($up['instalment_label']) ?></td>
                                                <td><input type="number" step="0.01" min="0" name="due_amount" class="form-control form-control-sm" value="<?= h((string)$up['due_amount']) ?>"></td>
                                                <td><input type="number" step="0.01" min="0" name="paid_amount" class="form-control form-control-sm" value="<?= h((string)$up['paid_amount']) ?>"></td>
                                                <td><input type="text" name="payment_ref" class="form-control form-control-sm" value="<?= h((string)($up['payment_ref'] ?? '')) ?>"></td>
                                                <td>
                                                    <select name="status" class="form-control form-control-sm">
                                                        <?php foreach (['Unpaid','Pending','Verified','Rejected'] as $st): ?>
                                                            <option value="<?= $st ?>" <?= ((string)$up['status'] === $st) ? 'selected' : '' ?>><?= $st ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td><?= !empty($up['proof_path']) ? '<a href="'.h((string)$up['proof_path']).'" target="_blank">View</a>' : '—' ?></td>
                                                <td class="nowrap">
                                                    <input type="hidden" name="action" value="update_payment_row">
                                                    <input type="hidden" name="payment_id" value="<?= (int)$up['id'] ?>">
                                                    <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-save mr-1"></i>Save</button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                <div class="method-tabs-wrap">
                    <div class="mini font-weight-bold text-uppercase mb-2" style="letter-spacing:.4px;">Payment Method</div>
                    <button type="button" class="btn method-pill is-active-all js-method-pill" data-plan="all">All</button>
                    <button type="button" class="btn btn-outline-primary method-pill js-method-pill" data-plan="term-wise">Term-wise</button>
                    <button type="button" class="btn btn-outline-info method-pill js-method-pill" data-plan="half-yearly">Half-yearly</button>
                    <button type="button" class="btn btn-outline-success method-pill js-method-pill" data-plan="yearly">Yearly</button>
                </div>

                <?php foreach ($plans as $planName => $codes): ?>
                    <div class="card shadow mb-4 fee-plan-section" data-plan="<?php echo strtolower($planName); ?>">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <?php echo htmlspecialchars($planName); ?> Fees
                            </h6>
                            <span class="mini">Installments: <?php echo count($codes); ?></span>
                        </div>

                        <div class="card-body">
                            <?php if (empty($group[$planName])): ?>
                                <div class="alert alert-light mb-0">No records found for this plan.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered" width="100%">
                                        <thead class="thead-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Parent</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th class="wrap">Address</th>
                                            <th class="nowrap">Amount</th>
                                            <th class="nowrap">Status</th>
                                            <th class="ref-col">Reference No.</th>

                                            <!-- ✅ Due date shown in column header -->
                                            <?php foreach ($codes as $c): ?>
                                                <?php $dueHeader = installment_due_date($feesSettings, $c); ?>
                                                <th class="nowrap text-center">
                                                    <div><?php echo htmlspecialchars(installment_label($c)); ?></div>
                                                    <div class="mini text-muted">Due: <?php echo $dueHeader ? htmlspecialchars($dueHeader) : '-'; ?></div>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                        </thead>

                                        <tbody>
                                        <?php foreach ($group[$planName] as $sid => $info): ?>
                                            <?php
                                                $totalPlanAmount = 0.0;
                                                $hasInstallmentRows = false;
                                                $isFullyPaid = true;
                                                foreach ($codes as $codeForPlan) {
                                                    $instRow = $info['installments'][$codeForPlan] ?? null;
                                                    if (!$instRow) {
                                                        $isFullyPaid = false;
                                                        continue;
                                                    }
                                                    $hasInstallmentRows = true;
                                                    $totalPlanAmount += (float)($instRow['due_amount'] ?? 0);
                                                    if (normalize_status($instRow['status'] ?? '') !== 'approved') {
                                                        $isFullyPaid = false;
                                                    }
                                                }
                                                if ($totalPlanAmount <= 0) {
                                                    $totalPlanAmount = (float)($info['enrollment_amount'] ?? 0);
                                                }
                                                $planPaymentStatus = ($hasInstallmentRows && $isFullyPaid) ? 'Paid' : 'Unpaid';
                                            ?>
                                            <tr>
                                                <td class="wrap">
                                                    <strong><?php echo htmlspecialchars($info['student_name']); ?></strong><br>
                                                    <span class="mini">Student ID: <?php echo htmlspecialchars($info['public_student_id']); ?></span><br>
                                                    <span class="mini">Enrollment:
                                                        <span class="badge badge-<?php echo badge_class($info['enrollment_status'] ?? 'Pending'); ?>">
                                                            <?php echo htmlspecialchars($info['enrollment_status'] ?? 'Pending'); ?>
                                                        </span>
                                                    </span>
                                                </td>

                                                <td class="wrap"><?php echo htmlspecialchars($info['parent_name'] ?: '-'); ?></td>
                                                <td class="wrap"><?php echo htmlspecialchars($info['parent_email'] ?: '-'); ?></td>
                                                <td class="wrap"><?php echo htmlspecialchars($info['parent_phone'] ?: '-'); ?></td>
                                                <td class="wrap"><?php echo htmlspecialchars($info['parent_address'] ?: '-'); ?></td>
                                                <td class="nowrap"><strong>$<?php echo number_format((float)$totalPlanAmount, 2); ?></strong></td>
                                                <td class="nowrap">
                                                    <span class="badge badge-<?php echo $planPaymentStatus === 'Paid' ? 'success' : 'warning'; ?>">
                                                        <?php echo $planPaymentStatus; ?>
                                                    </span>
                                                </td>

                                                <!-- ✅ Reference column -->
                                                <td class="wrap">
                                                    <?php
                                                        // For each plan, display the reference of the FIRST installment row (best), else fallback to enrollment_reference.
                                                        $firstCode = first_installment_code($planName);
                                                        $ref = '';
                                                        if (isset($info['installments'][$firstCode]['payment_reference'])) {
                                                            $ref = (string)$info['installments'][$firstCode]['payment_reference'];
                                                        }
                                                        if ($ref === '') {
                                                            $ref = (string)($info['enrollment_reference'] ?? '');
                                                        }
                                                    ?>
                                                    <div class="mini"><strong><?php echo $ref ? htmlspecialchars($ref) : '-'; ?></strong></div>
                                                    <div class="mini text-muted">Use this to match bank transfer</div>
                                                </td>

                                                <?php foreach ($codes as $code): ?>
                                                    <?php
                                                        $r = $info['installments'][$code] ?? null;
                                                        $hasRow = is_array($r);
                                                        $status = $r['status'] ?? 'Not Created';
                                                        $proof = trim((string)($r['proof_path'] ?? ''));
                                                        $feeId = (int)($r['id'] ?? 0);
                                                        $amount = $hasRow ? (float)($r['due_amount'] ?? 0) : null;

                                                        $isApproved = (normalize_status($status) === 'approved');
                                                    ?>
                                                    <td>
                                                        <div class="mini mb-1">
                                                            <strong>Amount:</strong>
                                                            <?php echo $amount === null ? '-' : ('$' . number_format($amount, 2)); ?>
                                                        </div>
                                                        <div class="mb-1">
                                                            <span class="badge badge-<?php echo badge_class($status); ?>">
                                                                <?php echo htmlspecialchars($status); ?>
                                                            </span>
                                                        </div>

                                                        <?php if ($proof !== ''): ?>
                                                            <?php $type = proof_type($proof); ?>
                                                            <div class="mb-2">
                                                                <a href="javascript:void(0)"
                                                                   class="proof-thumb"
                                                                   data-proof="<?php echo htmlspecialchars($proof); ?>"
                                                                   data-type="<?php echo htmlspecialchars($type); ?>"
                                                                   data-name="<?php echo htmlspecialchars(basename($proof)); ?>">
                                                                    <?php if ($type === 'img'): ?>
                                                                        <img class="thumb-img" src="<?php echo htmlspecialchars($proof); ?>" alt="proof">
                                                                    <?php else: ?>
                                                                        <span class="thumb-icon"><i class="fas fa-file-pdf"></i></span>
                                                                    <?php endif; ?>
                                                                    <span class="mini"><i class="fas fa-eye"></i> View</span>
                                                                </a>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="mini text-muted mb-2">No proof</div>
                                                        <?php endif; ?>

                                                        <?php if ($feeId > 0): ?>
                                                            <?php if ($isApproved): ?>
                                                                <!-- ✅ Replace Approve/Reject with Update -->
                                                                <button type="button"
                                                                        class="btn btn-sm btn-outline-primary update-btn"
                                                                        data-fee-id="<?php echo (int)$feeId; ?>"
                                                                        data-current-status="<?php echo htmlspecialchars($status); ?>">
                                                                    <i class="fas fa-edit"></i> Update
                                                                </button>
                                                            <?php else: ?>
                                                                <div class="btn-group btn-group-sm" role="group">
                                                                    <a class="btn btn-success"
                                                                       href="feesManagement?fee_action=approve&fee_id=<?php echo (int)$feeId; ?>"
                                                                       onclick="return confirm('Approve this installment?');">
                                                                        Approve
                                                                    </a>
                                                                    <a class="btn btn-warning"
                                                                       href="feesManagement?fee_action=reject&fee_id=<?php echo (int)$feeId; ?>"
                                                                       onclick="return confirm('Reject this installment?');">
                                                                        Reject
                                                                    </a>
                                                                </div>

                                                                <!-- Also allow update even if not approved (optional).
                                                                     If you want update only when approved, delete this: -->
                                                                <div class="mt-2">
                                                                    <button type="button"
                                                                            class="btn btn-sm btn-outline-primary update-btn"
                                                                            data-fee-id="<?php echo (int)$feeId; ?>"
                                                                            data-current-status="<?php echo htmlspecialchars($status); ?>">
                                                                        <i class="fas fa-edit"></i> Update
                                                                    </button>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <div class="mini text-muted">Missing fee row</div>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<!-- Hidden Update Form (submitted via JS) -->
<form id="updateFeeForm" method="POST" style="display:none;">
    <input type="hidden" name="update_fee_id" id="update_fee_id" value="">
    <input type="hidden" name="new_status" id="new_status" value="">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // ---------- Payment method tabs ----------
    const methodPills = document.querySelectorAll('.js-method-pill');
    const planSections = document.querySelectorAll('.fee-plan-section');

    function slugToActiveClass(slug) {
        if (slug === 'term-wise') return 'is-active-term-wise';
        if (slug === 'half-yearly') return 'is-active-half-yearly';
        if (slug === 'yearly') return 'is-active-yearly';
        return 'is-active-all';
    }

    function applyMethodFilter(planSlug) {
        const slug = (planSlug || 'all').toLowerCase();
        planSections.forEach(section => {
            const current = (section.getAttribute('data-plan') || '').toLowerCase();
            section.style.display = (slug === 'all' || current === slug) ? '' : 'none';
        });

        methodPills.forEach(btn => {
            btn.classList.remove('is-active-all', 'is-active-term-wise', 'is-active-half-yearly', 'is-active-yearly');
            const thisSlug = (btn.getAttribute('data-plan') || 'all').toLowerCase();
            if (thisSlug === slug) {
                btn.classList.add(slugToActiveClass(thisSlug));
            }
        });
    }

    methodPills.forEach(btn => {
        btn.addEventListener('click', function() {
            applyMethodFilter((this.getAttribute('data-plan') || 'all'));
        });
    });
    applyMethodFilter('all');

    // ---------- Proof Modal ----------
    function openProofModal(path, type, filename) {
        filename = filename || 'proof';

        if (type === 'img') {
            let scale = 1;

            Swal.fire({
                title: 'Payment Proof',
                html: `
                    <div class="proof-stage">
                        <img id="swalProofImg" class="proof-img" src="${path}" alt="Proof" />
                    </div>
                    <div class="swal-toolbar">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="zoomInBtn">
                            <i class="fas fa-search-plus"></i> Zoom In
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="zoomOutBtn">
                            <i class="fas fa-search-minus"></i> Zoom Out
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="resetZoomBtn">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <a class="btn btn-sm btn-success" href="${path}" download="${filename}">
                            <i class="fas fa-download"></i> Download
                        </a>
                        <a class="btn btn-sm btn-primary" href="${path}" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Open
                        </a>
                    </div>
                `,
                showCloseButton: true,
                showConfirmButton: false,
                didOpen: () => {
                    const img = document.getElementById('swalProofImg');
                    if (!img) return;

                    img.onload = () => {
                        img.style.width = img.naturalWidth + 'px';
                        img.style.height = 'auto';
                        applyScale();
                    };

                    function applyScale() {
                        img.style.transform = `scale(${scale})`;
                    }

                    document.getElementById('zoomInBtn')?.addEventListener('click', () => {
                        scale = Math.min(5, +(scale + 0.25).toFixed(2));
                        applyScale();
                    });

                    document.getElementById('zoomOutBtn')?.addEventListener('click', () => {
                        scale = Math.max(0.25, +(scale - 0.25).toFixed(2));
                        applyScale();
                    });

                    document.getElementById('resetZoomBtn')?.addEventListener('click', () => {
                        scale = 1;
                        applyScale();
                    });
                }
            });
        } else {
            Swal.fire({
                title: 'Payment Proof (PDF)',
                html: `
                    <iframe class="proof-frame" src="${path}#toolbar=1&navpanes=0&scrollbar=1"></iframe>
                    <div class="swal-toolbar">
                        <a class="btn btn-sm btn-success" href="${path}" download="${filename}">
                            <i class="fas fa-download"></i> Download
                        </a>
                        <a class="btn btn-sm btn-primary" href="${path}" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Open PDF
                        </a>
                    </div>
                `,
                showCloseButton: true,
                showConfirmButton: false
            });
        }
    }

    document.querySelectorAll('.proof-thumb').forEach(el => {
        el.addEventListener('click', function () {
            const path = this.getAttribute('data-proof');
            const type = this.getAttribute('data-type');
            const name = this.getAttribute('data-name') || 'proof';
            if (!path) return;
            openProofModal(path, type, name);
        });
    });

    // ---------- Update button ----------
    document.querySelectorAll('.update-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const feeId = this.getAttribute('data-fee-id');
            const current = this.getAttribute('data-current-status') || 'Pending';

            Swal.fire({
                title: 'Update Installment',
                html: `
                    <div class="text-left">
                        <div class="mini mb-2">Fee ID: <strong>${feeId}</strong></div>
                        <label class="mini mb-1">Status</label>
                        <select id="statusSelect" class="form-control">
                            <option value="Pending">Pending</option>
                            <option value="Approved">Approved</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                        <div class="mini text-muted mt-2">
                            If set to Pending, verified_by and verified_at will be cleared.
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Save',
                cancelButtonText: 'Cancel',
                didOpen: () => {
                    const sel = document.getElementById('statusSelect');
                    if (sel) sel.value = (current.charAt(0).toUpperCase() + current.slice(1).toLowerCase());
                },
                preConfirm: () => {
                    const sel = document.getElementById('statusSelect');
                    return sel ? sel.value : 'Pending';
                }
            }).then((res) => {
                if (!res.isConfirmed) return;

                document.getElementById('update_fee_id').value = feeId;
                document.getElementById('new_status').value = res.value;
                document.getElementById('updateFeeForm').submit();
            });
        });
    });

});
</script>

</body>
</html>
