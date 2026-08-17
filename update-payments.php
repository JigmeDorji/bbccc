<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/pcm_helpers.php";
require_once "include/csrf.php";
require_once "include/fee_audit.php";
require_once "include/notifications.php";
require_once "include/mailer.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role === 'parent') {
    header("Location: index-admin");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$message = "";
$success = false;
$reload  = false;

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
// h() comes from include/pcm_helpers.php
// pcm_ensure_class_charge_schema() / pcm_apply_class_charge() come from include/pcm_helpers.php
// pcm_badge() comes from include/pcm_helpers.php (status -> Bootstrap badge class)

function proof_type(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return ($ext === 'pdf') ? 'pdf' : 'img';
}

function pretty_date(?string $d): string {
    return $d ? htmlspecialchars($d) : '-';
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

function first_installment_code(string $planType, int $startTerm = 1): string {
    $startTerm = max(1, min(4, $startTerm));
    return match ($planType) {
        'Term-wise' => 'TERM' . $startTerm,
        'Half-yearly' => $startTerm <= 2 ? 'HALF1' : 'HALF2',
        'Yearly' => 'YEARLY',
        default => 'TERM1'
    };
}

function installment_applies_to_start_term(string $planType, string $code, int $startTerm): bool {
    $startTerm = max(1, min(4, $startTerm));
    $allowed = match ($planType) {
        'Term-wise' => array_slice(['TERM1','TERM2','TERM3','TERM4'], $startTerm - 1),
        'Half-yearly' => $startTerm <= 2 ? ['HALF1','HALF2'] : ['HALF2'],
        'Yearly' => ['YEARLY'],
        default => [],
    };
    return in_array($code, $allowed, true);
}

/**
 * Due date rules: TERM1 = HALF1 = YEARLY -> due_term1; TERM2 -> due_term2;
 * TERM3 = HALF2 -> due_term3; TERM4 -> due_term4.
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

pcm_ensure_class_charge_schema($pdo);
$hasStartTerm = $pdo->query("SHOW COLUMNS FROM pcm_enrolments LIKE 'start_term'")->fetch(PDO::FETCH_ASSOC);
if (!$hasStartTerm) {
    $pdo->exec("ALTER TABLE pcm_enrolments ADD COLUMN start_term TINYINT NOT NULL DEFAULT 1");
}

// ---------------- CLASS-BASED ADDITIONAL CHARGES ----------------
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
            $applied = pcm_apply_class_charge($pdo, $newChargeId);
            $pdo->commit();

            $message = "New class charge created and applied to {$applied} student(s).";
            $success = true;
            $reload = true;
        } elseif ($act === 'apply') {
            $chargeId = (int)($_POST['charge_id'] ?? 0);
            if ($chargeId <= 0) throw new Exception("Invalid charge.");
            $applied = pcm_apply_class_charge($pdo, $chargeId);
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

// ---------------- QUICK STATUS CHANGE ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fee_id'])) {
    try {
        $feeId = (int)($_POST['update_fee_id'] ?? 0);
        $newStatus = trim((string)($_POST['new_status'] ?? ''));
        $reviewer = (string)($_SESSION['username'] ?? 'admin');

        $result = pcm_admin_update_fee_status($pdo, $feeId, $newStatus, $reviewer);
        $message = $result['flash'];
        $success = true;
        $reload  = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload  = false;
    }
}

// ---------------- CHANGE A STUDENT'S FEE PLAN ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_plan') {
    try {
        $studentDbId = (int)($_POST['student_id'] ?? 0);
        if ($studentDbId <= 0) throw new Exception("Invalid student.");
        $reviewer = (string)($_SESSION['username'] ?? 'admin');

        $result = pcm_admin_update_child_details($pdo, $studentDbId, ['fee_plan' => (string)($_POST['fee_plan'] ?? '')], $reviewer);
        $message = $result['flash'];
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- ADD A ONE-OFF ADDITIONAL CHARGE FOR ONE STUDENT ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_individual_charge') {
    try {
        if (!is_admin_role()) throw new Exception("Only admin can add charges.");
        pcm_ensure_class_charge_schema($pdo);

        $studentDbId = (int)($_POST['student_id'] ?? 0);
        $title = trim((string)($_POST['charge_title'] ?? ''));
        $amount = (float)($_POST['charge_amount'] ?? 0);
        $dueDate = trim((string)($_POST['charge_due_date'] ?? ''));

        if ($studentDbId <= 0) throw new Exception("Invalid student.");
        if ($title === '') throw new Exception("Charge name is required.");
        if ($amount <= 0) throw new Exception("Amount must be greater than zero.");

        $enrolStmt = $pdo->prepare("SELECT id, parent_id FROM pcm_enrolments WHERE student_id = :sid LIMIT 1");
        $enrolStmt->execute([':sid' => $studentDbId]);
        $enrol = $enrolStmt->fetch(PDO::FETCH_ASSOC);
        if (!$enrol) throw new Exception("This student has no enrolment record yet.");

        $ins = $pdo->prepare("
            INSERT INTO pcm_fee_payments
                (enrolment_id, class_charge_id, student_id, parent_id, plan_type, instalment_label, due_amount, paid_amount, due_date, status)
            VALUES
                (:eid, NULL, :sid, :pid, 'Additional', :label, :due, 0, :due_date, 'Unpaid')
        ");
        $ins->execute([
            ':eid'  => (int)$enrol['id'],
            ':sid'  => $studentDbId,
            ':pid'  => (int)$enrol['parent_id'],
            ':label' => $title,
            ':due'  => $amount,
            ':due_date' => $dueDate !== '' ? $dueDate : null,
        ]);

        $message = "Additional charge added.";
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- DELETE A FEE RECORD (term instalment or additional charge) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_individual_charge') {
    try {
        if (!is_admin_role()) throw new Exception("Only admin can delete fee records.");

        $pid = (int)($_POST['payment_id'] ?? 0);
        if ($pid <= 0) throw new Exception("Invalid record.");

        $rowStmt = $pdo->prepare("SELECT id, plan_type, status FROM pcm_fee_payments WHERE id = :id LIMIT 1");
        $rowStmt->execute([':id' => $pid]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Fee record not found.");

        $pdo->prepare("DELETE FROM pcm_fee_payments WHERE id = :id")->execute([':id' => $pid]);

        $message = "Fee record deleted.";
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- EDIT A FEE ROW (due/paid/ref/status) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_payment_row') {
    try {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $reviewer = (string)($_SESSION['username'] ?? 'admin');

        $result = pcm_admin_update_fee_row($pdo, $pid, $_POST, $reviewer);
        $message = $result['flash'];
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- BULK STATUS UPDATE ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_update_payments') {
    try {
        $ids = $_POST['payment_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        $st = trim((string)($_POST['bulk_status'] ?? ''));
        $setPaidToDue = isset($_POST['set_paid_to_due']) && (string)$_POST['set_paid_to_due'] === '1';
        $allowed = ['Unpaid','Pending','Verified','Rejected'];

        if (empty($ids)) throw new Exception("Please select at least one payment row.");
        if (!in_array($st, $allowed, true)) throw new Exception("Please choose a valid status.");

        $reviewer = (string)($_SESSION['username'] ?? 'admin');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $beforeRows = [];
        foreach ($ids as $id) {
            $beforeRows[$id] = bbcc_fee_payment_snapshot($pdo, $id);
        }

        if (in_array($st, ['Verified', 'Rejected'], true)) {
            $sql = "
                UPDATE pcm_fee_payments
                SET status = ?,
                    paid_amount = CASE WHEN ? = 1 THEN due_amount ELSE paid_amount END,
                    verified_by = ?,
                    verified_at = NOW()
                WHERE id IN ({$in})
            ";
            $params = array_merge([$st, $setPaidToDue ? 1 : 0, $reviewer], $ids);
        } else {
            $sql = "
                UPDATE pcm_fee_payments
                SET status = ?,
                    paid_amount = CASE WHEN ? = 1 THEN due_amount ELSE paid_amount END,
                    verified_by = NULL,
                    verified_at = NULL
                WHERE id IN ({$in})
            ";
            $params = array_merge([$st, $setPaidToDue ? 1 : 0], $ids);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($ids as $id) {
            bbcc_audit_fee_payment_change(
                $pdo,
                $id,
                'bulk_status_update',
                $beforeRows[$id] ?? null,
                bbcc_fee_payment_snapshot($pdo, $id)
            );
        }

        $message = count($ids) . " payment row(s) updated successfully.";
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- MARK PAID (record a manual/cash payment) — admin-tier only ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_mark_paid') {
    try {
        if (!is_admin_role()) {
            throw new Exception('You do not have permission to record manual payments.');
        }

        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $paidAmount = (float)($_POST['paid_amount'] ?? 0);
        $paymentRef = trim((string)($_POST['payment_ref'] ?? ''));

        if ($paymentId <= 0) {
            throw new Exception('Invalid payment row.');
        }
        if ($paidAmount < 0) {
            throw new Exception('Paid amount cannot be negative.');
        }

        $rowStmt = $pdo->prepare("SELECT due_amount FROM pcm_fee_payments WHERE id = :id LIMIT 1");
        $rowStmt->execute([':id' => $paymentId]);
        $row = $rowStmt->fetch();
        if (!$row) {
            throw new Exception('Payment row not found.');
        }

        $dueAmount = (float)($row['due_amount'] ?? 0);
        if ($paidAmount <= 0) {
            $paidAmount = $dueAmount;
        }

        $reviewer = (string)($_SESSION['username'] ?? 'admin');
        $before = bbcc_fee_payment_snapshot($pdo, $paymentId);
        $upd = $pdo->prepare("
            UPDATE pcm_fee_payments
            SET paid_amount = :paid,
                payment_ref = :ref,
                status = 'Verified',
                submitted_at = COALESCE(submitted_at, NOW()),
                verified_by = :by,
                verified_at = NOW(),
                reject_reason = NULL
            WHERE id = :id
        ");
        $upd->execute([
            ':paid' => $paidAmount,
            ':ref' => ($paymentRef !== '' ? $paymentRef : null),
            ':by' => $reviewer,
            ':id' => $paymentId,
        ]);
        $after = bbcc_fee_payment_snapshot($pdo, $paymentId);
        bbcc_audit_fee_payment_change($pdo, $paymentId, 'manual_mark_paid', $before, $after);

        $message = 'Manual payment saved successfully.';
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- VERIFY / REJECT a pending proof — admin-only ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['verify', 'reject'], true)) {
    try {
        if (!is_admin_role()) {
            throw new Exception('You do not have permission to verify or reject payments.');
        }

        $fid    = (int)($_POST['fee_id'] ?? 0);
        $action = (string)$_POST['action'];
        $reason = trim((string)($_POST['reject_reason'] ?? ''));

        $row = $pdo->prepare("
            SELECT f.*, s.student_name, p.full_name AS parent_name, p.email AS parent_email
            FROM pcm_fee_payments f
            JOIN students s ON s.id = f.student_id
            JOIN parents  p ON p.id = f.parent_id
            WHERE f.id = :id LIMIT 1
        ");
        $row->execute([':id' => $fid]);
        $fee = $row->fetch();

        if (!$fee) {
            throw new Exception('Record not found.');
        }
        if ($fee['status'] !== 'Pending') {
            throw new Exception('This payment is not awaiting review.');
        }
        if ($action === 'reject' && $reason === '') {
            throw new Exception('A rejection reason is required.');
        }

        $newStatus = ($action === 'verify') ? 'Verified' : 'Rejected';
        $reviewer  = (string)($_SESSION['username'] ?? 'admin');

        $before = bbcc_fee_payment_snapshot($pdo, $fid);
        $upd = $pdo->prepare("
            UPDATE pcm_fee_payments
            SET status=:st, verified_by=:vb, verified_at=NOW(),
                reject_reason = CASE WHEN :st2='Rejected' THEN :rr ELSE NULL END,
                paid_amount   = CASE WHEN :st3='Verified' THEN due_amount ELSE 0 END
            WHERE id=:id
        ");
        $upd->execute([':st'=>$newStatus, ':vb'=>$reviewer, ':st2'=>$newStatus, ':rr'=>$reason?:null, ':st3'=>$newStatus, ':id'=>$fid]);
        $after = bbcc_fee_payment_snapshot($pdo, $fid);
        bbcc_audit_fee_payment_change($pdo, $fid, strtolower($newStatus), $before, $after, $reason);

        pcm_notify_parent_fee($fee['parent_email'], $fee['parent_name'], $fee['student_name'], $fee['instalment_label'], $newStatus);
        bbcc_notify_username(
            $pdo,
            (string)$fee['parent_email'],
            'Fee Payment ' . $newStatus . ' for ' . (string)$fee['student_name'],
            'Your payment proof for ' . (string)$fee['instalment_label'] . ' is now marked as ' . $newStatus . '.',
            'parent-payments'
        );

        $message = "Payment {$newStatus} — {$fee['student_name']} ({$fee['instalment_label']}).";
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- SEND CUSTOM EMAIL TO PARENT — admin-only ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_custom_email') {
    try {
        if (!is_admin_role()) {
            throw new Exception('You do not have permission to email parents from this page.');
        }

        $fid = (int)($_POST['fee_id'] ?? 0);
        $subjectTpl = trim((string)($_POST['email_subject'] ?? 'Fee Update for {child_name}'));
        $bodyTpl = trim((string)($_POST['email_body'] ?? 'Dear {parent_name},'));

        if ($fid <= 0) throw new Exception('Invalid fee record.');
        if ($subjectTpl === '' || $bodyTpl === '') throw new Exception('Subject and message are required.');

        $row = $pdo->prepare("
            SELECT f.*, s.student_name, p.full_name AS parent_name, p.email AS parent_email
            FROM pcm_fee_payments f
            JOIN students s ON s.id = f.student_id
            JOIN parents p ON p.id = f.parent_id
            WHERE f.id = :id LIMIT 1
        ");
        $row->execute([':id' => $fid]);
        $fee = $row->fetch(PDO::FETCH_ASSOC);
        if (!$fee) throw new Exception('Record not found.');
        $toEmail = trim((string)($fee['parent_email'] ?? ''));
        if ($toEmail === '') throw new Exception('Parent email not available.');

        $vars = [
            '{parent_name}' => (string)($fee['parent_name'] ?? 'Parent'),
            '{child_name}' => (string)($fee['student_name'] ?? 'Student'),
            '{plan_type}' => (string)($fee['plan_type'] ?? ''),
            '{instalment_label}' => (string)($fee['instalment_label'] ?? ''),
            '{due_amount}' => number_format((float)($fee['due_amount'] ?? 0), 2),
            '{paid_amount}' => number_format((float)($fee['paid_amount'] ?? 0), 2),
            '{status}' => (string)($fee['status'] ?? ''),
        ];
        $subject = strtr($subjectTpl, $vars);
        $bodyText = strtr($bodyTpl, $vars);
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;">' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</div>';
        $sent = @send_mail($toEmail, (string)($fee['parent_name'] ?? 'Parent'), $subject, $html, 8);
        if (!$sent) throw new Exception('Email send failed.');

        $message = 'Email sent successfully to ' . $toEmail . '.';
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- LOAD UNIFIED PAYMENTS TABLE (flat, one row per instalment) ----------------
$latestClassJoin = "
    LEFT JOIN (
        SELECT ca1.student_id, ca1.class_id
        FROM class_assignments ca1
        INNER JOIN (
            SELECT ca2.student_id, MAX(ca2.id) AS assignment_id
            FROM class_assignments ca2
            INNER JOIN classes c2 ON c2.id = ca2.class_id AND c2.active = 1
            GROUP BY ca2.student_id
        ) latest_ca ON latest_ca.assignment_id = ca1.id
    ) current_ca ON current_ca.student_id = s.id
    LEFT JOIN classes current_class ON current_class.id = current_ca.class_id AND current_class.active = 1
";
$attendanceTotalJoin = "
    LEFT JOIN (
        SELECT student_id,
               SUM(CASE WHEN LOWER(COALESCE(status, '')) = 'present' THEN 1 ELSE 0 END) AS total_attendance
        FROM attendance
        GROUP BY student_id
    ) attendance_totals ON attendance_totals.student_id = s.id
";

$stmtPayments = $pdo->prepare("
    SELECT
        f.id, f.enrolment_id, f.student_id, f.parent_id, f.plan_type, f.instalment_label,
        f.due_amount, f.paid_amount, f.payment_ref, f.proof_path, f.status, f.reject_reason,
        f.submitted_at, f.verified_by, f.verified_at,
        s.student_name, s.student_id AS stu_code,
        current_class.id AS class_id, current_class.class_name,
        COALESCE(attendance_totals.total_attendance, 0) AS total_attendance,
        COALESCE(e.start_term, 1) AS start_term,
        e.status AS enrolment_status,
        p.full_name AS parent_name, p.email AS parent_email, p.phone AS parent_phone
    FROM pcm_fee_payments f
    JOIN students s ON s.id = f.student_id
    {$latestClassJoin}
    {$attendanceTotalJoin}
    LEFT JOIN pcm_enrolments e ON e.id = f.enrolment_id
    LEFT JOIN parents p ON p.id = f.parent_id
    WHERE f.plan_type IN ('Term-wise','Half-yearly','Yearly','Additional')
    ORDER BY FIELD(f.status,'Pending','Rejected','Unpaid','Verified'), f.submitted_at DESC, f.id DESC
    LIMIT 1000
");
$stmtPayments->execute();
$payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

$updateCounts = ['Pending' => 0, 'Verified' => 0, 'Rejected' => 0, 'Unpaid' => 0];
foreach ($payments as $row) {
    $k = (string)($row['status'] ?? '');
    if (isset($updateCounts[$k])) $updateCounts[$k]++;
}

// ---------------- Group into a per-child matrix: plan -> student -> installment code ----------------
$plans = [
    'Term-wise' => ['TERM1','TERM2','TERM3','TERM4'],
    'Half-yearly' => ['HALF1','HALF2'],
    'Yearly' => ['YEARLY'],
];
$group = ['Term-wise' => [], 'Half-yearly' => [], 'Yearly' => []];
$additionalByStudent = [];

foreach ($payments as $r) {
    $plan = (string)$r['plan_type'];
    $sid = (string)$r['student_id'];
    if ($sid === '') continue;

    if ($plan === 'Additional') {
        $additionalByStudent[$sid][] = $r;
        continue;
    }
    if (!isset($group[$plan])) continue;

    if (!isset($group[$plan][$sid])) {
        $group[$plan][$sid] = [
            'student_db_id' => $sid,
            'public_student_id' => $r['stu_code'] ?? '',
            'student_name' => $r['student_name'] ?? '',
            'class_id' => (int)($r['class_id'] ?? 0),
            'class_name' => $r['class_name'] ?? '',
            'total_attendance' => (int)($r['total_attendance'] ?? 0),
            'start_term' => (int)($r['start_term'] ?? 1),
            'enrollment_status' => $r['enrolment_status'] ?? 'Pending',
            'parent_name' => $r['parent_name'] ?? '',
            'parent_email' => $r['parent_email'] ?? '',
            'parent_phone' => $r['parent_phone'] ?? '',
            'installments' => [],
        ];
    }

    $code = installment_code_from_label((string)($r['instalment_label'] ?? ''));
    if ($code === '') continue;
    $group[$plan][$sid]['installments'][$code] = $r;
}

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

$bankName = $feesSettings['bank_name'] ?? '';
$accName  = $feesSettings['account_name'] ?? '';
$bsb      = $feesSettings['bsb'] ?? '';
$accNo    = $feesSettings['account_number'] ?? '';
$notes    = $feesSettings['bank_notes'] ?? '';

$isAdminTier = is_admin_role();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Manage Payments</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css" rel="stylesheet">

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .mini { font-size:12px; color:#6c757d; }
        .nowrap { white-space:nowrap; }
        td.wrap { white-space: normal !important; max-width: 260px; }

        .summary-box { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:12px; padding:14px; }
        .due-pill { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#1b4fd6; font-size:11px; font-weight:700; margin-right:6px; margin-bottom:6px; }
        .kv strong { display:inline-block; min-width:120px; }

        .proof-thumb { display:inline-flex; align-items:center; gap:8px; cursor:pointer; text-decoration:none; }
        .thumb-img { width:42px; height:42px; object-fit:cover; border-radius:8px; border:1px solid #e3e6f0; background:#fff; }
        .thumb-icon { width:42px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:8px; border:1px solid #e3e6f0; background:#fff; color:#d93025; font-size:18px; }

        .swal2-popup { width: 920px !important; max-width: 96vw !important; }
        .proof-stage { width: 100%; max-height: 72vh; overflow: auto; border: 1px solid #e3e6f0; border-radius: 12px; padding: 10px; background: #fafbff; display: flex; align-items: center; justify-content: center; }
        .proof-img { display:block; transform-origin: top left; border-radius: 10px; border: 1px solid #e3e6f0; background:#fff; max-width: 100%; max-height: calc(72vh - 24px); width: auto; height: auto; }
        .swal-toolbar { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-top:10px; }

        thead th { vertical-align: middle !important; }
        thead .mini { font-size:11px; font-weight:700; }
        .ref-col { max-width: 220px; }

        .payment-summary-grid { display:grid; grid-template-columns:repeat(4, minmax(120px, 1fr)); gap:10px; margin-bottom:16px; }
        .payment-summary-item { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #e3e6f0; border-radius:10px; background:#fff; }
        .payment-summary-icon { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; flex:0 0 34px; }
        .summary-pending .payment-summary-icon { background:#fff4cf; color:#b77900; }
        .summary-verified .payment-summary-icon { background:#def7ec; color:#087f5b; }
        .summary-rejected .payment-summary-icon { background:#fde8e7; color:#c53030; }
        .summary-unpaid .payment-summary-icon { background:#edf0f5; color:#5a5c69; }
        .payment-summary-value { font-size:1.15rem; font-weight:800; line-height:1; color:#2e3440; }
        .payment-summary-label { margin-top:4px; font-size:.69rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#858796; }

        .update-payments-toolbar { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:8px; padding:12px; margin-bottom:14px; }
        .update-payments-toolbar .form-control, .update-payments-toolbar .btn { min-height:38px; }

        .payment-filter-panel { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:10px; padding:14px; margin-bottom:14px; }
        .payment-filter-panel .form-control { min-height:40px; border-color:#d9deea; }
        .payment-filter-panel .input-group .btn { min-height:40px; }
        .payment-result-meta { font-size:.8rem; color:#6c757d; }
        .payment-no-results { display:none; padding:24px; margin-bottom:16px; text-align:center; border:1px dashed #cdd3df; border-radius:10px; color:#6c757d; background:#fbfcfe; }

        .method-tabs-wrap { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:12px; padding:14px 16px; margin-bottom:16px; }
        .method-pill { border-radius:20px !important; font-weight:600; font-size:.82rem; padding:6px 16px; border:2px solid transparent; margin-right:6px; margin-bottom:6px; }
        .method-pill.is-active-all { background:#881b12; color:#fff; border-color:#881b12; }
        .method-pill.is-active-term-wise { background:#4e73df; color:#fff; border-color:#4e73df; }
        .method-pill.is-active-half-yearly { background:#36b9cc; color:#fff; border-color:#36b9cc; }
        .method-pill.is-active-yearly { background:#1cc88a; color:#fff; border-color:#1cc88a; }

        .update-overview-table { min-width: 1220px; }
        .update-overview-table th, .update-overview-table td { vertical-align: top; }
        .update-overview-table .update-payment-cell { min-width: 230px; background:#fff; }
        .update-overview-table .update-payment-cell.status-pending { background:#fff8e1; }
        .update-overview-table .update-payment-cell.status-rejected { background:#fff1f0; }
        .update-overview-table .update-payment-cell.status-verified { background:#eefaf4; }
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
                    <h1 class="h3 text-gray-800 mb-0">Manage Payments</h1>
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
                            }).then(()=> {
                                // The search box on THIS page is whatever the server
                                // rendered (empty, since this came from a plain form
                                // POST, not a GET carrying ?q=) -- the actual restore
                                // of what was typed before the action happens on the
                                // next load, from sessionStorage (see applyPaymentRowFilters).
                                if (ok && reload) window.location.href = 'update-payments.php';
                            });
                        }
                    });
                </script>

                <!-- Bank & Due Dates reference -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-info-circle"></i> Current Bank & Due Dates Summary</h6>
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
                                        <span class="due-pill">TERM1 / HALF1 / YEARLY: <?php echo pretty_date($feesSettings['due_term1'] ?? null); ?></span>
                                        <span class="due-pill">TERM2: <?php echo pretty_date($feesSettings['due_term2'] ?? null); ?></span>
                                        <span class="due-pill">TERM3 / HALF2: <?php echo pretty_date($feesSettings['due_term3'] ?? null); ?></span>
                                        <span class="due-pill">TERM4: <?php echo pretty_date($feesSettings['due_term4'] ?? null); ?></span>
                                    </div>
                                    <div class="mini text-muted">Rule applied: Term1 = Half1 = Yearly, and Term3 = Half2.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($isAdminTier): ?>
                <!-- Class-Based Additional Charges -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-book mr-1"></i>Class-Based Additional Charges</h6>
                        <span class="mini">Example: Textbook charge by class</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-3">
                            <?= csrf_field() ?>
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
                                        <th>#</th><th>Class</th><th>Charge</th><th>Amount</th><th>Due Date</th><th>Applied Students</th><th>Status</th><th>Actions</th>
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
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="class_charge_action" value="apply">
                                                    <input type="hidden" name="charge_id" value="<?php echo (int)$cc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Apply Missing</button>
                                                </form>
                                                <form method="POST" class="d-inline">
                                                    <?= csrf_field() ?>
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
                <?php endif; ?>

                <!-- Unified payments table -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-money-check-alt mr-1"></i>All Payments</h6>
                    </div>
                    <div class="card-body">
                        <div class="payment-summary-grid">
                            <div class="payment-summary-item summary-pending"><span class="payment-summary-icon"><i class="fas fa-clock"></i></span><div><div class="payment-summary-value"><?= (int)$updateCounts['Pending'] ?></div><div class="payment-summary-label">Pending</div></div></div>
                            <div class="payment-summary-item summary-verified"><span class="payment-summary-icon"><i class="fas fa-check"></i></span><div><div class="payment-summary-value"><?= (int)$updateCounts['Verified'] ?></div><div class="payment-summary-label">Verified</div></div></div>
                            <div class="payment-summary-item summary-rejected"><span class="payment-summary-icon"><i class="fas fa-times"></i></span><div><div class="payment-summary-value"><?= (int)$updateCounts['Rejected'] ?></div><div class="payment-summary-label">Rejected</div></div></div>
                            <div class="payment-summary-item summary-unpaid"><span class="payment-summary-icon"><i class="fas fa-wallet"></i></span><div><div class="payment-summary-value"><?= (int)$updateCounts['Unpaid'] ?></div><div class="payment-summary-label">Unpaid</div></div></div>
                        </div>

                        <form method="POST" id="bulkPaymentForm" class="update-payments-toolbar">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="bulk_update_payments">
                            <div class="row align-items-end">
                                <div class="col-lg-3 col-md-6 mb-2">
                                    <label class="mini font-weight-bold text-uppercase mb-1">Bulk status</label>
                                    <select name="bulk_status" class="form-control form-control-sm" required>
                                        <option value="">Choose status</option>
                                        <option value="Verified">Verified</option>
                                        <option value="Pending">Pending</option>
                                        <option value="Rejected">Rejected</option>
                                        <option value="Unpaid">Unpaid</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-2">
                                    <label class="mini font-weight-bold text-uppercase mb-1">Amount helper</label>
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="bulkSetPaidToDue" name="set_paid_to_due" value="1">
                                        <label class="custom-control-label" for="bulkSetPaidToDue">Set paid amount to due</label>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-2">
                                    <label class="mini font-weight-bold text-uppercase mb-1">Selected rows</label>
                                    <div><strong id="selectedPaymentCount">0</strong> selected</div>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-2 text-lg-right">
                                    <button type="button" class="btn btn-sm btn-outline-secondary mr-1" id="selectVisiblePayments">Select visible</button>
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Apply selected</button>
                                </div>
                            </div>
                        </form>

                        <div class="payment-filter-panel">
                            <div class="form-row align-items-end">
                                <div class="col-lg-3 col-md-4">
                                    <label for="updateClassFilter" class="mini font-weight-bold text-uppercase mb-1">Filter by class</label>
                                    <select id="updateClassFilter" class="form-control form-control-sm">
                                        <option value="all">All classes</option>
                                        <?php foreach ($classOptions as $classOption): ?>
                                            <option value="<?= (int)$classOption['id'] ?>"><?= h((string)$classOption['class_name']) ?></option>
                                        <?php endforeach; ?>
                                        <option value="unassigned">Not assigned</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-4 mt-2 mt-md-0">
                                    <label for="updatePaymentSort" class="mini font-weight-bold text-uppercase mb-1">Sort by</label>
                                    <select id="updatePaymentSort" class="form-control form-control-sm">
                                        <option value="student-asc">Student: A to Z</option>
                                        <option value="student-desc">Student: Z to A</option>
                                        <option value="class-asc">Class: A to Z</option>
                                        <option value="attendance-desc">Attendance: High to Low</option>
                                        <option value="attendance-asc">Attendance: Low to High</option>
                                    </select>
                                </div>
                                <div class="col-lg-6 col-md-4 mt-2 mt-md-0">
                                    <label for="updatePaymentSearch" class="mini font-weight-bold text-uppercase mb-1">Search payments</label>
                                    <div class="input-group input-group-sm">
                                        <input type="search" id="updatePaymentSearch" class="form-control" placeholder="Child, student ID, parent, email, phone or reference">
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-primary" id="updatePaymentSearchBtn"><i class="fas fa-search mr-1"></i>Search</button>
                                            <button type="button" class="btn btn-outline-secondary" id="clearUpdatePaymentSearch">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="payment-result-meta mt-2"><i class="fas fa-list mr-1"></i><strong id="visiblePaymentCount">0</strong> children shown</div>
                        </div>

                        <div class="method-tabs-wrap">
                            <div class="mini font-weight-bold text-uppercase mb-2" style="letter-spacing:.4px;">Payment Plan</div>
                            <button type="button" class="btn method-pill is-active-all js-method-pill" data-plan="all">All</button>
                            <button type="button" class="btn btn-outline-primary method-pill js-method-pill" data-plan="term-wise">Term-wise</button>
                            <button type="button" class="btn btn-outline-info method-pill js-method-pill" data-plan="half-yearly">Half-yearly</button>
                            <button type="button" class="btn btn-outline-success method-pill js-method-pill" data-plan="yearly">Yearly</button>
                        </div>

                        <div class="payment-no-results" id="paymentNoResults">
                            <i class="fas fa-search fa-2x mb-2"></i>
                            <div class="font-weight-bold">No matching children found</div>
                            <div class="small">Try another name, student ID, parent, class, or reference.</div>
                        </div>

                        <?php foreach ($plans as $planName => $codes): ?>
                            <div class="card shadow-sm mb-4 fee-plan-section" data-plan="<?= strtolower($planName) ?>">
                                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                    <h6 class="m-0 font-weight-bold text-primary"><?= h($planName) ?> Fees</h6>
                                    <span class="mini">Installments: <?= count($codes) ?></span>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($group[$planName])): ?>
                                        <div class="alert alert-light mb-0">No records found for this plan.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-hover update-overview-table" width="100%">
                                                <thead class="thead-light">
                                                <tr>
                                                    <th></th>
                                                    <th>Student</th>
                                                    <th>Class</th>
                                                    <th class="nowrap">Attendance</th>
                                                    <th>Parent</th>
                                                    <?php foreach ($codes as $c): ?>
                                                        <?php $dueHeader = installment_due_date($feesSettings, $c); ?>
                                                        <th class="nowrap text-center">
                                                            <div><?= h(installment_label($c)) ?></div>
                                                            <div class="mini text-muted">Due: <?= $dueHeader ? h($dueHeader) : '-' ?></div>
                                                        </th>
                                                    <?php endforeach; ?>
                                                    <th class="nowrap">Additional Charges</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ($group[$planName] as $sid => $info): ?>
                                                    <tr class="update-student-row"
                                                        data-class-id="<?= (int)($info['class_id'] ?? 0) ?>"
                                                        data-student="<?= h((string)($info['student_name'] ?? '')) ?>"
                                                        data-class-name="<?= h((string)($info['class_name'] ?? '')) ?>"
                                                        data-attendance="<?= (int)($info['total_attendance'] ?? 0) ?>">
                                                        <td><input type="checkbox" class="row-select-all mr-1" title="Select all instalments for this child"></td>
                                                        <td class="wrap">
                                                            <strong><?= h($info['student_name']) ?></strong><br>
                                                            <span class="mini">Student ID: <?= h($info['public_student_id']) ?></span><br>
                                                            <span class="mini">Enrollment: <span class="badge badge-<?= pcm_badge((string)($info['enrollment_status'] ?? 'Pending')) ?>"><?= h((string)($info['enrollment_status'] ?? 'Pending')) ?></span></span>
                                                            <?php if ((int)($info['start_term'] ?? 1) > 1): ?>
                                                                <br><span class="badge badge-light border mt-1">Started Term <?= (int)$info['start_term'] ?></span>
                                                            <?php endif; ?>
                                                            <br>
                                                            <button type="button" class="btn btn-outline-secondary btn-sm mt-1 js-changeplan-btn" title="Change fee plan"
                                                                    data-student-id="<?= (int)$sid ?>" data-current-plan="<?= h($planName) ?>" data-child="<?= h((string)$info['student_name']) ?>">
                                                                <i class="fas fa-right-left mr-1"></i>Plan: <?= h($planName) ?>
                                                            </button>
                                                        </td>
                                                        <td class="nowrap">
                                                            <?php if (!empty($info['class_name'])): ?>
                                                                <span class="badge badge-info"><?= h($info['class_name']) ?></span>
                                                            <?php else: ?>
                                                                <span class="text-muted">Not assigned</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center nowrap"><strong><?= (int)($info['total_attendance'] ?? 0) ?></strong></td>
                                                        <td class="wrap">
                                                            <?= h($info['parent_name'] ?: '-') ?><br>
                                                            <span class="mini"><?= h($info['parent_email'] ?: '-') ?></span><br>
                                                            <span class="mini"><?= h($info['parent_phone'] ?: '-') ?></span>
                                                        </td>
                                                        <?php foreach ($codes as $code): ?>
                                                            <?php
                                                                $r = $info['installments'][$code] ?? null;
                                                                $hasRow = is_array($r);
                                                                $isApplicable = installment_applies_to_start_term($planName, $code, (int)($info['start_term'] ?? 1));
                                                                $status = $hasRow ? (string)$r['status'] : '';
                                                                $proof = $hasRow ? trim((string)($r['proof_path'] ?? '')) : '';
                                                                $ptype = $proof !== '' ? proof_type($proof) : '';
                                                                $feeId = $hasRow ? (int)$r['id'] : 0;
                                                            ?>
                                                            <td class="update-payment-cell <?= $hasRow ? 'status-' . strtolower($status) : '' ?>">
                                                                <?php if (!$hasRow): ?>
                                                                    <div class="mini text-muted"><?= $isApplicable ? 'Missing fee row' : 'Not applicable' ?></div>
                                                                <?php else: ?>
                                                                    <label class="mb-1 mini d-block">
                                                                        <input type="checkbox" class="payment-row-check mr-1" name="payment_ids[]" value="<?= $feeId ?>" form="bulkPaymentForm">
                                                                        <span class="badge badge-<?= pcm_badge($status) ?>"><?= h($status) ?></span>
                                                                    </label>
                                                                    <div class="mini mb-1">Due: $<?= number_format((float)$r['due_amount'], 2) ?> / Paid: $<?= number_format((float)$r['paid_amount'], 2) ?></div>
                                                                    <?php if (!empty($r['payment_ref'])): ?><div class="mini text-muted mb-1">Ref: <?= h((string)$r['payment_ref']) ?></div><?php endif; ?>
                                                                    <?php if ($proof !== ''): ?>
                                                                        <div class="mb-1"><a href="javascript:void(0)" class="mini proof-thumb" data-proof="<?= h($proof) ?>" data-type="<?= h($ptype) ?>" data-name="<?= h(basename($proof)) ?>"><i class="fas fa-eye"></i> Proof</a></div>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($r['reject_reason'])): ?><div class="mini text-danger mb-1"><?= h((string)$r['reject_reason']) ?></div><?php endif; ?>
                                                                    <div class="btn-group btn-group-sm" role="group">
                                                                        <button type="button" class="btn btn-outline-primary js-edit-btn" title="Edit"
                                                                                data-id="<?= $feeId ?>" data-due="<?= h((string)$r['due_amount']) ?>" data-paid="<?= h((string)$r['paid_amount']) ?>"
                                                                                data-ref="<?= h((string)($r['payment_ref'] ?? '')) ?>" data-status="<?= h($status) ?>"
                                                                                data-label="<?= h(installment_label($code)) ?>" data-child="<?= h((string)$info['student_name']) ?>">
                                                                            <i class="fas fa-edit"></i>
                                                                        </button>
                                                                        <?php if ($isAdminTier && in_array($status, ['Unpaid', 'Rejected'], true)): ?>
                                                                            <button type="button" class="btn btn-outline-success js-markpaid-btn" title="Mark Paid"
                                                                                    data-id="<?= $feeId ?>" data-due="<?= h((string)$r['due_amount']) ?>"
                                                                                    data-child="<?= h((string)$info['student_name']) ?>" data-label="<?= h(installment_label($code)) ?>">
                                                                                <i class="fas fa-hand-holding-usd"></i>
                                                                            </button>
                                                                        <?php endif; ?>
                                                                        <?php if ($isAdminTier && $status === 'Pending'): ?>
                                                                            <button type="button" class="btn btn-outline-success js-verify-btn" title="Verify"
                                                                                    data-id="<?= $feeId ?>" data-child="<?= h((string)$info['student_name']) ?>" data-label="<?= h(installment_label($code)) ?>">
                                                                                <i class="fas fa-check"></i>
                                                                            </button>
                                                                            <button type="button" class="btn btn-outline-danger js-reject-btn" title="Reject"
                                                                                    data-id="<?= $feeId ?>" data-child="<?= h((string)$info['student_name']) ?>" data-label="<?= h(installment_label($code)) ?>">
                                                                                <i class="fas fa-times"></i>
                                                                            </button>
                                                                        <?php endif; ?>
                                                                        <?php if ($isAdminTier): ?>
                                                                            <button type="button" class="btn btn-outline-info js-email-btn" title="Email parent" data-id="<?= $feeId ?>" data-child="<?= h((string)$info['student_name']) ?>">
                                                                                <i class="fas fa-envelope"></i>
                                                                            </button>
                                                                        <?php endif; ?>
                                                                        <?php if ($isAdminTier): ?>
                                                                            <button type="button" class="btn btn-outline-danger js-delete-charge-btn" title="Delete this fee record"
                                                                                    data-id="<?= $feeId ?>" data-child="<?= h((string)$info['student_name']) ?>" data-label="<?= h(installment_label($code)) ?>" data-status="<?= h($status) ?>">
                                                                                <i class="fas fa-trash-alt"></i>
                                                                            </button>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                        <td class="wrap update-payment-cell" style="min-width:220px;">
                                                            <?php if (empty($additionalByStudent[$sid])): ?>
                                                                <span class="mini text-muted">-</span>
                                                            <?php else: foreach ($additionalByStudent[$sid] as $ar): ?>
                                                                <?php
                                                                    $aProof = trim((string)($ar['proof_path'] ?? ''));
                                                                    $aPtype = $aProof !== '' ? proof_type($aProof) : '';
                                                                    $aId = (int)$ar['id'];
                                                                    $aStatus = (string)$ar['status'];
                                                                ?>
                                                                <div class="mb-2 pb-2 border-bottom">
                                                                    <label class="mb-1 mini d-block">
                                                                        <input type="checkbox" class="payment-row-check mr-1" name="payment_ids[]" value="<?= $aId ?>" form="bulkPaymentForm">
                                                                        <strong><?= h((string)$ar['instalment_label']) ?></strong>
                                                                        <span class="badge badge-<?= pcm_badge($aStatus) ?>"><?= h($aStatus) ?></span>
                                                                    </label>
                                                                    <div class="mini mb-1">Due: $<?= number_format((float)$ar['due_amount'], 2) ?> / Paid: $<?= number_format((float)$ar['paid_amount'], 2) ?></div>
                                                                    <?php if ($aProof !== ''): ?>
                                                                        <div class="mb-1"><a href="javascript:void(0)" class="mini proof-thumb" data-proof="<?= h($aProof) ?>" data-type="<?= h($aPtype) ?>" data-name="<?= h(basename($aProof)) ?>"><i class="fas fa-eye"></i> Proof</a></div>
                                                                    <?php endif; ?>
                                                                    <div class="btn-group btn-group-sm" role="group">
                                                                        <button type="button" class="btn btn-outline-primary js-edit-btn" title="Edit"
                                                                                data-id="<?= $aId ?>" data-due="<?= h((string)$ar['due_amount']) ?>" data-paid="<?= h((string)$ar['paid_amount']) ?>"
                                                                                data-ref="<?= h((string)($ar['payment_ref'] ?? '')) ?>" data-status="<?= h($aStatus) ?>"
                                                                                data-label="<?= h((string)$ar['instalment_label']) ?>" data-child="<?= h((string)$info['student_name']) ?>">
                                                                            <i class="fas fa-edit"></i>
                                                                        </button>
                                                                        <?php if ($isAdminTier && in_array($aStatus, ['Unpaid', 'Rejected'], true)): ?>
                                                                            <button type="button" class="btn btn-outline-success js-markpaid-btn" title="Mark Paid"
                                                                                    data-id="<?= $aId ?>" data-due="<?= h((string)$ar['due_amount']) ?>"
                                                                                    data-child="<?= h((string)$info['student_name']) ?>" data-label="<?= h((string)$ar['instalment_label']) ?>">
                                                                                <i class="fas fa-hand-holding-usd"></i>
                                                                            </button>
                                                                        <?php endif; ?>
                                                                        <?php if ($isAdminTier && $aStatus === 'Pending'): ?>
                                                                            <button type="button" class="btn btn-outline-success js-verify-btn" title="Verify"
                                                                                    data-id="<?= $aId ?>" data-child="<?= h((string)$info['student_name']) ?>" data-label="<?= h((string)$ar['instalment_label']) ?>">
                                                                                <i class="fas fa-check"></i>
                                                                            </button>
                                                                            <button type="button" class="btn btn-outline-danger js-reject-btn" title="Reject"
                                                                                    data-id="<?= $aId ?>" data-child="<?= h((string)$info['student_name']) ?>" data-label="<?= h((string)$ar['instalment_label']) ?>">
                                                                                <i class="fas fa-times"></i>
                                                                            </button>
                                                                        <?php endif; ?>
                                                                        <?php if ($isAdminTier): ?>
                                                                            <button type="button" class="btn btn-outline-info js-email-btn" title="Email parent" data-id="<?= $aId ?>" data-child="<?= h((string)$info['student_name']) ?>">
                                                                                <i class="fas fa-envelope"></i>
                                                                            </button>
                                                                        <?php endif; ?>
                                                                        <?php if ($isAdminTier): ?>
                                                                            <button type="button" class="btn btn-outline-danger js-delete-charge-btn" title="Delete charge"
                                                                                    data-id="<?= $aId ?>" data-child="<?= h((string)$info['student_name']) ?>" data-label="<?= h((string)$ar['instalment_label']) ?>" data-status="<?= h($aStatus) ?>">
                                                                                <i class="fas fa-trash-alt"></i>
                                                                            </button>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; endif; ?>
                                                            <?php if ($isAdminTier): ?>
                                                                <button type="button" class="btn btn-outline-primary btn-sm btn-block js-add-charge-btn"
                                                                        data-student-id="<?= (int)$sid ?>" data-child="<?= h((string)$info['student_name']) ?>">
                                                                    <i class="fas fa-plus mr-1"></i>Add Charge
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<!-- Shared modal: Change fee plan -->
<div class="modal fade" id="changePlanModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" data-confirm="Change this student's fee plan? Their fee instalments will be regenerated unless some have already been paid or reviewed.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_plan">
                <input type="hidden" name="student_id" id="changePlanStudentId" value="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Change Fee Plan</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="small mb-2"><strong id="changePlanChild"></strong></div>
                    <div class="form-group mb-0">
                        <label>Fee Plan</label>
                        <select name="fee_plan" id="changePlanSelect" class="form-control">
                            <option value="Term-wise">Term-wise</option>
                            <option value="Half-yearly">Half-yearly</option>
                            <option value="Yearly">Yearly</option>
                        </select>
                        <small class="form-text text-muted">Instalments already paid or under review are kept as-is; only untouched rows are regenerated for the new plan.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Edit fee row -->
<div class="modal fade" id="editPaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_payment_row">
                <input type="hidden" name="payment_id" id="editPaymentId" value="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="small mb-2"><strong id="editPaymentChild"></strong> - <span id="editPaymentLabel"></span></div>
                    <div class="form-group"><label>Due Amount</label><input type="number" min="0" step="0.01" name="due_amount" id="editPaymentDue" class="form-control" required></div>
                    <div class="form-group"><label>Paid Amount</label><input type="number" min="0" step="0.01" name="paid_amount" id="editPaymentPaid" class="form-control" required></div>
                    <div class="form-group"><label>Reference</label><input type="text" name="payment_ref" id="editPaymentRef" class="form-control" placeholder="Payment reference"></div>
                    <div class="form-group mb-0">
                        <label>Status</label>
                        <select name="status" id="editPaymentStatus" class="form-control">
                            <option value="Unpaid">Unpaid</option>
                            <option value="Pending">Pending</option>
                            <option value="Verified">Verified</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Mark Paid -->
<div class="modal fade" id="markPaidModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="manual_mark_paid">
                <input type="hidden" name="payment_id" id="markPaidId" value="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Record Manual Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="small mb-2"><strong id="markPaidChild"></strong> - <span id="markPaidLabel"></span></div>
                    <div class="form-group"><label>Paid Amount</label><input type="number" min="0" step="0.01" name="paid_amount" id="markPaidAmount" class="form-control" required></div>
                    <div class="form-group mb-0"><label>Reference</label><input type="text" name="payment_ref" class="form-control" placeholder="Payment reference"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Verify -->
<div class="modal fade" id="verifyPaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="fee_id" id="verifyPaymentId" value="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Verify Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p>Confirm <strong id="verifyPaymentChild"></strong> — <span id="verifyPaymentLabel"></span> has been paid?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check mr-1"></i>Verify</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Reject -->
<div class="modal fade" id="rejectPaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="fee_id" id="rejectPaymentId" value="">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Reject Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p><strong id="rejectPaymentChild"></strong> — <span id="rejectPaymentLabel"></span></p>
                    <div class="form-group mb-0"><label>Reason</label><textarea name="reject_reason" class="form-control" rows="2" required></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Delete a fee record (term instalment or additional charge) -->
<div class="modal fade" id="deleteChargeModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="deleteChargeForm" data-self-managed-submit>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_individual_charge">
                <input type="hidden" name="payment_id" id="deleteChargeId" value="">
                <input type="hidden" id="deleteChargeIsVerified" value="0">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Delete Fee Record</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p>Delete <strong id="deleteChargeLabel"></strong> for <strong id="deleteChargeChild"></strong>? This cannot be undone. Use this only for records that were created by mistake (e.g. a wrong term).</p>
                    <div id="deleteChargeVerifiedWarning" class="alert alert-danger d-none">
                        <strong>Warning:</strong> this record is marked <strong>Verified</strong> (already recorded as paid). Deleting it permanently removes the payment record and cannot be undone.
                        Type <strong>DELETE</strong> below to confirm.
                        <input type="text" id="deleteChargeConfirmText" class="form-control mt-2" autocomplete="off">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" id="deleteChargeSubmitBtn" class="btn btn-danger"><i class="fas fa-trash-alt mr-1"></i>Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Add individual charge -->
<div class="modal fade" id="addChargeModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_individual_charge">
                <input type="hidden" name="student_id" id="addChargeStudentId" value="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add Charge for <span id="addChargeChild"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group"><label>Charge Name</label><input type="text" name="charge_title" class="form-control" maxlength="120" placeholder="e.g. Excursion Fee" required></div>
                    <div class="form-group"><label>Amount</label><input type="number" step="0.01" min="0.01" name="charge_amount" class="form-control" required></div>
                    <div class="form-group mb-0"><label>Due Date (optional)</label><input type="date" name="charge_due_date" class="form-control"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus mr-1"></i>Add Charge</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Email parent -->
<div class="modal fade" id="emailPaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_custom_email">
                <input type="hidden" name="fee_id" id="emailPaymentId" value="">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Send Parent Email</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="mini mb-2">Variables: {parent_name}, {child_name}, {plan_type}, {instalment_label}, {due_amount}, {paid_amount}, {status}</div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="email_subject" class="form-control" value="Fee Update for {child_name}" required>
                    </div>
                    <div class="form-group mb-0">
                        <label>Message</label>
                        <textarea name="email_body" class="form-control" rows="6" required>Dear {parent_name},

This is an update for {child_name}:
- Plan: {plan_type}
- Instalment: {instalment_label}
- Due: ${due_amount}
- Paid: ${paid_amount}
- Status: {status}

Thank you.</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">Send Email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const classFilter = document.getElementById('updateClassFilter');
    const paymentSort = document.getElementById('updatePaymentSort');
    const paymentSearch = document.getElementById('updatePaymentSearch');
    const paymentSearchBtn = document.getElementById('updatePaymentSearchBtn');
    const clearPaymentSearch = document.getElementById('clearUpdatePaymentSearch');
    const visiblePaymentCount = document.getElementById('visiblePaymentCount');
    const paymentNoResults = document.getElementById('paymentNoResults');

    const deleteChargeForm = document.getElementById('deleteChargeForm');
    if (deleteChargeForm) {
        deleteChargeForm.addEventListener('submit', function (e) {
            const isVerified = document.getElementById('deleteChargeIsVerified').value === '1';
            if (!isVerified) return;
            const typed = (document.getElementById('deleteChargeConfirmText').value || '').trim().toUpperCase();
            if (typed !== 'DELETE') {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Type DELETE to confirm', text: 'This record is Verified (paid) — type DELETE in the box to confirm removing it.' });
            }
        });
    }

    document.querySelectorAll('.update-overview-table tbody').forEach(tbody => {
        Array.from(tbody.querySelectorAll('.update-student-row')).forEach((row, index) => {
            row.dataset.originalIndex = String(index);
        });
    });

    function sortPaymentRows() {
        const sortValue = paymentSort ? paymentSort.value : 'student-asc';
        const separator = sortValue.lastIndexOf('-');
        const field = separator > -1 ? sortValue.slice(0, separator) : 'student';
        const dataField = field === 'class' ? 'className' : field;
        const direction = sortValue.endsWith('-desc') ? -1 : 1;

        document.querySelectorAll('.update-overview-table tbody').forEach(tbody => {
            const rows = Array.from(tbody.querySelectorAll('.update-student-row'));
            rows.sort((a, b) => {
                let comparison = 0;
                if (field === 'attendance') {
                    comparison = (parseFloat(a.dataset[dataField] || '0') - parseFloat(b.dataset[dataField] || '0'));
                } else {
                    const aValue = (a.dataset[dataField] || '').trim();
                    const bValue = (b.dataset[dataField] || '').trim();
                    comparison = aValue.localeCompare(bValue, undefined, { numeric: true, sensitivity: 'base' });
                }
                if (comparison === 0) {
                    comparison = Number(a.dataset.originalIndex || 0) - Number(b.dataset.originalIndex || 0);
                }
                return comparison * direction;
            });
            rows.forEach(row => tbody.appendChild(row));
        });
    }

    function refreshPaymentResultSummary() {
        let visibleRows = 0;
        document.querySelectorAll('.update-student-row').forEach(row => {
            const section = row.closest('.fee-plan-section');
            const rowVisible = row.style.display !== 'none';
            const sectionVisible = !section || section.style.display !== 'none';
            if (rowVisible && sectionVisible) visibleRows++;
        });
        if (visiblePaymentCount) visiblePaymentCount.textContent = String(visibleRows);
        if (paymentNoResults) paymentNoResults.style.display = visibleRows === 0 ? 'block' : 'none';
    }

    function applyPaymentRowFilters() {
        const selectedClass = classFilter ? classFilter.value : 'all';
        const query = paymentSearch ? paymentSearch.value.trim().toLowerCase() : '';
        document.querySelectorAll('.update-student-row').forEach(row => {
            const rowClass = row.getAttribute('data-class-id') || '0';
            const classMatches = selectedClass === 'all'
                || (selectedClass === 'unassigned' && rowClass === '0')
                || rowClass === selectedClass;
            const searchableText = (row.textContent || '').toLowerCase();
            const searchMatches = query === '' || searchableText.includes(query);
            row.style.display = classMatches && searchMatches ? '' : 'none';
        });
        refreshPaymentResultSummary();
        // Persist so the search/filter survives an action (edit, verify,
        // delete, etc.) reloading this page via a real form POST -- the
        // reloaded page has no way to know what was typed before the
        // submit, so sessionStorage is the only thing that carries it
        // across that boundary.
        try {
            sessionStorage.setItem('updatePaymentsSearchQ', paymentSearch ? paymentSearch.value : '');
            sessionStorage.setItem('updatePaymentsSearchClass', selectedClass);
        } catch (e) { /* storage unavailable (private mode, etc.) -- fine to skip */ }
    }

    if (classFilter) classFilter.addEventListener('change', applyPaymentRowFilters);
    if (paymentSort) paymentSort.addEventListener('change', function () { sortPaymentRows(); refreshPaymentResultSummary(); });
    if (paymentSearchBtn) paymentSearchBtn.addEventListener('click', applyPaymentRowFilters);
    if (paymentSearch) {
        paymentSearch.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); applyPaymentRowFilters(); }
        });
    }
    if (clearPaymentSearch) {
        clearPaymentSearch.addEventListener('click', function () {
            if (paymentSearch) paymentSearch.value = '';
            applyPaymentRowFilters();
            if (paymentSearch) paymentSearch.focus();
        });
    }
    sortPaymentRows();

    // Restore search/class filter before the first filter pass runs --
    // ?q=/?class= (deep-link, e.g. from Fees Overview's "click status to
    // update" links) take priority when present, otherwise fall back to
    // what was last typed, remembered via sessionStorage since a real
    // form POST reload (edit, verify, delete, etc.) can't carry it any
    // other way. This MUST run before applyPaymentRowFilters() below --
    // that call itself re-saves whatever is currently in the search box
    // to sessionStorage, which would silently wipe out the saved value
    // if it ran first against the still-empty, freshly-loaded inputs.
    const deepLinkParams = new URLSearchParams(window.location.search);
    let restoreQuery = deepLinkParams.get('q');
    let restoreClass = deepLinkParams.get('class');
    const shouldScrollToMatch = !!restoreQuery;
    if (restoreQuery === null && restoreClass === null) {
        try {
            restoreQuery = sessionStorage.getItem('updatePaymentsSearchQ');
            restoreClass = sessionStorage.getItem('updatePaymentsSearchClass');
        } catch (e) { /* storage unavailable -- fine to skip */ }
    }
    if (restoreClass && restoreClass !== 'all' && classFilter) classFilter.value = restoreClass;
    if (restoreQuery && paymentSearch) paymentSearch.value = restoreQuery;

    applyPaymentRowFilters();

    if (restoreQuery && shouldScrollToMatch) {
        const firstMatch = Array.from(document.querySelectorAll('.update-student-row'))
            .find(row => row.style.display !== 'none');
        if (firstMatch) {
            firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstMatch.classList.add('table-active');
        }
    }

    // ---------- Payment plan tabs ----------
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
            if (thisSlug === slug) btn.classList.add(slugToActiveClass(thisSlug));
        });
        refreshPaymentResultSummary();
    }
    methodPills.forEach(btn => {
        btn.addEventListener('click', function () { applyMethodFilter(this.getAttribute('data-plan') || 'all'); });
    });
    applyMethodFilter('all');

    // ---------- Bulk selection ----------
    function updateSelectedCount() {
        const count = document.querySelectorAll('.payment-row-check:checked').length;
        document.getElementById('selectedPaymentCount').textContent = String(count);
    }
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('payment-row-check')) {
            updateSelectedCount();
        }
        if (e.target.classList.contains('row-select-all')) {
            const row = e.target.closest('tr');
            if (row) {
                row.querySelectorAll('.payment-row-check').forEach(cb => { cb.checked = e.target.checked; });
                updateSelectedCount();
            }
        }
    });
    document.getElementById('selectVisiblePayments').addEventListener('click', function () {
        document.querySelectorAll('.update-student-row').forEach(row => {
            const section = row.closest('.fee-plan-section');
            if (row.style.display === 'none') return;
            if (section && section.style.display === 'none') return;
            row.querySelectorAll('.payment-row-check').forEach(cb => { cb.checked = true; });
        });
        updateSelectedCount();
    });

    const bulkForm = document.getElementById('bulkPaymentForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function (e) {
            if (document.querySelectorAll('.payment-row-check:checked').length === 0) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Please select at least one payment row.', timer: 1800, showConfirmButton: false });
            }
        });
    }

    // Row action buttons + proof viewer: delegated on document (one listener
    // covers every cell, including the repeated Additional-charges rows).
    document.addEventListener('click', function (e) {
        const changePlanBtn = e.target.closest('.js-changeplan-btn');
        if (changePlanBtn) {
            document.getElementById('changePlanStudentId').value = changePlanBtn.dataset.studentId;
            document.getElementById('changePlanSelect').value = changePlanBtn.dataset.currentPlan;
            document.getElementById('changePlanChild').textContent = changePlanBtn.dataset.child;
            jQuery('#changePlanModal').modal('show');
            return;
        }

        const editBtn = e.target.closest('.js-edit-btn');
        if (editBtn) {
            document.getElementById('editPaymentId').value = editBtn.dataset.id;
            document.getElementById('editPaymentDue').value = editBtn.dataset.due;
            document.getElementById('editPaymentPaid').value = editBtn.dataset.paid;
            document.getElementById('editPaymentRef').value = editBtn.dataset.ref;
            document.getElementById('editPaymentStatus').value = editBtn.dataset.status;
            document.getElementById('editPaymentChild').textContent = editBtn.dataset.child;
            document.getElementById('editPaymentLabel').textContent = editBtn.dataset.label;
            jQuery('#editPaymentModal').modal('show');
            return;
        }

        const markPaidBtn = e.target.closest('.js-markpaid-btn');
        if (markPaidBtn) {
            document.getElementById('markPaidId').value = markPaidBtn.dataset.id;
            document.getElementById('markPaidAmount').value = markPaidBtn.dataset.due;
            document.getElementById('markPaidChild').textContent = markPaidBtn.dataset.child;
            document.getElementById('markPaidLabel').textContent = markPaidBtn.dataset.label;
            jQuery('#markPaidModal').modal('show');
            return;
        }

        const verifyBtn = e.target.closest('.js-verify-btn');
        if (verifyBtn) {
            document.getElementById('verifyPaymentId').value = verifyBtn.dataset.id;
            document.getElementById('verifyPaymentChild').textContent = verifyBtn.dataset.child;
            document.getElementById('verifyPaymentLabel').textContent = verifyBtn.dataset.label;
            jQuery('#verifyPaymentModal').modal('show');
            return;
        }

        const rejectBtn = e.target.closest('.js-reject-btn');
        if (rejectBtn) {
            document.getElementById('rejectPaymentId').value = rejectBtn.dataset.id;
            document.getElementById('rejectPaymentChild').textContent = rejectBtn.dataset.child;
            document.getElementById('rejectPaymentLabel').textContent = rejectBtn.dataset.label;
            jQuery('#rejectPaymentModal').modal('show');
            return;
        }

        const emailBtn = e.target.closest('.js-email-btn');
        if (emailBtn) {
            document.getElementById('emailPaymentId').value = emailBtn.dataset.id;
            jQuery('#emailPaymentModal').modal('show');
            return;
        }

        const deleteChargeBtn = e.target.closest('.js-delete-charge-btn');
        if (deleteChargeBtn) {
            document.getElementById('deleteChargeId').value = deleteChargeBtn.dataset.id;
            document.getElementById('deleteChargeChild').textContent = deleteChargeBtn.dataset.child;
            document.getElementById('deleteChargeLabel').textContent = deleteChargeBtn.dataset.label;
            const isVerified = deleteChargeBtn.dataset.status === 'Verified';
            document.getElementById('deleteChargeVerifiedWarning').classList.toggle('d-none', !isVerified);
            document.getElementById('deleteChargeConfirmText').value = '';
            document.getElementById('deleteChargeIsVerified').value = isVerified ? '1' : '0';
            jQuery('#deleteChargeModal').modal('show');
            return;
        }

        const addChargeBtn = e.target.closest('.js-add-charge-btn');
        if (addChargeBtn) {
            document.getElementById('addChargeStudentId').value = addChargeBtn.dataset.studentId;
            document.getElementById('addChargeChild').textContent = addChargeBtn.dataset.child;
            jQuery('#addChargeModal').modal('show');
            return;
        }

        const proofEl = e.target.closest('.proof-thumb');
        if (proofEl) {
            const proof = proofEl.dataset.proof;
            const type = proofEl.dataset.type;
            const name = proofEl.dataset.name;
            let html;
            if (type === 'img') {
                html = '<div class="proof-stage"><img class="proof-img" src="' + proof + '" alt="' + name + '"></div>';
            } else {
                html = '<iframe class="proof-frame" src="' + proof + '"></iframe>';
            }
            Swal.fire({
                title: name,
                html: html,
                showConfirmButton: true,
                confirmButtonText: 'Close',
                width: 920,
            });
        }
    });
});
</script>
</body>
</html>
