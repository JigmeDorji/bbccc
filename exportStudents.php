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
$hasClassCampusColumn = bbcc_export_has_column($pdo, 'classes', 'campus_key');
$hasEnrolmentClassColumn = bbcc_export_has_column($pdo, 'pcm_enrolments', 'class_id');
$classCampusSelect = $hasClassCampusColumn
    ? ($hasEnrolmentClassColumn ? "COALESCE(c.campus_key, ec.campus_key)" : "c.campus_key")
    : "''";
$enrolCampusSelect = bbcc_export_has_column($pdo, 'pcm_enrolments', 'campus_preference')
    ? "e.campus_preference"
    : "''";
$startTermSelect = bbcc_export_has_column($pdo, 'pcm_enrolments', 'start_term')
    ? "e.start_term"
    : "''";
$latestClassJoin = pcm_latest_class_assignment_join('s.id', 'ca', 'c');
$enrolmentClassJoin = $hasEnrolmentClassColumn
    ? "LEFT JOIN classes ec ON ec.id = e.class_id AND ec.active = 1"
    : "";
$classNameSelect = $hasEnrolmentClassColumn
    ? "COALESCE(c.class_name, ec.class_name)"
    : "c.class_name";

$sql = "
    SELECT
        s.id AS student_db_id,
        s.student_id,
        s.student_name,
        s.dob,
        s.gender AS student_gender,
        s.medical_issue,
        s.registration_date,
        COALESCE(NULLIF(CAST(s.registration_date AS CHAR), '0000-00-00'), DATE(e.submitted_at), DATE(s.created_at)) AS enrollment_start_date,
        s.approval_status,
        s.status AS student_status,
        s.class_option,
        s.payment_plan,
        s.payment_amount,
        s.payment_reference,
        s.created_at AS student_created_at,
        {$classNameSelect} AS class_name,
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
    LEFT JOIN (
        SELECT e1.*
        FROM pcm_enrolments e1
        INNER JOIN (
            SELECT student_id, MAX(id) AS enrolment_id
            FROM pcm_enrolments
            GROUP BY student_id
        ) latest_e ON latest_e.enrolment_id = e1.id
    ) e ON e.student_id = s.id
    {$latestClassJoin}
    {$enrolmentClassJoin}
    LEFT JOIN parents p ON p.id = COALESCE(NULLIF(e.parent_id, 0), {$studentParentExpr})
    ORDER BY class_name ASC, s.student_name ASC, s.student_id ASC
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
    'Enrollment Start Date',
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
        $row['enrollment_start_date'] ?? '',
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
