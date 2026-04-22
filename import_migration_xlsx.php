<?php
/**
 * import_migration_xlsx.php
 *
 * Usage:
 *   php import_migration_xlsx.php "/absolute/path/to/bbcc_data_migration_filled_from_my_data.xlsx"
 *   php import_migration_xlsx.php "/absolute/path/to/file.xlsx" --dry-run
 */

require_once __DIR__ . '/include/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

function usage(): void {
    echo "Usage:\n";
    echo "  php import_migration_xlsx.php \"/absolute/path/to/file.xlsx\" [--dry-run]\n";
}

function fail(string $msg, int $code = 1): void {
    fwrite(STDERR, "[ERROR] {$msg}\n");
    exit($code);
}

function info(string $msg): void {
    fwrite(STDOUT, "[INFO] {$msg}\n");
}

function normalize_header(string $h): string {
    $h = strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim((string)$h, '_');
}

function col_to_idx(string $col): int {
    $col = strtoupper($col);
    $n = 0;
    $len = strlen($col);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($col[$i]) - 64);
    }
    return $n - 1;
}

function parse_cell_value(SimpleXMLElement $cell, array $sharedStrings): string {
    $type = (string)($cell['t'] ?? '');

    if ($type === 'inlineStr') {
        return trim((string)$cell->is->t);
    }

    if ($type === 's') {
        $idx = (int)($cell->v ?? -1);
        return isset($sharedStrings[$idx]) ? $sharedStrings[$idx] : '';
    }

    if (isset($cell->v)) {
        return trim((string)$cell->v);
    }

    return '';
}

function parse_shared_strings(ZipArchive $zip): array {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) return [];

    $sx = simplexml_load_string($xml);
    if (!$sx) return [];

    $strings = [];
    foreach ($sx->si as $si) {
        if (isset($si->t)) {
            $strings[] = (string)$si->t;
            continue;
        }
        // Rich text run
        $parts = [];
        foreach ($si->r as $r) {
            $parts[] = (string)$r->t;
        }
        $strings[] = implode('', $parts);
    }
    return $strings;
}

function parse_workbook_sheets(ZipArchive $zip): array {
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        fail("Invalid XLSX: workbook metadata missing.");
    }

    $wb = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);
    if (!$wb || !$rels) {
        fail("Invalid XLSX: failed to parse workbook metadata.");
    }

    $relsNs = $rels->getNamespaces(true);
    $wbNs = $wb->getNamespaces(true);

    $relsChildren = $rels->children($relsNs[''] ?? null);
    $mapRidToTarget = [];
    foreach ($relsChildren->Relationship as $rel) {
        $id = (string)$rel['Id'];
        $target = (string)$rel['Target'];
        $mapRidToTarget[$id] = $target;
    }

    $wbChildren = $wb->children($wbNs[''] ?? null);
    $sheetNodes = $wbChildren->sheets->sheet;

    $sheetMap = [];
    foreach ($sheetNodes as $sheet) {
        $name = (string)$sheet['name'];
        $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
        if ($name !== '' && isset($mapRidToTarget[$rid])) {
            $target = $mapRidToTarget[$rid];
            if (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . ltrim($target, '/');
            }
            $sheetMap[$name] = $target;
        }
    }
    return $sheetMap;
}

function read_xlsx(string $path): array {
    if (!is_file($path)) {
        fail("XLSX file not found: {$path}");
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        fail("Unable to open XLSX: {$path}");
    }

    $shared = parse_shared_strings($zip);
    $sheets = parse_workbook_sheets($zip);

    $all = [];
    foreach ($sheets as $sheetName => $sheetPath) {
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) {
            continue;
        }
        $sx = simplexml_load_string($xml);
        if (!$sx) continue;

        $ns = $sx->getNamespaces(true);
        $children = $sx->children($ns[''] ?? null);
        $rowsNode = $children->sheetData->row ?? [];

        $sheetRows = [];
        foreach ($rowsNode as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r']; // e.g., A1
                if (!preg_match('/^([A-Z]+)\d+$/i', $ref, $m)) {
                    continue;
                }
                $idx = col_to_idx($m[1]);
                $cells[$idx] = parse_cell_value($c, $shared);
            }
            if (!empty($cells)) {
                ksort($cells);
                $max = (int)max(array_keys($cells));
                $arr = array_fill(0, $max + 1, '');
                foreach ($cells as $i => $v) {
                    $arr[$i] = $v;
                }
                $sheetRows[] = $arr;
            }
        }

        if (empty($sheetRows)) {
            $all[$sheetName] = [];
            continue;
        }

        $headers = array_map(
            static fn($h) => normalize_header((string)$h),
            $sheetRows[0]
        );

        $data = [];
        for ($i = 1; $i < count($sheetRows); $i++) {
            $r = $sheetRows[$i];
            $rowAssoc = [];
            foreach ($headers as $idx => $h) {
                if ($h === '') continue;
                $rowAssoc[$h] = $r[$idx] ?? '';
            }
            $nonEmpty = false;
            foreach ($rowAssoc as $v) {
                if (trim((string)$v) !== '') {
                    $nonEmpty = true;
                    break;
                }
            }
            if ($nonEmpty) {
                $data[] = $rowAssoc;
            }
        }

        $all[$sheetName] = $data;
    }

    $zip->close();
    return $all;
}

