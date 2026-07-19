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

try {
    $pdo = pcm_pdo();
} catch (Throwable $e) {
    bbcc_fail_db($e);
}

function spx_xml($value): string
{
    $value = (string)($value ?? '');
    $clean = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value);
    return htmlspecialchars($clean ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function spx_column_name(int $number): string
{
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

function spx_text_cell(string $reference, $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';
    return '<c r="' . $reference . '" t="inlineStr"' . $styleAttribute . '><is><t xml:space="preserve">'
        . spx_xml($value) . '</t></is></c>';
}

function spx_number_cell(string $reference, float $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';
    return '<c r="' . $reference . '"' . $styleAttribute . '><v>' . number_format($value, 2, '.', '') . '</v></c>';
}

function spx_date_cell(string $reference, $value, int $style): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '' || $value === '0000-00-00' || str_starts_with($value, '0000-00-00')) {
        return spx_text_cell($reference, '');
    }

    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $base = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
        $serial = ($date->getTimestamp() - $base->getTimestamp()) / 86400;
        return '<c r="' . $reference . '" s="' . $style . '"><v>'
            . rtrim(rtrim(number_format($serial, 10, '.', ''), '0'), '.') . '</v></c>';
    } catch (Throwable $e) {
        return spx_text_cell($reference, $value);
    }
}

function spx_payment_status(array $row): string
{
    $feeRows = (int)($row['fee_rows'] ?? 0);
    if ($feeRows <= 0) {
        return 'No fee record';
    }

    $due = (float)($row['total_due'] ?? 0);
    $paid = (float)($row['total_paid'] ?? 0);
    $pending = (int)($row['pending_rows'] ?? 0);
    $rejected = (int)($row['rejected_rows'] ?? 0);
    $verified = (int)($row['verified_rows'] ?? 0);

    if ($rejected > 0 && $paid <= 0) return 'Rejected';
    if ($due > 0 && $paid >= $due && $verified > 0) return 'Paid';
    if ($paid > 0 && ($due <= 0 || $paid < $due)) return 'Part-paid';
    if ($pending > 0) return 'Pending';
    if ($paid > 0) return 'Paid';
    return 'Unpaid';
}

$studentParentExpr = pcm_students_parent_expr($pdo, 's');

$sql = "
    SELECT
        c.class_name,
        s.id AS student_db_id,
        s.student_id AS student_code,
        s.student_name,
        s.dob,
        s.gender,
        s.medical_issue,
        s.registration_date,
        s.class_option,
        s.approval_status,
        s.status AS student_status,
        s.payment_plan AS student_payment_plan,
        s.payment_amount AS student_payment_amount,
        s.payment_reference AS student_payment_reference,
        p.full_name AS parent_name,
        p.email AS parent_email,
        p.phone AS parent_phone,
        p.address AS parent_address,
        p.status AS parent_status,
        e.status AS enrolment_status,
        e.payment_ref AS enrolment_payment_ref,
        COALESCE(e.fee_plan, ps.plan_type, s.payment_plan, '') AS fee_plan,
        COALESCE(ps.total_due, 0) AS total_due,
        COALESCE(ps.total_paid, 0) AS total_paid,
        COALESCE(ps.fee_rows, 0) AS fee_rows,
        COALESCE(ps.pending_rows, 0) AS pending_rows,
        COALESCE(ps.verified_rows, 0) AS verified_rows,
        COALESCE(ps.rejected_rows, 0) AS rejected_rows,
        ps.payment_refs,
        ps.instalments,
        ps.last_submitted_at,
        ps.last_verified_at
    FROM students s
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
    LEFT JOIN pcm_enrolments e ON e.student_id = s.id
    LEFT JOIN parents p ON p.id = COALESCE(NULLIF(e.parent_id, 0), {$studentParentExpr})
    LEFT JOIN (
        SELECT
            student_id,
            MAX(plan_type) AS plan_type,
            COUNT(*) AS fee_rows,
            SUM(COALESCE(due_amount, 0)) AS total_due,
            SUM(COALESCE(paid_amount, 0)) AS total_paid,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_rows,
            SUM(CASE WHEN status = 'Verified' THEN 1 ELSE 0 END) AS verified_rows,
            SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_rows,
            GROUP_CONCAT(DISTINCT NULLIF(payment_ref, '') ORDER BY payment_ref SEPARATOR ', ') AS payment_refs,
            GROUP_CONCAT(DISTINCT instalment_label ORDER BY id SEPARATOR ', ') AS instalments,
            MAX(submitted_at) AS last_submitted_at,
            MAX(verified_at) AS last_verified_at
        FROM pcm_fee_payments
        GROUP BY student_id
    ) ps ON ps.student_id = s.id
    ORDER BY c.class_name ASC, s.student_name ASC, s.student_id ASC
";

$stmt = $pdo->query($sql);

$filename = 'students_class_parent_payments_' . date('Ymd') . '.xlsx';
$headers = [
    'Class',
    'Student DB ID',
    'Student ID',
    'Student Name',
    'Date of Birth',
    'Gender',
    'Medical Issue',
    'Registration Date',
    'Class Option',
    'Approval Status',
    'Student Status',
    'Stored Payment Plan',
    'Stored Payment Amount',
    'Stored Payment Reference',
    'Parent Name',
    'Parent Email',
    'Parent Phone',
    'Parent Address',
    'Parent Status',
    'Enrolment Status',
    'Enrolment Payment Reference',
    'Fee Plan',
    'Total Due',
    'Total Paid',
    'Balance',
    'Payment Status',
    'Instalments',
    'Payment References',
    'Last Submitted At',
    'Last Verified At',
];

