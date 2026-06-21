<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower((string)($_SESSION['role'] ?? ''));
if ($role === 'parent') {
    http_response_code(403);
    exit('Access denied.');
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    bbcc_fail_db($e);
}

function xlsx_xml($value): string
{
    $value = (string)($value ?? '');
    $clean = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value);
    return htmlspecialchars($clean ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_column_name(int $number): string
{
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

function xlsx_text_cell(string $reference, $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';
    return '<c r="' . $reference . '" t="inlineStr"' . $styleAttribute . '><is><t xml:space="preserve">'
        . xlsx_xml($value) . '</t></is></c>';
}

function xlsx_number_cell(string $reference, float $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';
    return '<c r="' . $reference . '"' . $styleAttribute . '><v>' . number_format($value, 2, '.', '') . '</v></c>';
}

function xlsx_date_cell(string $reference, $value, int $style): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '' || $value === '0000-00-00' || str_starts_with($value, '0000-00-00')) {
        return xlsx_text_cell($reference, '');
    }

    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $base = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
        $serial = ($date->getTimestamp() - $base->getTimestamp()) / 86400;
        return '<c r="' . $reference . '" s="' . $style . '"><v>'
            . rtrim(rtrim(number_format($serial, 10, '.', ''), '0'), '.') . '</v></c>';
    } catch (Throwable $e) {
        return xlsx_text_cell($reference, $value);
    }
}

function report_payment_status(array $row): string
{
    if (empty($row['payment_id'])) {
        return 'No fee record';
    }

    $due = (float)($row['due_amount'] ?? 0);
    $paid = (float)($row['paid_amount'] ?? 0);
    $status = strtolower(trim((string)($row['recorded_status'] ?? '')));

    if ($status === 'rejected') return 'Rejected';
    if ($due > 0 && $paid >= $due && in_array($status, ['verified', 'approved'], true)) return 'Paid';
    if ($paid > 0 && ($due <= 0 || $paid < $due)) return 'Part-paid';
    if ($status === 'pending') return 'Pending';
    if (in_array($status, ['verified', 'approved'], true)) return 'Paid';
    return 'Unpaid';
}

$classId = max(0, (int)($_GET['class_id'] ?? 0));
$className = 'All Classes';

if ($classId > 0) {
    $classStmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = :id AND active = 1 LIMIT 1");
    $classStmt->execute([':id' => $classId]);
    $classNameFound = $classStmt->fetchColumn();
    if ($classNameFound === false) {
        http_response_code(404);
        exit('Selected class was not found.');
    }
    $className = (string)$classNameFound;
}

$hasStudentParentId = (bool)$pdo->query("SHOW COLUMNS FROM students LIKE 'parent_id'")->fetch(PDO::FETCH_ASSOC);
$hasStudentParentIdLegacy = (bool)$pdo->query("SHOW COLUMNS FROM students LIKE 'parentId'")->fetch(PDO::FETCH_ASSOC);
if ($hasStudentParentId && $hasStudentParentIdLegacy) {
    $studentParentExpr = 'COALESCE(NULLIF(s.parent_id, 0), NULLIF(s.parentId, 0))';
} elseif ($hasStudentParentId) {
    $studentParentExpr = 'NULLIF(s.parent_id, 0)';
} elseif ($hasStudentParentIdLegacy) {
    $studentParentExpr = 'NULLIF(s.parentId, 0)';
} else {
    $studentParentExpr = 'NULL';
}

$sql = "
    SELECT
        c.class_name,
        s.student_id AS student_code,
        s.student_name,
        p.full_name AS parent_name,
        p.email AS parent_email,
        fp.id AS payment_id,
        COALESCE(fp.plan_type, e.fee_plan, '') AS plan_type,
        COALESCE(fp.instalment_label, '') AS instalment_label,
        fp.due_amount,
        fp.paid_amount,
        fp.due_date,
        fp.payment_ref,
        fp.status AS recorded_status,
        fp.verified_at
    FROM (
        SELECT ca1.student_id, ca1.class_id
        FROM class_assignments ca1
        INNER JOIN (
            SELECT ca2.student_id, MAX(ca2.id) AS assignment_id
            FROM class_assignments ca2
            INNER JOIN classes c2 ON c2.id = ca2.class_id AND c2.active = 1
            GROUP BY ca2.student_id
        ) latest_ca ON latest_ca.assignment_id = ca1.id
    ) ca
    INNER JOIN classes c ON c.id = ca.class_id AND c.active = 1
    INNER JOIN students s ON s.id = ca.student_id
    LEFT JOIN pcm_fee_payments fp ON fp.student_id = s.id
    LEFT JOIN pcm_enrolments e ON e.id = fp.enrolment_id
    LEFT JOIN parents p ON p.id = COALESCE(fp.parent_id, e.parent_id, {$studentParentExpr})
    WHERE s.approval_status = 'Approved'
      AND LOWER(COALESCE(s.status, 'active')) <> 'past'