function v(array $row, string $key): string {
    return trim((string)($row[$key] ?? ''));
}

function to_float(string $v): float {
    $x = trim($v);
    if ($x === '') return 0.0;
    $x = str_replace(['$', ','], '', $x);
    return (float)$x;
}

function to_nullable_date(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : null;
}

function to_nullable_datetime(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v)) {
        return strlen($v) === 10 ? ($v . ' 00:00:00') : $v;
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

function norm_fee_plan(string $plan): string {
    $p = strtolower(trim($plan));
    if ($p === 'half-yearly' || $p === 'half yearly' || $p === 'halfyearly') return 'Half-yearly';
    if ($p === 'yearly') return 'Yearly';
    return 'Term-wise';
}

function norm_payment_status(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'verified') return 'Verified';
    if ($s === 'pending') return 'Pending';
    if ($s === 'rejected') return 'Rejected';
    return 'Unpaid';
}

function norm_enrol_status(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'approved') return 'Approved';
    if ($s === 'rejected') return 'Rejected';
    return 'Pending';
}

function map_campus_key(string $campus): string {
    $c = strtolower(trim($campus));
    if (strpos($c, 'hawker') !== false) return 'c2';
    return 'c1';
}

function ensure_schema(PDO $pdo): void {
    // classes.campus_key
    $s = $pdo->query("SHOW COLUMNS FROM classes LIKE 'campus_key'");
    if (!$s || !$s->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE classes ADD COLUMN campus_key VARCHAR(20) NOT NULL DEFAULT 'c1' AFTER class_name");
    }

    // pcm_fee_payments.plan_type include Additional
    $col = $pdo->query("SHOW COLUMNS FROM pcm_fee_payments LIKE 'plan_type'")->fetch(PDO::FETCH_ASSOC);
    $type = strtolower((string)($col['Type'] ?? ''));
    if ($type !== '' && strpos($type, 'additional') === false) {
        $pdo->exec("ALTER TABLE pcm_fee_payments MODIFY COLUMN plan_type ENUM('Term-wise','Half-yearly','Yearly','Additional') NOT NULL");
    }

    // pcm_fee_payments.instalment_label length
    $lab = $pdo->query("SHOW COLUMNS FROM pcm_fee_payments LIKE 'instalment_label'")->fetch(PDO::FETCH_ASSOC);
    $labType = strtolower((string)($lab['Type'] ?? ''));
    if ($labType !== '' && preg_match('/varchar\((\d+)\)/', $labType, $m)) {
        if ((int)$m[1] < 120) {
            $pdo->exec("ALTER TABLE pcm_fee_payments MODIFY COLUMN instalment_label VARCHAR(120) NOT NULL");
        }
    }
}