$sheetRows = [];
$headerCells = [];
foreach ($headers as $index => $header) {
    $headerCells[] = spx_text_cell(spx_column_name($index + 1) . '1', $header, 1);
}
$sheetRows[] = '<row r="1" ht="24" customHeight="1">' . implode('', $headerCells) . '</row>';

$rowNumber = 2;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $due = (float)($row['total_due'] ?? 0);
    $paid = (float)($row['total_paid'] ?? 0);
    $hasFeeRows = (int)($row['fee_rows'] ?? 0) > 0;

    $cells = [
        spx_text_cell('A' . $rowNumber, $row['class_name'] ?: 'Not assigned'),
        spx_text_cell('B' . $rowNumber, $row['student_db_id']),
        spx_text_cell('C' . $rowNumber, $row['student_code']),
        spx_text_cell('D' . $rowNumber, $row['student_name']),
        spx_date_cell('E' . $rowNumber, $row['dob'], 3),
        spx_text_cell('F' . $rowNumber, $row['gender']),
        spx_text_cell('G' . $rowNumber, $row['medical_issue']),
        spx_date_cell('H' . $rowNumber, $row['registration_date'], 3),
        spx_text_cell('I' . $rowNumber, $row['class_option']),
        spx_text_cell('J' . $rowNumber, $row['approval_status']),
        spx_text_cell('K' . $rowNumber, $row['student_status']),
        spx_text_cell('L' . $rowNumber, $row['student_payment_plan']),
        $row['student_payment_amount'] !== null && $row['student_payment_amount'] !== ''
            ? spx_number_cell('M' . $rowNumber, (float)$row['student_payment_amount'], 2)
            : spx_text_cell('M' . $rowNumber, ''),
        spx_text_cell('N' . $rowNumber, $row['student_payment_reference']),
        spx_text_cell('O' . $rowNumber, $row['parent_name']),
        spx_text_cell('P' . $rowNumber, $row['parent_email']),
        spx_text_cell('Q' . $rowNumber, $row['parent_phone']),
        spx_text_cell('R' . $rowNumber, $row['parent_address']),
        spx_text_cell('S' . $rowNumber, $row['parent_status']),
        spx_text_cell('T' . $rowNumber, $row['enrolment_status']),
        spx_text_cell('U' . $rowNumber, $row['enrolment_payment_ref']),
        spx_text_cell('V' . $rowNumber, $row['fee_plan']),
        $hasFeeRows ? spx_number_cell('W' . $rowNumber, $due, 2) : spx_text_cell('W' . $rowNumber, ''),
        $hasFeeRows ? spx_number_cell('X' . $rowNumber, $paid, 2) : spx_text_cell('X' . $rowNumber, ''),
        $hasFeeRows ? spx_number_cell('Y' . $rowNumber, max(0, $due - $paid), 2) : spx_text_cell('Y' . $rowNumber, ''),
        spx_text_cell('Z' . $rowNumber, spx_payment_status($row)),
        spx_text_cell('AA' . $rowNumber, $row['instalments']),
        spx_text_cell('AB' . $rowNumber, $row['payment_refs']),
        spx_date_cell('AC' . $rowNumber, $row['last_submitted_at'], 4),
        spx_date_cell('AD' . $rowNumber, $row['last_verified_at'], 4),
    ];
    $sheetRows[] = '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
    $rowNumber++;
}

$lastRow = max(1, $rowNumber - 1);
$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . '<cols>'
    . '<col min="1" max="1" width="22" customWidth="1"/><col min="2" max="3" width="14" customWidth="1"/>'
    . '<col min="4" max="4" width="26" customWidth="1"/><col min="5" max="6" width="16" customWidth="1"/>'
    . '<col min="7" max="7" width="28" customWidth="1"/><col min="8" max="14" width="18" customWidth="1"/>'
    . '<col min="15" max="15" width="26" customWidth="1"/><col min="16" max="16" width="32" customWidth="1"/>'
    . '<col min="17" max="17" width="18" customWidth="1"/><col min="18" max="18" width="36" customWidth="1"/>'
    . '<col min="19" max="22" width="18" customWidth="1"/><col min="23" max="25" width="14" customWidth="1"/>'
    . '<col min="26" max="26" width="18" customWidth="1"/><col min="27" max="28" width="30" customWidth="1"/>'
    . '<col min="29" max="30" width="20" customWidth="1"/>'
    . '</cols><sheetData>' . implode('', $sheetRows) . '</sheetData>'
    . '<autoFilter ref="A1:AD' . $lastRow . '"/>'
    . '</worksheet>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd"/><numFmt numFmtId="166" formatCode="yyyy-mm-dd hh:mm"/></numFmts>'
    . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
    . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF881B12"/><bgColor indexed="64"/></patternFill></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="5">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';

$tempFile = tempnam(sys_get_temp_dir(), 'bbcc_student_payments_');
if ($tempFile === false) {
    http_response_code(500);
    exit('Unable to create the Excel file.');
}

$zip = new ZipArchive();
if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($tempFile);
    http_response_code(500);
    exit('Unable to create the Excel file.');
}

$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Student Payments" sheetId="1" r:id="rId1"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->addFromString('xl/styles.xml', $stylesXml);
$createdAt = gmdate('Y-m-d\TH:i:s\Z');
$zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Student Class Parent Payments</dc:title><dc:creator>BBCC</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:created></cp:coreProperties>');
$zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>BBCC</Application></Properties>');
$zip->close();

if (ob_get_length()) ob_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($tempFile);
@unlink($tempFile);
exit;