";
$params = [];
if ($classId > 0) {
    $sql .= " AND c.id = :class_id";
    $params[':class_id'] = $classId;
}
$sql .= "
    ORDER BY c.class_name ASC,
             s.student_name ASC,
             FIELD(COALESCE(fp.plan_type, e.fee_plan, ''), 'Term-wise', 'Half-yearly', 'Yearly', 'Additional'),
             fp.due_date ASC,
             fp.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$slug = preg_replace('/[^A-Za-z0-9]+/', '_', $className);
$slug = trim((string)$slug, '_') ?: 'classes';
$filename = 'student_fee_status_' . strtolower($slug) . '_' . date('Ymd') . '.xlsx';
$headers = [
    'Class', 'Student ID', 'Student Name', 'Parent Name', 'Parent Email',
    'Fee Plan', 'Instalment', 'Amount Due', 'Amount Paid', 'Balance',
    'Payment Status', 'Recorded Status', 'Due Date', 'Payment Reference', 'Verified At'
];

$sheetRows = [];
$headerCells = [];
foreach ($headers as $index => $header) {
    $headerCells[] = xlsx_text_cell(xlsx_column_name($index + 1) . '1', $header, 1);
}
$sheetRows[] = '<row r="1" ht="24" customHeight="1">' . implode('', $headerCells) . '</row>';

$rowNumber = 2;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $due = (float)($row['due_amount'] ?? 0);
    $paid = (float)($row['paid_amount'] ?? 0);
    $hasPayment = !empty($row['payment_id']);

    $cells = [
        xlsx_text_cell('A' . $rowNumber, $row['class_name']),
        xlsx_text_cell('B' . $rowNumber, $row['student_code']),
        xlsx_text_cell('C' . $rowNumber, $row['student_name']),
        xlsx_text_cell('D' . $rowNumber, $row['parent_name']),
        xlsx_text_cell('E' . $rowNumber, $row['parent_email']),
        xlsx_text_cell('F' . $rowNumber, $row['plan_type']),
        xlsx_text_cell('G' . $rowNumber, $row['instalment_label']),
        $hasPayment ? xlsx_number_cell('H' . $rowNumber, $due, 2) : xlsx_text_cell('H' . $rowNumber, ''),
        $hasPayment ? xlsx_number_cell('I' . $rowNumber, $paid, 2) : xlsx_text_cell('I' . $rowNumber, ''),
        $hasPayment ? xlsx_number_cell('J' . $rowNumber, max(0, $due - $paid), 2) : xlsx_text_cell('J' . $rowNumber, ''),
        xlsx_text_cell('K' . $rowNumber, report_payment_status($row)),
        xlsx_text_cell('L' . $rowNumber, $row['recorded_status']),
        xlsx_date_cell('M' . $rowNumber, $row['due_date'], 3),
        xlsx_text_cell('N' . $rowNumber, $row['payment_ref']),
        xlsx_date_cell('O' . $rowNumber, $row['verified_at'], 4),
    ];
    $sheetRows[] = '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
    $rowNumber++;
}

$lastRow = max(1, $rowNumber - 1);
$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . '<cols>'
    . '<col min="1" max="1" width="20" customWidth="1"/><col min="2" max="2" width="14" customWidth="1"/>'
    . '<col min="3" max="4" width="24" customWidth="1"/><col min="5" max="5" width="30" customWidth="1"/>'
    . '<col min="6" max="7" width="16" customWidth="1"/><col min="8" max="10" width="14" customWidth="1"/>'
    . '<col min="11" max="12" width="17" customWidth="1"/><col min="13" max="13" width="14" customWidth="1"/>'
    . '<col min="14" max="14" width="22" customWidth="1"/><col min="15" max="15" width="20" customWidth="1"/>'
    . '</cols><sheetData>' . implode('', $sheetRows) . '</sheetData>'
    . '<autoFilter ref="A1:O' . $lastRow . '"/>'
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

$tempFile = tempnam(sys_get_temp_dir(), 'bbcc_fee_status_');
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
$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Student Fee Status" sheetId="1" r:id="rId1"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->addFromString('xl/styles.xml', $stylesXml);
$createdAt = gmdate('Y-m-d\TH:i:s\Z');
$zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Student Fee Status</dc:title><dc:creator>BBCC</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:created></cp:coreProperties>');
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
