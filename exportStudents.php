<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role()) {
    http_response_code(403);
    exit('Access denied.');
}

$pdo = pcm_pdo();

function bbcc_export_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");
    $stmt->execute([':column' => $column]);
    $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    return $cache[$key];
}

function bbcc_export_csv_value($value): string
{
    $value = trim((string)($value ?? ''));
    if ($value !== '' && preg_match('/^[=+\-@]/', $value) === 1) {
        return "'" . $value;
    }
    return $value;
}

$studentParentExpr = pcm_students_parent_expr($pdo, 's');
$classCampusSelect = bbcc_export_has_column($pdo, 'classes', 'campus_key')
    ? "c.campus_key"
    : "''";
$enrolCampusSelect = bbcc_export_has_column($pdo, 'pcm_enrolments', 'campus_preference')
    ? "e.campus_preference"
    : "''";
$startTermSelect = bbcc_export_has_column($pdo, 'pcm_enrolments', 'start_term')
    ? "e.start_term"
    : "''";

$sql = "
    SELECT
        s.id AS student_db_id,
        s.student_id,
        s.student_name,
        s.dob,
        s.gender AS student_gender,
        s.medical_issue,
        s.registration_date,
        s.approval_status,
        s.status AS student_status,
        s.class_option,
        s.payment_plan,
        s.payment_amount,
        s.payment_reference,
        s.created_at AS student_created_at,
        c.class_name,
        {$classCampusSelect} AS class_campus,
        p.full_name AS parent_name,
        p.gender AS parent_gender,
        p.email AS parent_email,
        p.phone AS parent_phone,
        p.address AS parent_address,
        p.status AS parent_status,
        e.status AS enrolment_status,
        e.fee_plan,
        e.fee_amount,
        e.payment_ref AS enrolment_payment_ref,
        {$enrolCampusSelect} AS campus_preference,
        {$startTermSelect} AS start_term,
        e.submitted_at AS enrolment_submitted_at
    FROM students s
    LEFT JOIN pcm_enrolments e ON e.student_id = s.id
    LEFT JOIN (
        SELECT ca1.student_id, ca1.class_id
        FROM class_assignments ca1
        INNER JOIN (
            SELECT ca2.student_id, MAX(ca2.id) AS assignment_id
            FROM class_assignments ca2
            INNER JOIN classes c2 ON c2.id = ca2.class_id AND c2.active = 1
            GROUP BY ca2.student_id
        ) latest_ca ON latest_ca.assignment_id = ca1.id
    ) ca ON ca.student_id = s.id
    LEFT JOIN classes c ON c.id = ca.class_id
    LEFT JOIN parents p ON p.id = COALESCE(NULLIF(e.parent_id, 0), {$studentParentExpr})
    ORDER BY c.class_name ASC, s.student_name ASC, s.student_id ASC
";

$stmt = $pdo->query($sql);

$filename = 'students_full_details_' . date('Ymd') . '.csv';

if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');
if ($out === false) {
    http_response_code(500);
    exit('Unable to create CSV export.');
}

// UTF-8 BOM helps Excel detect non-ASCII names correctly.
fwrite($out, "\xEF\xBB\xBF");

$headers = [
    'Student DB ID',
    'Student ID',
    'Student Name',
    'Date of Birth',
    'Gender',
    'Medical Issue',
    'Registration Date',
    'Approval Status',
    'Student Status',
    'Class',
    'Class Campus',
    'Class Option',
    'Parent Name',
    'Parent Gender',
    'Parent Email',
    'Parent Phone',
    'Parent Address',
    'Parent Status',
    'Enrolment Status',
    'Fee Plan',
    'Fee Amount',
    'Payment Plan',
    'Payment Amount',
    'Payment Reference',
    'Enrolment Payment Reference',
    'Campus Preference',
    'Start Term',
    'Enrolment Submitted At',
    'Student Created At',
];
fputcsv($out, $headers, ',', '"', '');

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $csvRow = array_map('bbcc_export_csv_value', [
        $row['student_db_id'] ?? '',
        $row['student_id'] ?? '',
        $row['student_name'] ?? '',
        $row['dob'] ?? '',
        $row['student_gender'] ?? '',
        $row['medical_issue'] ?? '',
        $row['registration_date'] ?? '',
        $row['approval_status'] ?? '',
        $row['student_status'] ?? '',
        $row['class_name'] ?? 'Not assigned',
        $row['class_campus'] ?? '',
        $row['class_option'] ?? '',
        $row['parent_name'] ?? '',
        $row['parent_gender'] ?? '',
        $row['parent_email'] ?? '',
        $row['parent_phone'] ?? '',
        $row['parent_address'] ?? '',
        $row['parent_status'] ?? '',
        $row['enrolment_status'] ?? '',
        $row['fee_plan'] ?? '',
        $row['fee_amount'] ?? '',
        $row['payment_plan'] ?? '',
        $row['payment_amount'] ?? '',
        $row['payment_reference'] ?? '',
        $row['enrolment_payment_ref'] ?? '',
        $row['campus_preference'] ?? '',
        $row['start_term'] ?? '',
        $row['enrolment_submitted_at'] ?? '',
        $row['student_created_at'] ?? '',
    ]);
    fputcsv($out, $csvRow, ',', '"', '');
}

fclose($out);
exit;