function main(array $argv): void {
    global $DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME;

    if (count($argv) < 2) {
        usage();
        exit(1);
    }

    $xlsxPath = $argv[1];
    $dryRun = in_array('--dry-run', $argv, true);

    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $sheets = read_xlsx($xlsxPath);
    $required = ['parents', 'students', 'classes', 'class_assignments', 'enrolments', 'fee_payments'];
    foreach ($required as $r) {
        if (!isset($sheets[$r])) {
            fail("Required sheet missing: {$r}");
        }
    }

    info("Loaded workbook. Rows: parents=" . count($sheets['parents']) .
        ", students=" . count($sheets['students']) .
        ", classes=" . count($sheets['classes']) .
        ", class_assignments=" . count($sheets['class_assignments']) .
        ", enrolments=" . count($sheets['enrolments']) .
        ", fee_payments=" . count($sheets['fee_payments']));

    ensure_schema($pdo);

    $parentKeyToId = [];
    $studentKeyToId = [];
    $classKeyToId = [];
    $enrolKeyToId = [];

    $summary = [
        'parents' => 0,
        'students' => 0,
        'classes' => 0,
        'assignments' => 0,
        'enrolments' => 0,
        'fee_payments' => 0,
    ];

    $pdo->beginTransaction();
    try {
        // 1) Parents
        $insParent = $pdo->prepare("
            INSERT INTO parents (full_name, email, phone, address, status, pin_hash)
            VALUES (:full_name, :email, :phone, :address, :status, :pin_hash)
            ON DUPLICATE KEY UPDATE
              full_name = VALUES(full_name),
              phone = VALUES(phone),
              address = VALUES(address),
              status = VALUES(status),
              pin_hash = CASE
                  WHEN VALUES(pin_hash) IS NULL OR VALUES(pin_hash) = '' THEN pin_hash
                  ELSE VALUES(pin_hash)
              END
        ");
        $selParent = $pdo->prepare("SELECT id FROM parents WHERE email = :email LIMIT 1");

        foreach ($sheets['parents'] as $row) {
            $pKey = v($row, 'parent_key');
            $email = strtolower(v($row, 'email'));
            if ($pKey === '' || $email === '') continue;

            $pinPlain = v($row, 'kiosk_pin_plain');
            $pinHash = $pinPlain !== '' ? password_hash($pinPlain, PASSWORD_DEFAULT) : null;

            $insParent->execute([
                ':full_name' => v($row, 'full_name') !== '' ? v($row, 'full_name') : 'Unknown Parent',
                ':email' => $email,
                ':phone' => v($row, 'phone') !== '' ? v($row, 'phone') : null,
                ':address' => v($row, 'address') !== '' ? v($row, 'address') : null,
                ':status' => (strtolower(v($row, 'status')) === 'inactive') ? 'Inactive' : 'Active',
                ':pin_hash' => $pinHash,
            ]);

            $selParent->execute([':email' => $email]);
            $pid = (int)($selParent->fetchColumn() ?: 0);
            if ($pid > 0) {
                $parentKeyToId[$pKey] = $pid;
                $summary['parents']++;
            }
        }

        // 2) Classes
        $selClassByName = $pdo->prepare("SELECT id FROM classes WHERE LOWER(class_name) = LOWER(:name) LIMIT 1");
        $insClass = $pdo->prepare("
            INSERT INTO classes (class_name, campus_key, description, capacity, schedule_text, active)
            VALUES (:name, :campus_key, NULL, NULL, NULL, :active)
        ");
        $updClass = $pdo->prepare("
            UPDATE classes
            SET campus_key = :campus_key, active = :active
            WHERE id = :id
        ");

        foreach ($sheets['classes'] as $row) {
            $cKey = v($row, 'class_key');
            $name = v($row, 'class_name');
            if ($cKey === '' || $name === '') continue;

            $campusKey = map_campus_key(v($row, 'campus'));
            $active = (v($row, 'active_1_or_0') === '0') ? 0 : 1;

            $selClassByName->execute([':name' => $name]);
            $existingId = (int)($selClassByName->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $updClass->execute([
                    ':id' => $existingId,
                    ':campus_key' => $campusKey,
                    ':active' => $active
                ]);
                $classKeyToId[$cKey] = $existingId;
                $summary['classes']++;
                continue;
            }

            $insClass->execute([
                ':name' => $name,
                ':campus_key' => $campusKey,
                ':active' => $active,
            ]);
            $classKeyToId[$cKey] = (int)$pdo->lastInsertId();
            $summary['classes']++;
        }

        // 3) Students
        $selStudentByPublicId = $pdo->prepare("SELECT id FROM students WHERE student_id = :sid LIMIT 1");
        $selStudentByNameParent = $pdo->prepare("SELECT id FROM students WHERE student_name = :name AND (parent_id = :pid OR parentId = :pid) LIMIT 1");
        $insStudent = $pdo->prepare("
            INSERT INTO students
                (student_id, student_name, dob, gender, medical_issue, approval_status, parentId, parent_id, status, payment_plan, payment_amount)
            VALUES
                (:student_id, :student_name, :dob, :gender, :medical_issue, :approval_status, :parent_id, :parent_id, 'Active', :payment_plan, :payment_amount)
        ");
        $updStudent = $pdo->prepare("
            UPDATE students
            SET student_id = :student_id,
                student_name = :student_name,
                dob = :dob,
                gender = :gender,
                medical_issue = :medical_issue,
                approval_status = :approval_status,
                parentId = :parent_id,
                parent_id = :parent_id,
                payment_plan = :payment_plan,
                payment_amount = :payment_amount
            WHERE id = :id
        ");

        foreach ($sheets['students'] as $row) {
            $sKey = v($row, 'student_key');
            $pKey = v($row, 'parent_key');
            if ($sKey === '' || $pKey === '') continue;
            if (!isset($parentKeyToId[$pKey])) continue;
            $parentId = (int)$parentKeyToId[$pKey];

            $studentName = v($row, 'student_name');
            if ($studentName === '') continue;
            $publicId = v($row, 'student_id_public');

            $existingId = 0;
            if ($publicId !== '') {
                $selStudentByPublicId->execute([':sid' => $publicId]);
                $existingId = (int)($selStudentByPublicId->fetchColumn() ?: 0);
            }
            if ($existingId <= 0) {
                $selStudentByNameParent->execute([':name' => $studentName, ':pid' => $parentId]);
                $existingId = (int)($selStudentByNameParent->fetchColumn() ?: 0);
            }

            $params = [
                ':student_id' => $publicId !== '' ? $publicId : null,
                ':student_name' => $studentName,
                ':dob' => to_nullable_date(v($row, 'dob_yyyy_mm_dd')),
                ':gender' => v($row, 'gender') !== '' ? v($row, 'gender') : null,
                ':medical_issue' => v($row, 'medical_notes') !== '' ? v($row, 'medical_notes') : null,
                ':approval_status' => 'Approved',
                ':parent_id' => $parentId,
                ':payment_plan' => null,
                ':payment_amount' => null,
            ];

            if ($existingId > 0) {
                $params[':id'] = $existingId;
                $updStudent->execute($params);
                $studentKeyToId[$sKey] = $existingId;
            } else {
                $insStudent->execute($params);
                $studentKeyToId[$sKey] = (int)$pdo->lastInsertId();
            }
            $summary['students']++;
        }

        // 4) Class assignments
        $selAssign = $pdo->prepare("SELECT id FROM class_assignments WHERE student_id = :sid LIMIT 1");
        $insAssign = $pdo->prepare("INSERT INTO class_assignments (class_id, student_id, assigned_by) VALUES (:cid, :sid, :by)");
        $updAssign = $pdo->prepare("UPDATE class_assignments SET class_id = :cid, assigned_by = :by, assigned_at = NOW() WHERE id = :id");
        $actor = (string)(getenv('USER') ?: 'migration');

        foreach ($sheets['class_assignments'] as $row) {
            $sKey = v($row, 'student_key');
            $cKey = v($row, 'class_key');
            if ($sKey === '' || $cKey === '') continue;
            if (!isset($studentKeyToId[$sKey], $classKeyToId[$cKey])) continue;
            $sid = (int)$studentKeyToId[$sKey];
            $cid = (int)$classKeyToId[$cKey];

            $selAssign->execute([':sid' => $sid]);
            $aid = (int)($selAssign->fetchColumn() ?: 0);
            if ($aid > 0) {
                $updAssign->execute([':id' => $aid, ':cid' => $cid, ':by' => $actor]);
            } else {
                $insAssign->execute([':cid' => $cid, ':sid' => $sid, ':by' => $actor]);
            }
            $summary['assignments']++;
        }

        // 5) Enrolments
        $selEnrol = $pdo->prepare("SELECT id FROM pcm_enrolments WHERE student_id = :sid LIMIT 1");
        $insEnrol = $pdo->prepare("
            INSERT INTO pcm_enrolments
                (student_id, parent_id, fee_plan, fee_amount, status, submitted_at)
            VALUES
                (:sid, :pid, :plan, :amount, :status, COALESCE(:submitted_at, NOW()))
        ");
        $updEnrol = $pdo->prepare("
            UPDATE pcm_enrolments
            SET parent_id = :pid,
                fee_plan = :plan,
                fee_amount = :amount,
                status = :status,
                submitted_at = COALESCE(:submitted_at, submitted_at)
            WHERE id = :id
        ");

        foreach ($sheets['enrolments'] as $row) {
            $eKey = v($row, 'enrolment_key');
            $sKey = v($row, 'student_key');
            $pKey = v($row, 'parent_key');
            if ($eKey === '' || $sKey === '' || $pKey === '') continue;
            if (!isset($studentKeyToId[$sKey], $parentKeyToId[$pKey])) continue;

            $sid = (int)$studentKeyToId[$sKey];
            $pid = (int)$parentKeyToId[$pKey];
            $plan = norm_fee_plan(v($row, 'fee_plan'));
            $amount = to_float(v($row, 'fee_amount'));
            $status = norm_enrol_status(v($row, 'status'));
            $submittedAt = to_nullable_datetime(v($row, 'submitted_at_yyyy_mm_dd_hh_mm_ss'));

            $selEnrol->execute([':sid' => $sid]);
            $existingId = (int)($selEnrol->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $updEnrol->execute([
                    ':id' => $existingId,
                    ':pid' => $pid,
                    ':plan' => $plan,
                    ':amount' => $amount,
                    ':status' => $status,
                    ':submitted_at' => $submittedAt,
                ]);
                $enrolKeyToId[$eKey] = $existingId;
            } else {
                $insEnrol->execute([
                    ':sid' => $sid,
                    ':pid' => $pid,
                    ':plan' => $plan,
                    ':amount' => $amount,
                    ':status' => $status,
                    ':submitted_at' => $submittedAt,
                ]);
                $enrolKeyToId[$eKey] = (int)$pdo->lastInsertId();
            }
            $summary['enrolments']++;
        }

        // 6) Fee payments
        $selFee = $pdo->prepare("
            SELECT id
            FROM pcm_fee_payments
            WHERE enrolment_id = :eid
              AND instalment_label = :label
            LIMIT 1
        ");
        $insFee = $pdo->prepare("
            INSERT INTO pcm_fee_payments
                (enrolment_id, student_id, parent_id, plan_type, instalment_label, due_amount, paid_amount, payment_ref, status, due_date, submitted_at, verified_by, verified_at)
            VALUES
                (:eid, :sid, :pid, :plan_type, :label, :due, :paid, :ref, :status, :due_date, :submitted_at, :verified_by, :verified_at)
        ");
        $updFee = $pdo->prepare("
            UPDATE pcm_fee_payments
            SET student_id = :sid,
                parent_id = :pid,
                plan_type = :plan_type,
                due_amount = :due,
                paid_amount = :paid,
                payment_ref = :ref,
                status = :status,
                due_date = :due_date,
                submitted_at = :submitted_at,
                verified_by = :verified_by,
                verified_at = :verified_at
            WHERE id = :id
        ");

        foreach ($sheets['fee_payments'] as $row) {
            $eKey = v($row, 'enrolment_key');
            $sKey = v($row, 'student_key');
            $pKey = v($row, 'parent_key');
            $label = v($row, 'instalment_label');
            if ($eKey === '' || $sKey === '' || $pKey === '' || $label === '') continue;
            if (!isset($enrolKeyToId[$eKey], $studentKeyToId[$sKey], $parentKeyToId[$pKey])) continue;

            $eid = (int)$enrolKeyToId[$eKey];
            $sid = (int)$studentKeyToId[$sKey];
            $pid = (int)$parentKeyToId[$pKey];
            $planType = trim(v($row, 'plan_type'));
            if (!in_array($planType, ['Term-wise', 'Half-yearly', 'Yearly', 'Additional'], true)) {
                $planType = 'Term-wise';
            }
            $due = to_float(v($row, 'due_amount'));
            $paid = to_float(v($row, 'paid_amount'));
            $status = norm_payment_status(v($row, 'status'));
            $ref = v($row, 'payment_ref');
            $dueDate = to_nullable_date(v($row, 'due_date_yyyy_mm_dd'));
            $submittedAt = to_nullable_datetime(v($row, 'submitted_at_yyyy_mm_dd_hh_mm_ss'));
            $verifiedBy = ($status === 'Verified') ? 'migration' : null;
            $verifiedAt = ($status === 'Verified') ? ($submittedAt ?: date('Y-m-d H:i:s')) : null;

            $selFee->execute([':eid' => $eid, ':label' => $label]);
            $fid = (int)($selFee->fetchColumn() ?: 0);

            $params = [
                ':eid' => $eid,
                ':sid' => $sid,
                ':pid' => $pid,
                ':plan_type' => $planType,
                ':label' => $label,
                ':due' => $due,
                ':paid' => $paid,
                ':ref' => ($ref !== '' ? $ref : null),
                ':status' => $status,
                ':due_date' => $dueDate,
                ':submitted_at' => $submittedAt,
                ':verified_by' => $verifiedBy,
                ':verified_at' => $verifiedAt,
            ];

            if ($fid > 0) {
                $params[':id'] = $fid;
                $updFee->execute($params);
            } else {
                $insFee->execute($params);
            }
            $summary['fee_payments']++;
        }

        if ($dryRun) {
            $pdo->rollBack();
            info("Dry-run complete. No changes committed.");
        } else {
            $pdo->commit();
            info("Import committed successfully.");
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail("Import failed: " . $e->getMessage());
    }

    info("Summary:");
    foreach ($summary as $k => $v) {
        info("  {$k}: {$v}");
    }
}

main($argv);

