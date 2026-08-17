<?php
// admin-enrolments.php — Review / Approve / Reject parent enrolment requests
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_once "include/notifications.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized"); exit; }

$pdo   = pcm_pdo();
$flash = '';
$ok    = false;
pcm_ensure_enrolment_campus_preference($pdo);
pcm_ensure_enrolment_start_term($pdo);
$currentActor = (string)($_SESSION['username'] ?? 'admin');
$campusChoices = pcm_campus_choice_labels();
$ageSettings = pcm_enrolment_age_settings($pdo);
$allClasses = $pdo->query("SELECT id, class_name FROM classes WHERE active=1 ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);

function bbcc_tokens(string $text): array {
    $parts = preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [];
    $stop = ['campus','college','high','school','hs','the','and','of'];
    $out = [];
    foreach ($parts as $p) {
        if ($p === '' || strlen($p) < 4 || in_array($p, $stop, true)) continue;
        $out[$p] = true;
    }
    return array_keys($out);
}

function bbcc_class_matches_campus(string $className, array $campusLabels): bool {
    if (empty($campusLabels)) return true;
    $hay = strtolower($className);
    $tokens = [];
    foreach ($campusLabels as $label) {
        $tokens = array_merge($tokens, bbcc_tokens((string)$label));
    }
    $tokens = array_unique($tokens);
    if (empty($tokens)) return true;
    foreach ($tokens as $t) {
        if (strpos($hay, $t) !== false) return true;
    }
    return false;
}

// Shared row markup for both the Unallocated and Enrolled-by-class tables --
// same column shape either way, so a fix here only has to happen once.
function bbcc_render_enrolment_rows(array $rows, array $campusChoices, array $allClasses, array $auditByEnrolment): void {
    foreach ($rows as $i => $e) {
        $rowStatusNorm = strtolower(trim((string)($e['status'] ?? '')));
        $selectedCampusKeys = pcm_normalize_campus_selection((string)($e['campus_preference'] ?? ''));
        $selectedCampusLabels = [];
        foreach ($selectedCampusKeys as $ck) {
            if (isset($campusChoices[$ck])) $selectedCampusLabels[] = $campusChoices[$ck];
        }
        $matchingClasses = array_values(array_filter($allClasses, function($cl) use ($selectedCampusLabels) {
            return bbcc_class_matches_campus((string)($cl['class_name'] ?? ''), $selectedCampusLabels);
        }));
        if (empty($matchingClasses)) {
            $matchingClasses = $allClasses;
        }
        $isManualEnrolment = (int)($e['is_manual_enrolment'] ?? 0) === 1;
        $canAssignClass = $isManualEnrolment || in_array($rowStatusNorm, ['pending', 'approved'], true);
        ?>
        <tr data-status="<?= $e['status'] ?>">
            <td><?= $i + 1 ?></td>
            <td><code><?= h($e['stu_code']) ?></code></td>
            <td><?= h($e['student_name']) ?></td>
            <td><?= h(pcm_campus_selection_label((string)($e['campus_preference'] ?? ''))) ?></td>
            <td><?= h($e['assigned_class_name'] ?? 'Not assigned') ?></td>
            <td><?= h($e['assigned_teacher_name'] ?? '') ?></td>
            <td><?= h($e['parent_phone'] ?? '-') ?></td>
            <td><?= h($e['fee_plan']) ?></td>
            <td>$<?= number_format($e['fee_amount'], 2) ?></td>
            <td><?= h($e['payment_ref'] ?? '—') ?></td>
            <td>
                <?php if (!empty($e['proof_path'])): ?>
                    <button type="button" class="btn btn-sm btn-outline-info js-proof-btn" data-proof="<?= h($e['proof_path']) ?>" data-child="<?= h($e['student_name']) ?>">
                        View
                    </button>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
            <td><?= h($e['parent_name']) ?><br><small class="text-muted"><?= h($e['parent_email']) ?></small></td>
            <td><span class="badge badge-<?= pcm_badge($e['status']) ?>"><?= h($e['status']) ?></span>
                <?php if ($e['admin_note']): ?><br><small><?= h($e['admin_note']) ?></small><?php endif; ?>
            </td>
            <td><?= date('d M Y', strtotime($e['submitted_at'])) ?></td>
            <td>
                <div class="d-flex flex-column" style="gap:6px;min-width:220px;">
                    <?php $hasClassYet = !empty($e['assigned_class_id']); ?>
                    <form method="POST" class="js-assign-class-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="assign_class">
                        <input type="hidden" name="enrolment_id" value="<?= (int)$e['id'] ?>">
                        <select name="class_id" class="form-control form-control-sm mb-1" <?= $canAssignClass ? 'required' : 'disabled' ?>>
                            <option value=""><?= $canAssignClass ? 'Select class…' : 'Class assignment locked' ?></option>
                            <?php foreach ($matchingClasses as $cl): ?>
                                <option value="<?= (int)$cl['id'] ?>" <?= ((int)$e['assigned_class_id'] === (int)$cl['id']) ? 'selected' : '' ?>>
                                    <?= h($cl['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary btn-block js-save-class-btn" <?= $canAssignClass ? '' : 'disabled' ?>>
                            <i class="fas fa-save mr-1"></i><?= $hasClassYet ? 'Update Class' : 'Save Class' ?>
                        </button>
                    </form>
                    <?php if ($rowStatusNorm === 'pending'): ?>
                    <div class="btn-group btn-group-sm w-100" role="group">
                        <button type="button" class="btn btn-outline-success js-approve-enrol-btn" data-enrolment-id="<?= (int)$e['id'] ?>" data-student-name="<?= h($e['student_name']) ?>" title="Approve"><i class="fas fa-check mr-1"></i>Approve</button>
                        <button type="button" class="btn btn-outline-danger js-reject-enrol-btn" data-enrolment-id="<?= (int)$e['id'] ?>" data-student-name="<?= h($e['student_name']) ?>" title="Reject"><i class="fas fa-times"></i></button>
                        <button type="button" class="btn btn-outline-warning js-changes-enrol-btn" data-enrolment-id="<?= (int)$e['id'] ?>" data-student-name="<?= h($e['student_name']) ?>" title="Request Changes"><i class="fas fa-edit"></i></button>
                    </div>
                    <?php else: ?>
                        <span class="text-muted small"><?= h($e['reviewed_by'] ?? '') ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <?php
                    $historyItems = $auditByEnrolment[(int)$e['id']] ?? [];
                    $historyCount = count($historyItems);
                ?>
                <button type="button" class="btn btn-sm btn-outline-secondary js-history-btn" data-enrolment-id="<?= (int)$e['id'] ?>" data-student-name="<?= h($e['student_name']) ?>">
                    View (<?= $historyCount ?>)
                </button>
            </td>
        </tr>
        <?php
    }
}

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve','reject','request_changes','assign_class','manual_enrol','enrol_registered_child','reject_registration','save_age_settings'], true)) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'reject_registration') {
        $studentDbId = (int)($_POST['student_id'] ?? 0);
        $note = trim((string)($_POST['admin_note'] ?? ''));
        if ($studentDbId <= 0) {
            $flash = 'Invalid student.';
        } elseif ($note === '') {
            $flash = 'A rejection reason is required.';
        } else {
            try {
                $result = pcm_process_enrolment_decision($pdo, $studentDbId, 'reject', $currentActor, $note);
                pcm_log_enrolment_event($pdo, $studentDbId, (int)($result['enrolment_id'] ?? 0), 'child_registration_rejected', $currentActor, $note);

                if (!empty($result['parent_email'])) {
                    pcm_notify_parent_enrolment(
                        (string)$result['parent_email'],
                        (string)$result['parent_name'],
                        (string)$result['student_name'],
                        (string)$result['new_status'],
                        $note
                    );
                    bbcc_notify_username(
                        $pdo,
                        (string)$result['parent_email'],
                        'Child Registration Rejected for ' . (string)$result['student_name'],
                        'Your child registration was not approved. Please review admin notes and contact admin if needed.',
                        'children-enrollment'
                    );
                }

                $flash = 'Registration rejected for <strong>' . h((string)$result['student_name']) . '</strong>.';
                $ok = true;
            } catch (Throwable $e) {
                $flash = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'enrol_registered_child') {
        $studentDbId = (int)($_POST['student_id'] ?? 0);
        $plan = trim((string)($_POST['fee_plan'] ?? 'Term-wise'));
        $startTerm = pcm_normalize_start_term($_POST['start_term'] ?? 1);
        $ref = trim((string)($_POST['payment_ref'] ?? ''));
        $approveNow = isset($_POST['approve_now']) && (string)$_POST['approve_now'] === '1';
        $campusSelection = $_POST['campus_choice'] ?? [];
        if (!is_array($campusSelection)) $campusSelection = [];
        $campusSelection = array_values(array_unique(array_filter(array_map('strval', $campusSelection))));
        $allowedCampusChoices = array_keys($campusChoices);

        if ($studentDbId <= 0) {
            $flash = 'Invalid child selected.';
        } elseif (!in_array($plan, ['Term-wise', 'Half-yearly', 'Yearly'], true)) {
            $flash = 'Invalid fee plan selected.';
        } elseif (!pcm_plan_allowed_for_start_term($plan, $startTerm)) {
            $flash = 'That fee plan is not available for the selected starting term.';
        } elseif (empty($campusSelection) || array_diff($campusSelection, $allowedCampusChoices)) {
            $flash = 'Please select at least one valid campus.';
        } else {
            try {
                $stu = $pdo->prepare("
                    SELECT s.id, s.student_name, s.approval_status,
                           s.parent_id AS parent_id,
                           p.full_name AS parent_name, p.email AS parent_email
                    FROM students s
                    LEFT JOIN parents p ON p.id = s.parent_id
                    WHERE s.id = :id
                    LIMIT 1
                ");
                $stu->execute([':id' => $studentDbId]);
                $student = $stu->fetch(PDO::FETCH_ASSOC);
                if (!$student) throw new Exception('Child not found.');

                $parentId = (int)($student['parent_id'] ?? 0);
                if ($parentId <= 0) throw new Exception('Parent link missing for this child.');

                $campusStored = implode(',', $campusSelection);
                $amount = pcm_plan_total_for_start_term($plan, $startTerm);
                $existing = $pdo->prepare("SELECT id FROM pcm_enrolments WHERE student_id = :sid LIMIT 1");
                $existing->execute([':sid' => $studentDbId]);
                $row = $existing->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $eid = (int)$row['id'];
                    $upd = $pdo->prepare("
                        UPDATE pcm_enrolments
                        SET fee_plan=:plan, campus_preference=:campus, start_term=:start_term, fee_amount=:amt, payment_ref=:ref,
                            status=:status, admin_note=NULL, reviewed_by=:rb, reviewed_at=:ra, submitted_at=NOW()
                        WHERE id=:id
                    ");
                    $upd->execute([
                        ':plan' => $plan,
                        ':campus' => $campusStored,
                        ':start_term' => $startTerm,
                        ':amt' => $amount,
                        ':ref' => ($ref !== '' ? $ref : null),
                        ':status' => $approveNow ? 'Approved' : 'Pending',
                        ':rb' => $approveNow ? $currentActor : null,
                        ':ra' => $approveNow ? date('Y-m-d H:i:s') : null,
                        ':id' => $eid,
                    ]);
                    pcm_log_enrolment_event($pdo, $studentDbId, $eid, 'admin_registered_child_enrolment_updated', $currentActor, 'Updated from registered children queue.');
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO pcm_enrolments
                        (student_id, parent_id, fee_plan, campus_preference, start_term, fee_amount, payment_ref, proof_path, status, admin_note, reviewed_by, reviewed_at, submitted_at)
                        VALUES
                        (:sid, :pid, :plan, :campus, :start_term, :amt, :ref, NULL, :status, NULL, :rb, :ra, NOW())
                    ");
                    $ins->execute([
                        ':sid' => $studentDbId,
                        ':pid' => $parentId,
                        ':plan' => $plan,
                        ':campus' => $campusStored,
                        ':start_term' => $startTerm,
                        ':amt' => $amount,
                        ':ref' => ($ref !== '' ? $ref : null),
                        ':status' => $approveNow ? 'Approved' : 'Pending',
                        ':rb' => $approveNow ? $currentActor : null,
                        ':ra' => $approveNow ? date('Y-m-d H:i:s') : null,
                    ]);
                    $eid = (int)$pdo->lastInsertId();
                    pcm_log_enrolment_event($pdo, $studentDbId, $eid, 'admin_registered_child_enrolment_created', $currentActor, 'Created from registered children queue.');
                }

                $feeCountStmt = $pdo->prepare("SELECT COUNT(*) FROM pcm_fee_payments WHERE enrolment_id = :eid");
                $feeCountStmt->execute([':eid' => $eid]);
                if ((int)$feeCountStmt->fetchColumn() === 0) {
                    pcm_create_fee_rows($pdo, $eid, $studentDbId, $parentId, $plan, null, $startTerm);
                    pcm_log_enrolment_event($pdo, $studentDbId, $eid, 'admin_fee_rows_created', $currentActor, 'Fee instalment rows created from registered children queue.');
                }

                if ($approveNow) {
                    $pdo->prepare("UPDATE students SET approval_status='Approved' WHERE id=:id")->execute([':id' => $studentDbId]);
                    $parentEmail = trim((string)($student['parent_email'] ?? ''));
                    if ($parentEmail !== '') {
                        pcm_notify_parent_enrolment_confirmed(
                            $parentEmail,
                            (string)($student['parent_name'] ?? 'Parent'),
                            (string)$student['student_name']
                        );
                    }
                }

                $flash = 'Enrollment created for <strong>' . h((string)$student['student_name']) . '</strong>.'
                    . ($approveNow ? ' Approved immediately.' : '');
                $ok = true;
            } catch (Throwable $e) {
                $flash = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'save_age_settings') {
        $ageYears = (int)($_POST['min_age_years'] ?? -1);
        $ageMonths = (int)($_POST['min_age_months'] ?? -1);

        if ($ageYears < 0 || $ageYears > 18 || $ageMonths < 0 || $ageMonths > 11) {
            $flash = 'Please enter a valid minimum age (0-18 years, 0-11 months).';
        } else {
            $pdo->prepare("
                INSERT INTO enrolment_settings (id, min_age_years, min_age_months, updated_by)
                VALUES (1, :y, :m, :u)
                ON DUPLICATE KEY UPDATE min_age_years = VALUES(min_age_years), min_age_months = VALUES(min_age_months), updated_by = VALUES(updated_by)
            ")->execute([':y' => $ageYears, ':m' => $ageMonths, ':u' => $currentActor]);

            $flash = 'Minimum enrolment age updated to ' . $ageYears . ' years ' . $ageMonths . ' months.';
            $ok = true;
        }
    } elseif ($action === 'manual_enrol') {
        $parentName = trim((string)($_POST['parent_name'] ?? ''));
        $parentEmail = strtolower(trim((string)($_POST['parent_email'] ?? '')));
        $parentPhone = trim((string)($_POST['parent_phone'] ?? ''));
        $parentAddress = trim((string)($_POST['parent_address'] ?? ''));
        $childName = trim((string)($_POST['child_name'] ?? ''));
        $childDob = trim((string)($_POST['child_dob'] ?? ''));
        $childGender = trim((string)($_POST['child_gender'] ?? ''));
        $plan = trim((string)($_POST['fee_plan'] ?? 'Term-wise'));
        $startTerm = pcm_normalize_start_term($_POST['start_term'] ?? 1);
        $ref = trim((string)($_POST['payment_ref'] ?? ''));
        $approveNow = isset($_POST['manual_approve_now']) && (string)$_POST['manual_approve_now'] === '1';
        $campusSelection = $_POST['campus_choice'] ?? [];
        if (!is_array($campusSelection)) $campusSelection = [];
        $campusSelection = array_values(array_unique(array_filter(array_map('strval', $campusSelection))));
        $allowedCampusChoices = array_keys($campusChoices);

        if ($parentName === '' || $childName === '') {
            $flash = 'Parent name and child name are required.';
        } elseif (!filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Please provide a valid parent email.';
        } elseif ($parentPhone === '') {
            $flash = 'Parent phone is required.';
        } elseif ($childDob === '') {
            $flash = "Child's date of birth is required.";
        } elseif (!pcm_meets_minimum_enrolment_age($childDob)) {
            $flash = 'Child must be at least ' . pcm_minimum_enrolment_age_label() . ' old to be enrolled.';
        } elseif (!in_array($plan, ['Term-wise', 'Half-yearly', 'Yearly'], true)) {
            $flash = 'Invalid fee plan selected.';
        } elseif (!pcm_plan_allowed_for_start_term($plan, $startTerm)) {
            $flash = 'That fee plan is not available for the selected starting term.';
        } elseif (empty($campusSelection) || array_diff($campusSelection, $allowedCampusChoices)) {
            $flash = 'Please select at least one valid campus.';
        } else {
            try {
                $pdo->beginTransaction();
                $parentId = 0;

                $parentFind = $pdo->prepare("SELECT id FROM parents WHERE LOWER(email)=:e LIMIT 1");
                $parentFind->execute([':e' => $parentEmail]);
                $parentRow = $parentFind->fetch(PDO::FETCH_ASSOC);
                if ($parentRow) {
                    $parentId = (int)$parentRow['id'];
                    $pdo->prepare("
                        UPDATE parents
                        SET full_name = :n, phone = :ph, address = :ad, username = COALESCE(NULLIF(username,''), :un)
                        WHERE id = :id
                    ")->execute([
                        ':n' => $parentName,
                        ':ph' => $parentPhone,
                        ':ad' => ($parentAddress !== '' ? $parentAddress : null),
                        ':un' => $parentEmail,
                        ':id' => $parentId
                    ]);
                } else {
                    $insParent = $pdo->prepare("
                        INSERT INTO parents (full_name, email, phone, address, username, status)
                        VALUES (:n, :e, :ph, :ad, :un, 'Active')
                    ");
                    $insParent->execute([
                        ':n' => $parentName,
                        ':e' => $parentEmail,
                        ':ph' => $parentPhone,
                        ':ad' => ($parentAddress !== '' ? $parentAddress : null),
                        ':un' => $parentEmail
                    ]);
                    $parentId = (int)$pdo->lastInsertId();
                }

                if ($parentId <= 0) {
                    throw new Exception('Failed to create/find parent.');
                }

                $studentCode = pcm_next_student_id($pdo);
                $parentInsertColumns = pcm_students_parent_insert_columns($pdo);
                $parentInsertColumnsSql = implode(', ', $parentInsertColumns);
                $parentInsertPlaceholders = [];
                $parentInsertParams = [];
                foreach ($parentInsertColumns as $i => $col) {
                    $ph = ':pid' . $i;
                    $parentInsertPlaceholders[] = $ph;
                    $parentInsertParams[$ph] = $parentId;
                }
                $insStudent = $pdo->prepare("
                    INSERT INTO students (student_id, student_name, dob, gender, approval_status, {$parentInsertColumnsSql}, status)
                    VALUES (:scode, :sname, :dob, :gender, 'Pending', " . implode(', ', $parentInsertPlaceholders) . ", 'Active')
                ");
                $insStudent->execute(array_merge([
                    ':scode' => $studentCode,
                    ':sname' => $childName,
                    ':dob' => ($childDob !== '' ? $childDob : null),
                    ':gender' => ($childGender !== '' ? $childGender : null),
                ], $parentInsertParams));
                $studentDbId = (int)$pdo->lastInsertId();

                $campusStored = implode(',', $campusSelection);
                $feeAmount = pcm_plan_total_for_start_term($plan, $startTerm);
                $insEnrol = $pdo->prepare("
                    INSERT INTO pcm_enrolments
                    (student_id, parent_id, fee_plan, campus_preference, start_term, fee_amount, payment_ref, proof_path, status, admin_note, reviewed_by, reviewed_at, submitted_at)
                    VALUES
                    (:sid, :pid, :plan, :campus, :start_term, :amt, :ref, NULL, 'Pending', NULL, NULL, NULL, NOW())
                ");
                $insEnrol->execute([
                    ':sid' => $studentDbId,
                    ':pid' => $parentId,
                    ':plan' => $plan,
                    ':campus' => $campusStored,
                    ':start_term' => $startTerm,
                    ':amt' => $feeAmount,
                    ':ref' => ($ref !== '' ? $ref : null)
                ]);
                $enrolmentId = (int)$pdo->lastInsertId();

                pcm_log_enrolment_event($pdo, $studentDbId, $enrolmentId, 'manual_enrolment_created', $currentActor, 'Created manually by admin.');
                $pdo->commit();

                if ($approveNow) {
                    $result = pcm_process_enrolment_decision(
                        $pdo,
                        $studentDbId,
                        'approve',
                        (string)($_SESSION['username'] ?? 'admin'),
                        'Approved during manual enrollment by admin.'
                    );
                    pcm_log_enrolment_event(
                        $pdo,
                        $studentDbId,
                        (int)($result['enrolment_id'] ?? $enrolmentId),
                        'manual_enrolment_auto_approved',
                        $currentActor,
                        'Approved immediately by admin.'
                    );
                }

                bbcc_notify_username(
                    $pdo,
                    $parentEmail,
                    'Enrollment Draft Created for ' . $childName,
                    'An enrollment record was created by admin. You can log in to Parent Portal to track status and upload payment proof if needed.',
                    'children-enrollment'
                );

                $flash = 'Manual enrollment created for <strong>' . h($childName) . '</strong> (' . h($studentCode) . ').'
                    . ($approveNow ? ' Enrollment is also approved now.' : '');
                $ok = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'assign_class') {
        $eid = (int)($_POST['enrolment_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($eid <= 0 || $classId <= 0) {
            $flash = 'Please select a valid class.';
        } else {
            $row = $pdo->prepare("
                SELECT e.id, e.student_id, e.status, s.student_name, p.email AS parent_email,
                       EXISTS(
                           SELECT 1 FROM pcm_enrolment_audit a
                           WHERE a.enrolment_id = e.id
                             AND a.event_type = 'manual_enrolment_created'
                           LIMIT 1
                       ) AS is_manual_enrolment
                FROM pcm_enrolments e
                JOIN students s ON s.id = e.student_id
                LEFT JOIN parents p ON p.id = e.parent_id
                WHERE e.id = :id LIMIT 1
            ");
            $row->execute([':id' => $eid]);
            $en = $row->fetch();
            if (!$en) {
                $flash = 'Enrolment not found.';
            } elseif (
                (int)($en['is_manual_enrolment'] ?? 0) !== 1
                && !in_array(strtolower(trim((string)($en['status'] ?? ''))), ['approved', 'pending'], true)
            ) {
                $flash = 'Class can be assigned only for approved or pending enrollments.';
            } else {
                $exist = $pdo->prepare("SELECT id FROM class_assignments WHERE student_id = :sid LIMIT 1");
                $exist->execute([':sid' => (int)$en['student_id']]);
                if ($exist->fetch()) {
                    $pdo->prepare("UPDATE class_assignments SET class_id=:cid, assigned_by=:by, assigned_at=NOW() WHERE student_id=:sid")
                        ->execute([':cid' => $classId, ':by' => $_SESSION['userid'] ?? null, ':sid' => (int)$en['student_id']]);
                } else {
                    $pdo->prepare("INSERT INTO class_assignments (class_id, student_id, assigned_by) VALUES (:cid, :sid, :by)")
                        ->execute([':cid' => $classId, ':sid' => (int)$en['student_id'], ':by' => $_SESSION['userid'] ?? null]);
                }
                $classNameStmt = $pdo->prepare("SELECT class_name FROM classes WHERE id=:id LIMIT 1");
                $classNameStmt->execute([':id' => $classId]);
                $className = (string)($classNameStmt->fetchColumn() ?: '');
                pcm_log_enrolment_event($pdo, (int)$en['student_id'], $eid, 'class_assigned', $currentActor, 'Class assigned: ' . $className);
                if (!empty($en['parent_email'])) {
                    bbcc_notify_username(
                        $pdo,
                        (string)$en['parent_email'],
                        'Class Assigned for ' . (string)$en['student_name'],
                        'A class has been assigned for your child. Please check your enrollment details.',
                        'children-enrollment'
                    );
                }

                // Assigning a class is itself an admin decision to enroll the
                // child -- auto-approve a still-Pending enrolment here so it
                // doesn't sit stuck on "Pending" even though the child is
                // already in a class and generating fee rows.
                $autoApprovedNote = '';
                if (strtolower(trim((string)($en['status'] ?? ''))) === 'pending') {
                    $decision = pcm_process_enrolment_decision($pdo, (int)$en['student_id'], 'approve', $currentActor, 'Auto-approved on class assignment.');
                    pcm_log_enrolment_event($pdo, (int)$en['student_id'], $eid, 'enrolment_approved', $currentActor, 'Auto-approved on class assignment.');
                    if (!empty($decision['parent_email'])) {
                        pcm_notify_parent_enrolment_confirmed(
                            (string)$decision['parent_email'],
                            (string)$decision['parent_name'],
                            (string)$decision['student_name']
                        );
                        bbcc_notify_username(
                            $pdo,
                            (string)$decision['parent_email'],
                            'Enrollment Approved for ' . (string)$decision['student_name'],
                            'Your child enrollment has been approved. Thank you for completing the enrollment process.',
                            'children-enrollment'
                        );
                    }
                    $autoApprovedNote = ' Enrolment also approved.';
                }

                $flash = 'Class assigned for <strong>' . h((string)$en['student_name']) . '</strong>.' . $autoApprovedNote;
                $ok = true;
            }
        }
    } else {
        $eid    = (int)($_POST['enrolment_id'] ?? 0);
        $note   = trim($_POST['admin_note'] ?? '');

        $row = $pdo->prepare("
            SELECT e.*, s.student_name, p.full_name AS parent_name, p.email AS parent_email
            FROM pcm_enrolments e
            JOIN students s ON s.id = e.student_id
            JOIN parents  p ON p.id = e.parent_id
            WHERE e.id = :id LIMIT 1
        ");
        $row->execute([':id'=>$eid]);
        $en = $row->fetch();

        if (!$en) {
            $flash = 'Enrolment not found.';
        } elseif (!in_array((string)$en['status'], ['Pending','Needs Update'], true) && $action === 'request_changes') {
            $flash = 'Request changes can be sent only for pending submissions.';
        } elseif ($en['status'] !== 'Pending' && in_array($action, ['approve','reject'], true)) {
            $flash = 'Already processed.';
        } else {
            $newStatus  = ($action === 'approve') ? 'Approved' : 'Rejected';
            $reviewer   = $_SESSION['username'] ?? 'admin';
            try {
                if ($action === 'request_changes') {
                    if ($note === '') {
                        throw new Exception('Please provide a note for requested changes.');
                    }
                    $updNeed = $pdo->prepare("
                        UPDATE pcm_enrolments
                        SET status='Needs Update', admin_note=:n, reviewed_by=:rb, reviewed_at=NOW()
                        WHERE id=:id
                    ");
                    $updNeed->execute([':n' => $note, ':rb' => $reviewer, ':id' => $eid]);
                    pcm_notify_parent_enrolment_changes_requested(
                        (string)$en['parent_email'],
                        (string)$en['parent_name'],
                        (string)$en['student_name'],
                        $note
                    );
                    bbcc_notify_username(
                        $pdo,
                        (string)$en['parent_email'],
                        'Enrollment Update Needed for ' . (string)$en['student_name'],
                        'Admin requested updates on your enrollment submission. Please review the note and resubmit.',
                        'children-enrollment'
                    );
                    pcm_log_enrolment_event($pdo, (int)$en['student_id'], (int)$en['id'], 'changes_requested', $currentActor, $note);
                    $flash = "Changes requested for <strong>{$en['student_name']}</strong>.";
                    $ok = true;
                } else {
                    $result = pcm_process_enrolment_decision($pdo, (int)$en['student_id'], $action, $reviewer, $note);

                    if ($result['new_status'] === 'Approved') {
                        pcm_notify_parent_enrolment_confirmed(
                            (string)$result['parent_email'],
                            (string)$result['parent_name'],
                            (string)$result['student_name']
                        );
                        bbcc_notify_username(
                            $pdo,
                            (string)$result['parent_email'],
                            'Enrollment Approved for ' . (string)$result['student_name'],
                            'Your child enrollment has been approved. Thank you for completing the enrollment process.',
                            'children-enrollment'
                        );
                        pcm_log_enrolment_event($pdo, (int)$en['student_id'], (int)$en['id'], 'enrolment_approved', $currentActor, $note);
                    } else {
                        pcm_notify_parent_enrolment(
                            $result['parent_email'],
                            $result['parent_name'],
                            $result['student_name'],
                            $result['new_status'],
                            $note
                        );
                        bbcc_notify_username(
                            $pdo,
                            (string)$result['parent_email'],
                            'Enrollment Rejected for ' . (string)$result['student_name'],
                            'Your enrollment submission was not approved. Please review admin notes and submit again.',
                            'children-enrollment'
                        );
                        pcm_log_enrolment_event($pdo, (int)$en['student_id'], (int)$en['id'], 'enrolment_rejected', $currentActor, $note);
                    }

                    $flash = "Enrolment <strong>{$result['new_status']}</strong> for {$result['student_name']}.";
                    $ok = true;
                }
            } catch (Exception $ex) {
                $flash = 'Error: ' . $ex->getMessage();
            }
        }
    }
}

// ── Fetch all enrolments ──
$latestClassJoin = pcm_latest_class_assignment_join('e.student_id', 'ca', 'c');
$all = $pdo->query("
    SELECT e.*, s.student_id AS stu_code, s.student_name, s.dob, s.class_option,
           p.id AS parent_db_id, p.full_name AS parent_name, p.email AS parent_email, p.phone AS parent_phone,
           COALESCE(ca.class_id, e.class_id) AS assigned_class_id,
           COALESCE(c.class_name, ec.class_name) AS assigned_class_name,
           EXISTS(
               SELECT 1 FROM pcm_enrolment_audit a
               WHERE a.enrolment_id = e.id
                 AND a.event_type = 'manual_enrolment_created'
               LIMIT 1
           ) AS is_manual_enrolment
    FROM pcm_enrolments e
    JOIN students s ON s.id = e.student_id
    JOIN parents  p ON p.id = COALESCE(NULLIF(e.parent_id,0), NULLIF(s.parent_id,0))
    {$latestClassJoin}
    LEFT JOIN classes ec ON ec.id = e.class_id AND ec.active = 1
    WHERE LOWER(COALESCE(s.status,'active')) <> 'past'
    ORDER BY FIELD(e.status,'Pending','Approved','Rejected'), e.submitted_at DESC
")->fetchAll();

// Teacher per class -- lets the enrollment table double as a class roster
// view (grouped by class) without a separate page.
$teacherByClassId = [];
try {
    $teacherRows = $pdo->query("
        SELECT
            c.id AS class_id,
            COALESCE(tt.teacher_names, tlegacy.full_name) AS teacher_name
        FROM classes c
        LEFT JOIN (
            SELECT
                cta.class_id,
                GROUP_CONCAT(DISTINCT t.full_name ORDER BY cta.is_primary DESC, t.full_name SEPARATOR ', ') AS teacher_names
            FROM class_teacher_assignments cta
            INNER JOIN teachers t ON t.id = cta.teacher_id
            GROUP BY cta.class_id
        ) tt ON tt.class_id = c.id
        LEFT JOIN teachers tlegacy ON tlegacy.id = c.teacher_id
        WHERE c.active = 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($teacherRows as $tr) {
        $teacherByClassId[(int)$tr['class_id']] = (string)($tr['teacher_name'] ?? '');
    }
} catch (Throwable $e) {
    // Teacher assignment tables are optional -- roster grouping still
    // works without teacher names if they're unavailable.
}

foreach ($all as &$row) {
    $row['assigned_teacher_name'] = $teacherByClassId[(int)($row['assigned_class_id'] ?? 0)] ?? '';
}
unset($row);

foreach ($all as $row) {
    $enrolmentParentId = (int)($row['parent_id'] ?? 0);
    $resolvedParentId = (int)($row['parent_db_id'] ?? 0);
    if ($resolvedParentId > 0 && $enrolmentParentId !== $resolvedParentId) {
        try {
            $syncParent = $pdo->prepare("UPDATE pcm_enrolments SET parent_id = :pid WHERE id = :id");
            $syncParent->execute([':pid' => $resolvedParentId, ':id' => (int)$row['id']]);
        } catch (Throwable $e) {
            error_log('[BBCC] enrolment parent sync skipped: ' . $e->getMessage());
        }
    }
}

$auditRows = [];
$allEnrolmentIds = array_map(static fn($r) => (int)($r['id'] ?? 0), $all);
$allEnrolmentIds = array_values(array_filter($allEnrolmentIds));
if (!empty($allEnrolmentIds)) {
    pcm_ensure_enrolment_audit_table($pdo);
    $in = implode(',', array_map('intval', $allEnrolmentIds));
    $auditRows = $pdo->query("
        SELECT *
        FROM pcm_enrolment_audit
        WHERE enrolment_id IN ({$in})
        ORDER BY created_at DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
$auditByEnrolment = [];
$auditForJs = [];
foreach ($auditRows as $ar) {
    $k = (int)($ar['enrolment_id'] ?? 0);
    if ($k <= 0) continue;
    $auditByEnrolment[$k][] = $ar;
    $auditForJs[$k][] = [
        'time' => date('d M Y H:i', strtotime((string)$ar['created_at'])),
        'event' => (string)$ar['event_type'],
        'actor' => (string)($ar['actor'] ?? ''),
        'details' => (string)($ar['details'] ?? ''),
    ];
}

// Counts
$total   = count($all);
$pending = count(array_filter($all, fn($r)=>$r['status']==='Pending'));
$needsUpdate = count(array_filter($all, fn($r)=>$r['status']==='Needs Update'));
$approved= count(array_filter($all, fn($r)=>$r['status']==='Approved'));
$rejected= count(array_filter($all, fn($r)=>$r['status']==='Rejected'));

// Enrolled-but-no-class vs enrolled-and-allocated -- shown as two separate
// tables below (Unallocated / Enrolled -- By Class) instead of one mixed
// list with a "Not assigned" group buried among the real classes.
$unallocated = array_values(array_filter($all, fn($r) => empty($r['assigned_class_id'])));
$allocated   = array_values(array_filter($all, fn($r) => !empty($r['assigned_class_id'])));

$registeredChildren = $pdo->query("
    SELECT s.id, s.student_id, s.student_name, s.approval_status, s.registration_date,
           p.full_name AS parent_name, p.email AS parent_email, p.phone AS parent_phone,
           e.id AS enrolment_id, e.status AS enrolment_status
    FROM students s
    LEFT JOIN parents p ON p.id = s.parent_id
    LEFT JOIN pcm_enrolments e ON e.student_id = s.id
    WHERE e.id IS NULL
      AND LOWER(COALESCE(s.status,'active')) <> 'past'
    ORDER BY s.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pageScripts = [
    "https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js",
    "https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js",
    "https://cdn.datatables.net/rowgroup/1.4.1/js/dataTables.rowGroup.min.js",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Enrolment Management</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/rowgroup/1.4.1/css/rowGroup.bootstrap4.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand:#881b12; --brand-light:#a82218; --brand-bg:#fef3f2; }
        .stat-card { border-radius:14px; overflow:hidden; border:none; transition:transform .15s; }
        .stat-card:hover { transform:translateY(-3px); }
        .stat-card.status-clickable { cursor:pointer; }
        .stat-icon { width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem; }
        .stat-number { font-size:1.8rem;font-weight:800;line-height:1; }
        .stat-label  { font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#6e7687; }
        .filter-pill { border-radius:20px !important; font-weight:600; font-size:.8rem; padding:6px 18px; border:2px solid transparent; margin-right:6px; }
        .filter-pill.active-all { background:var(--brand);color:#fff;border-color:var(--brand); }
        .filter-pill.active-pending { background:#f6c23e;color:#000;border-color:#f6c23e; }
        .filter-pill.active-needs-update { background:#36b9cc;color:#fff;border-color:#36b9cc; }
        .filter-pill.active-approved { background:#1cc88a;color:#fff;border-color:#1cc88a; }
        .filter-pill.active-rejected { background:#e74a3b;color:#fff;border-color:#e74a3b; }
        tr.class-group-row > td { background: #f8f9fc; border-top: 2px solid #e3e6f0; padding: 10px 12px; }
        tr.class-group-row .class-name { color: #881b12; font-size: .95rem; }
        .search-row { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:12px; padding:16px 20px; }
        .search-row label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#5a5c69; margin-bottom:4px; }
        .search-row .form-control { border-radius:8px; height:40px; font-size:.88rem; }
        #enrolTable thead th { font-size:.75rem; text-transform:uppercase; letter-spacing:.5px; background:#f8f9fc; border-bottom:2px solid #e3e6f0; white-space:nowrap; }
        #enrolTable td { vertical-align:middle; font-size:.88rem; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
<?php include 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid py-3">

<?php if ($flash): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    Swal.fire({icon:'<?= $ok?"success":"error" ?>',html:<?= json_encode($flash) ?>,timer:2500,showConfirmButton:false})
    <?= $ok ? ".then(()=>window.location='admin-enrolments.php')" : "" ?>;
});
</script>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">Enrollment Management</h1>
        <p class="text-muted mb-0" style="font-size:.88rem;">Review, approve, reject, or request updates on parent enrollment submissions.</p>
    </div>
    <div class="d-flex align-items-center">
        <button type="button" class="btn btn-sm btn-outline-secondary mr-2" style="border-radius:8px;" data-toggle="modal" data-target="#ageSettingsModal">
            <i class="fas fa-birthday-cake mr-1"></i> Age Eligibility
        </button>
        <button type="button" class="btn btn-sm btn-primary mr-2" style="border-radius:8px;" data-toggle="modal" data-target="#manualEnrolModal">
            <i class="fas fa-plus-circle mr-1"></i> Manual Enrollment
        </button>
        <a href="dzoClassManagement" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
            <i class="fas fa-user-plus mr-1"></i> Child Registration
        </a>
    </div>
</div>

<div class="modal fade" id="ageSettingsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_age_settings">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-birthday-cake mr-1"></i> Minimum Enrolment Age</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted" style="font-size:.85rem;">
                        A child must be at least this old, as of today, to be added by a parent or enrolled by an admin.
                    </p>
                    <div class="form-row">
                        <div class="col-6 form-group">
                            <label>Years</label>
                            <input type="number" name="min_age_years" class="form-control" min="0" max="18" required value="<?= (int)$ageSettings['years'] ?>">
                        </div>
                        <div class="col-6 form-group">
                            <label>Months</label>
                            <input type="number" name="min_age_months" class="form-control" min="0" max="11" required value="<?= (int)$ageSettings['months'] ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="manualEnrolModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="manualEnrolForm" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="manual_enrol">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Manual Enrollment (Admin)</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Parent Full Name</label>
                            <input type="text" class="form-control" name="parent_name" required maxlength="150">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Parent Email</label>
                            <input type="email" class="form-control" name="parent_email" required maxlength="150" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="parent@example.com">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Parent Phone</label>
                            <input type="text" class="form-control" name="parent_phone" required maxlength="50">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Parent Address</label>
                            <input type="text" class="form-control" name="parent_address" maxlength="255">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Child Name</label>
                            <input type="text" class="form-control" name="child_name" required maxlength="150">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Child DOB</label>
                            <input type="date" class="form-control" name="child_dob" required max="<?= pcm_max_dob_for_minimum_age() ?>">
                            <small class="form-text text-muted">Must be at least <?= h(pcm_minimum_enrolment_age_label()) ?> old.</small>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Child Gender</label>
                            <select class="form-control" name="child_gender">
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Fee Plan</label>
                            <select class="form-control" name="fee_plan" required>
                                <option value="Term-wise">Term-wise</option>
                                <option value="Half-yearly">Half-yearly</option>
                                <option value="Yearly">Yearly</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Starting Term</label>
                            <select class="form-control" name="start_term" required>
                                <option value="1">Term 1</option>
                                <option value="2">Term 2</option>
                                <option value="3">Term 3</option>
                                <option value="4">Term 4</option>
                            </select>
                        </div>
                        <div class="col-md-8 form-group">
                            <label>Payment Reference (optional)</label>
                            <input type="text" class="form-control" name="payment_ref" maxlength="150" placeholder="e.g. ChildName_TERM1">
                        </div>
                        <div class="col-md-12 form-group mb-1">
                            <label class="d-block">Campus Selection</label>
                            <div class="custom-control custom-checkbox custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="manualCampusC1" name="campus_choice[]" value="c1">
                                <label class="custom-control-label" for="manualCampusC1"><?= h($campusChoices['c1'] ?? 'Campus 1') ?></label>
                            </div>
                            <div class="custom-control custom-checkbox custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="manualCampusC2" name="campus_choice[]" value="c2">
                                <label class="custom-control-label" for="manualCampusC2"><?= h($campusChoices['c2'] ?? 'Campus 2') ?></label>
                            </div>
                            <small class="form-text text-muted">Select one or both campuses.</small>
                        </div>
                        <div class="col-md-12 form-group mb-0">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="manualApproveNow" name="manual_approve_now" value="1">
                                <label class="custom-control-label" for="manualApproveNow">Approve this enrollment immediately</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Enrollment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Unenrolled: registered children with no enrolment submitted yet -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">Unenrolled</h6>
        <small class="text-muted">Registered children with no enrolment yet -- review the registration or enroll them directly</small>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>#</th><th>Student ID</th><th>Child</th><th>Parent</th><th>Child Status</th><th>Enrollment</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($registeredChildren as $i => $rc): ?>
                    <?php
                        $enrolStatus = trim((string)($rc['enrolment_status'] ?? ''));
                        $canEnrolFromQueue = ($enrolStatus === '' || in_array($enrolStatus, ['Needs Update', 'Rejected', 'Pending'], true));
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><code><?= h((string)($rc['student_id'] ?? '')) ?></code></td>
                        <td>
                            <?= h((string)($rc['student_name'] ?? '')) ?>
                            <div class="small text-muted"><?= !empty($rc['registration_date']) ? date('d M Y', strtotime((string)$rc['registration_date'])) : '—' ?></div>
                        </td>
                        <td>
                            <?= h((string)($rc['parent_name'] ?? '—')) ?><br>
                            <small class="text-muted"><?= h((string)($rc['parent_email'] ?? '')) ?></small>
                        </td>
                        <td><span class="badge badge-<?= pcm_badge((string)($rc['approval_status'] ?? 'Pending')) ?>"><?= h((string)($rc['approval_status'] ?? 'Pending')) ?></span></td>
                        <td>
                            <?php if ($enrolStatus !== ''): ?>
                                <span class="badge badge-<?= pcm_badge($enrolStatus) ?>"><?= h($enrolStatus) ?></span>
                            <?php else: ?>
                                <span class="text-muted">Not created</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button
                                type="button"
                                class="btn btn-sm btn-primary js-enrol-registered-btn"
                                data-student-id="<?= (int)$rc['id'] ?>"
                                data-student-name="<?= h((string)($rc['student_name'] ?? '')) ?>"
                                <?= $canEnrolFromQueue ? '' : 'disabled' ?>
                            >
                                <i class="fas fa-file-signature mr-1"></i> Enroll
                            </button>
                            <?php if (strtolower((string)($rc['approval_status'] ?? '')) !== 'rejected'): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger js-reject-reg-btn" data-student-id="<?= (int)$rc['id'] ?>" data-student-name="<?= h((string)($rc['student_name'] ?? '')) ?>">
                                <i class="fas fa-times mr-1"></i> Reject
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($registeredChildren)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No registered children found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Unallocated: enrolled, but no class assigned yet -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">Unallocated <span class="badge badge-warning ml-1"><?= count($unallocated) ?></span></h6>
        <small class="text-muted">Enrolled, but not yet assigned to a class</small>
    </div>
    <div class="card-body">
        <?php if (empty($unallocated)): ?>
            <p class="text-muted mb-0">Everyone enrolled has a class assigned.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table id="unallocatedTable" class="table table-bordered table-hover" style="width:100%">
                <thead class="thead-light">
                    <tr>
                        <th>#</th><th>Student ID</th><th>Child Name</th><th>Campus</th><th>Class</th><th>Teacher</th><th>Phone</th>
                        <th>Plan</th><th>Amount</th><th>Ref</th><th>Proof</th><th>Parent</th><th>Status</th><th>Submitted</th><th style="width:300px">Actions</th><th>History</th>
                    </tr>
                </thead>
                <tbody>
                <?php bbcc_render_enrolment_rows($unallocated, $campusChoices, $allClasses, $auditByEnrolment); ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="all">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(136,27,18,.1);color:var(--brand);"><i class="fas fa-users"></i></div>
                <div><div class="stat-number text-gray-800"><?= $total ?></div><div class="stat-label">Total</div></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Pending">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(246,194,62,.15);color:#f6c23e;"><i class="fas fa-clock"></i></div>
                <div><div class="stat-number text-gray-800"><?= $pending ?></div><div class="stat-label">Pending Review</div></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Needs Update">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(54,185,204,.15);color:#36b9cc;"><i class="fas fa-edit"></i></div>
                <div><div class="stat-number text-gray-800"><?= $needsUpdate ?></div><div class="stat-label">Needs Update</div></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Approved">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(28,200,138,.12);color:#1cc88a;"><i class="fas fa-check-circle"></i></div>
                <div><div class="stat-number text-gray-800"><?= $approved ?></div><div class="stat-label">Approved</div></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Rejected">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(231,74,59,.1);color:#e74a3b;"><i class="fas fa-times-circle"></i></div>
                <div><div class="stat-number text-gray-800"><?= $rejected ?></div><div class="stat-label">Rejected</div></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter + Search -->
<div class="search-row mb-4">
    <div class="d-flex flex-wrap align-items-center mb-3">
        <button class="btn filter-pill active-all" data-filter="all">All</button>
        <button class="btn filter-pill btn-outline-warning" data-filter="Pending"><i class="fas fa-clock mr-1"></i> Pending</button>
        <button class="btn filter-pill btn-outline-info" data-filter="Needs Update"><i class="fas fa-edit mr-1"></i> Needs Update</button>
        <button class="btn filter-pill btn-outline-success" data-filter="Approved"><i class="fas fa-check mr-1"></i> Approved</button>
        <button class="btn filter-pill btn-outline-danger" data-filter="Rejected"><i class="fas fa-times mr-1"></i> Rejected</button>
    </div>
    <div class="row">
        <div class="col-md-3">
            <label>Search Column</label>
            <select class="form-control" id="colSelect">
                <option value="-1">All Columns</option>
                <option value="1">Student ID</option>
                <option value="2">Child Name</option>
                <option value="3">Campus</option>
                <option value="4">Class</option>
                <option value="5">Teacher</option>
                <option value="6">Phone</option>
                <option value="7">Plan</option>
                <option value="11">Parent</option>
                <option value="12">Status</option>
            </select>
        </div>
        <div class="col-md-6">
            <label>Quick Search</label>
            <input type="text" class="form-control" id="searchBox" placeholder="Type to search instantly...">
        </div>
        <div class="col-md-3">
            <label>&nbsp;</label>
            <button class="btn btn-outline-secondary btn-block" id="resetBtn" style="border-radius:8px;height:40px;">
                <i class="fas fa-undo mr-1"></i> Reset
            </button>
        </div>
    </div>
</div>

<!-- Enrolled -- By Class -->
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Enrolled — By Class <span class="badge badge-success ml-1"><?= count($allocated) ?></span></h6>
        <small class="text-muted">Grouped by class -- use "Update Class" on any row to move a student</small>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="enrolTable" class="table table-bordered table-hover" style="width:100%">
                <thead class="thead-light">
                    <tr>
                        <th>#</th><th>Student ID</th><th>Child Name</th><th>Campus</th><th>Class</th><th>Teacher</th><th>Phone</th>
                        <th>Plan</th><th>Amount</th><th>Ref</th><th>Proof</th><th>Parent</th><th>Status</th><th>Submitted</th><th style="width:300px">Actions</th><th>History</th>
                    </tr>
                </thead>
                <tbody>
                <?php bbcc_render_enrolment_rows($allocated, $campusChoices, $allClasses, $auditByEnrolment); ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


</div>
</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>

<!-- Shared Approve/Reject/Request Changes/History modals (populated via JS -- see script below) -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST" class="js-enrol-action-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="enrolment_id" id="approveEnrolmentId" value="">
            <div class="modal-header bg-success text-white"><h5 class="modal-title">Approve Enrolment</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
                <p>Approve enrolment for <strong id="approveStudentName"></strong>?</p>
                <p class="small text-muted">This will also create fee instalment records and approve the first payment if proof is attached.</p>
                <div class="form-group"><label>Note (optional)</label><textarea name="admin_note" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success js-submit-action-btn">Approve</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST" class="js-enrol-action-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="enrolment_id" id="rejectEnrolmentId" value="">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title">Reject Enrolment</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
                <p>Reject enrolment for <strong id="rejectStudentName"></strong>?</p>
                <div class="form-group"><label>Reason / Note</label><textarea name="admin_note" class="form-control" rows="2" required></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger js-submit-action-btn">Reject</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="changesModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST" class="js-enrol-action-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_changes">
            <input type="hidden" name="enrolment_id" id="changesEnrolmentId" value="">
            <div class="modal-header bg-warning text-dark"><h5 class="modal-title">Request Changes</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
                <p>Ask parent to update submission for <strong id="changesStudentName"></strong>.</p>
                <div class="form-group"><label>Required update note</label><textarea name="admin_note" class="form-control" rows="2" required placeholder="e.g. Please upload clearer payment proof"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning js-submit-action-btn">Send Request</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Audit History — <span id="historyStudentName"></span></h5>
            <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
            <div id="historyEmptyMsg" class="text-muted mb-0" style="display:none;">No audit history yet.</div>
            <div id="historyTableWrap" class="table-responsive" style="display:none;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="thead-light">
                        <tr><th style="width:170px">Time</th><th style="width:170px">Event</th><th style="width:160px">Actor</th><th>Details</th></tr>
                    </thead>
                    <tbody id="historyTableBody"></tbody>
                </table>
            </div>
        </div>
    </div></div>
</div>

<div class="modal fade" id="rejectRegModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject_registration">
            <input type="hidden" name="student_id" id="rejectRegStudentId" value="">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title">Reject Registration</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
                <p>Reject registration for <strong id="rejectRegStudentName"></strong>? This does not create or affect any enrollment.</p>
                <div class="form-group"><label>Reason / Note</label><textarea name="admin_note" class="form-control" rows="2" required></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Reject</button></div>
        </form>
    </div></div>
</div>

<script>
var bbccEnrolHistory = <?= json_encode($auditForJs, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
</script>

<div class="modal fade" id="registeredChildEnrolModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" class="js-enrol-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="enrol_registered_child">
                <input type="hidden" name="student_id" id="rcStudentId" value="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Enroll Registered Child</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Create enrollment for <strong id="rcStudentName">this child</strong>.</p>
                    <div class="form-group">
                        <label>Fee Plan</label>
                        <select class="form-control" name="fee_plan" required>
                            <option value="Term-wise">Term-wise</option>
                            <option value="Half-yearly">Half-yearly</option>
                            <option value="Yearly">Yearly</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Starting Term</label>
                        <select class="form-control" name="start_term" required>
                            <option value="1">Term 1</option>
                            <option value="2">Term 2</option>
                            <option value="3">Term 3</option>
                            <option value="4">Term 4</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Payment Reference (optional)</label>
                        <input type="text" class="form-control" name="payment_ref" maxlength="150">
                    </div>
                    <div class="form-group mb-2">
                        <label class="d-block">Campus Selection</label>
                        <div class="custom-control custom-checkbox custom-control-inline">
                            <input type="checkbox" class="custom-control-input" id="rcCampusC1" name="campus_choice[]" value="c1">
                            <label class="custom-control-label" for="rcCampusC1"><?= h($campusChoices['c1'] ?? 'Campus 1') ?></label>
                        </div>
                        <div class="custom-control custom-checkbox custom-control-inline">
                            <input type="checkbox" class="custom-control-input" id="rcCampusC2" name="campus_choice[]" value="c2">
                            <label class="custom-control-label" for="rcCampusC2"><?= h($campusChoices['c2'] ?? 'Campus 2') ?></label>
                        </div>
                    </div>
                    <div class="custom-control custom-checkbox mt-3">
                        <input type="checkbox" class="custom-control-input" id="rcApproveNow" name="approve_now" value="1">
                        <label class="custom-control-label" for="rcApproveNow">Approve immediately</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary js-submit-action-btn">Create Enrollment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment Proof</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body text-center">
                <img id="proofImage" src="" alt="Payment Proof" style="max-width:100%;height:auto;display:none;">
                <iframe id="proofFrame" src="" style="width:100%;height:65vh;border:0;display:none;"></iframe>
                <div id="proofFallback" style="display:none;">
                    <p class="mb-2">Preview not available for this file type.</p>
                    <a id="proofOpenLink" href="#" target="_blank" class="btn btn-primary btn-sm">Open File</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(function(){
    var dt = $('#enrolTable').DataTable({
        pageLength: 25,
        order: [[4, 'asc'], [13, 'desc']],
        columnDefs: [
            { orderable: false, targets: 0 },
            { visible: false, targets: [4, 5] }
        ],
        rowGroup: {
            dataSrc: 4,
            startRender: function(rows, group) {
                var d = rows.data()[0];
                var teacher = d[5] || '';
                var count = rows.count();
                var $tr = $('<tr class="class-group-row"/>');
                var $td = $('<td colspan="14"/>').appendTo($tr);
                var $wrap = $('<div class="d-flex flex-wrap align-items-center justify-content-between"/>').appendTo($td);
                var $left = $('<div/>').appendTo($wrap);
                $('<strong class="class-name"/>').text(group || 'Not assigned').appendTo($left);
                if (teacher) {
                    $left.append(' ');
                    $('<i class="fas fa-chalkboard-teacher text-muted small ml-2 mr-1"></i>').appendTo($left);
                    $('<span class="small text-muted"/>').text(teacher).appendTo($left);
                }
                var $right = $('<div/>').appendTo($wrap);
                $('<span class="badge badge-info"/>').text(count + (count === 1 ? ' student' : ' students')).appendTo($right);
                return $tr;
            }
        }
    });

    if ($('#unallocatedTable').length) {
        $('#unallocatedTable').DataTable({
            pageLength: 10,
            order: [[13, 'desc']],
            columnDefs: [
                { orderable: false, targets: 0 },
                { visible: false, targets: [4, 5] }
            ]
        });
    }

    var activeStatus = 'all';
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
        if (settings.nTable.id !== 'enrolTable') return true;
        if (activeStatus === 'all') return true;
        var rowNode = settings.aoData[dataIndex] && settings.aoData[dataIndex].nTr ? settings.aoData[dataIndex].nTr : null;
        if (!rowNode) return true;
        var rowStatus = String($(rowNode).attr('data-status') || '').trim();
        return rowStatus === activeStatus;
    });

    function statusClassKey(status) {
        var s = String(status || '').toLowerCase();
        if (s === 'needs update') return 'needs-update';
        return s || 'all';
    }

    function setPillActive(status) {
        $('.filter-pill').removeClass('active-all active-pending active-needs-update active-approved active-rejected');
        $('.filter-pill').each(function(){
            if ($(this).data('filter') === status) {
                $(this).addClass('active-' + statusClassKey(status));
            }
        });
    }

    function applyStatusFilter(status) {
        activeStatus = status;
        dt.draw();
        setPillActive(status);
    }

    $('.filter-pill').on('click', function(){
        applyStatusFilter($(this).data('filter'));
    });

    $('.js-status-card').on('click', function(){
        applyStatusFilter($(this).data('status'));
    });

    $('#searchBox').on('keyup', function(){
        var term = this.value;
        var col = parseInt($('#colSelect').val(), 10);
        if (col === -1) {
            dt.search(term).draw();
        } else {
            dt.search('');
            dt.columns().search('');
            dt.column(col).search(term).draw();
        }
    });

    $('#colSelect').on('change', function(){
        $('#searchBox').trigger('keyup');
    });

    $('#resetBtn').on('click', function(){
        $('#searchBox').val('');
        $('#colSelect').val('-1');
        dt.search('');
        dt.columns().search('');
        applyStatusFilter('all');
    });

    applyStatusFilter('all');

    // Close approve/reject modal immediately on submit so UI does not appear stuck
    $(document).on('submit', 'form.js-enrol-action-form', function(){
        var $form = $(this);
        var $btn = $form.find('.js-submit-action-btn');
        $btn.prop('disabled', true).text('Processing...');
        $form.closest('.modal').modal('hide');
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open').css('padding-right', '');
    });

    // Proof popup modal
    $(document).on('click', '.js-proof-btn', function(){
        var path = $(this).data('proof') || '';
        var child = $(this).data('child') || 'Student';
        var lower = String(path).toLowerCase();
        var isImg = /\.(jpg|jpeg|png|gif|webp)$/i.test(lower);
        var isPdf = /\.pdf$/i.test(lower);

        $('#proofModal .modal-title').text('Payment Proof - ' + child);
        $('#proofImage, #proofFrame, #proofFallback').hide();
        $('#proofImage').attr('src', '');
        $('#proofFrame').attr('src', '');
        $('#proofOpenLink').attr('href', path);

        if (isImg) {
            $('#proofImage').attr('src', path).show();
        } else if (isPdf) {
            $('#proofFrame').attr('src', path).show();
        } else {
            $('#proofFallback').show();
        }
        $('#proofModal').modal('show');
    });

    $('#manualEnrolModal').on('shown.bs.modal', function(){
        var form = document.getElementById('manualEnrolForm');
        if (!form) return;
        form.reset();
        var emailInput = form.querySelector('input[name="parent_email"]');
        if (emailInput) emailInput.value = '';
    });

    $(document).on('click', '.js-enrol-registered-btn', function(){
        var sid = $(this).data('student-id') || '';
        var sname = $(this).data('student-name') || 'this child';
        var $modal = $('#registeredChildEnrolModal');
        var form = $modal.find('form')[0];
        if (form) form.reset();
        $('#rcStudentId').val(sid);
        $('#rcStudentName').text(sname);
        $modal.modal('show');
    });

    $(document).on('click', '.js-approve-enrol-btn', function(){
        var $form = $('#approveModal').find('form')[0];
        if ($form) $form.reset();
        $('#approveEnrolmentId').val($(this).data('enrolment-id'));
        $('#approveStudentName').text($(this).data('student-name') || '');
        $('#approveModal').modal('show');
    });

    $(document).on('click', '.js-reject-enrol-btn', function(){
        var $form = $('#rejectModal').find('form')[0];
        if ($form) $form.reset();
        $('#rejectEnrolmentId').val($(this).data('enrolment-id'));
        $('#rejectStudentName').text($(this).data('student-name') || '');
        $('#rejectModal').modal('show');
    });

    $(document).on('click', '.js-changes-enrol-btn', function(){
        var $form = $('#changesModal').find('form')[0];
        if ($form) $form.reset();
        $('#changesEnrolmentId').val($(this).data('enrolment-id'));
        $('#changesStudentName').text($(this).data('student-name') || '');
        $('#changesModal').modal('show');
    });

    $(document).on('click', '.js-reject-reg-btn', function(){
        $('#rejectRegStudentId').val($(this).data('student-id'));
        $('#rejectRegStudentName').text($(this).data('student-name') || '');
        $('#rejectRegModal').modal('show');
    });

    $(document).on('click', '.js-history-btn', function(){
        var eid = $(this).data('enrolment-id');
        var sname = $(this).data('student-name') || '';
        var items = bbccEnrolHistory[eid] || [];
        $('#historyStudentName').text(sname);
        var $body = $('#historyTableBody').empty();
        if (items.length === 0) {
            $('#historyEmptyMsg').show();
            $('#historyTableWrap').hide();
        } else {
            items.forEach(function(item){
                var $tr = $('<tr/>');
                $('<td/>').text(item.time).appendTo($tr);
                $('<td/>').text(item.event).appendTo($tr);
                $('<td/>').text(item.actor).appendTo($tr);
                $('<td/>').text(item.details).appendTo($tr);
                $body.append($tr);
            });
            $('#historyEmptyMsg').hide();
            $('#historyTableWrap').show();
        }
        $('#historyModal').modal('show');
    });
});
</script>
</body>
</html>
