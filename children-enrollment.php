<?php
// children-enrollment.php — Unified: Complete Enrollment + Bank Details + Fee Plans
// Redesigned for international standards: single-page, minimal clicks, smooth UX
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_parent_role()) { header("Location: unauthorized"); exit; }

$pdo      = pcm_pdo();
$campusChoices = pcm_campus_choice_labels();
[$campusOneName, $campusTwoName] = pcm_campus_names();
pcm_ensure_enrolment_campus_preference($pdo);
$parent   = pcm_current_parent($pdo);
if (!$parent) die("Parent account not found.");
$parentId = (int)$parent['id'];
$flash    = '';
$ok       = false;

// ── Bank details from fees_settings (single source of truth) ──
$_fs = $pdo->query("SELECT bank_name, account_name, bsb, account_number, bank_notes FROM fees_settings WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$banks = (!empty($_fs['bank_name'])) ? [[
    'bank_name'      => $_fs['bank_name'],
    'account_name'   => $_fs['account_name'],
    'bsb'            => $_fs['bsb'],
    'account_number' => $_fs['account_number'],
    'reference_hint' => $_fs['bank_notes'],
]] : [];

// ── POST ACTIONS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $act = $_POST['action'];

    // --- Add Child ---
    if ($act === 'add_child') {
        header("Location: parent-children");
        exit;
    }

    // --- Remove Child ---
    if ($act === 'remove_child') {
        header("Location: parent-children");
        exit;
    }

    // --- Submit Enrolment ---
    if ($act === 'enrol') {
        $childId = (int)($_POST['child_id'] ?? 0);
        $campusSelection = $_POST['campus_choice'] ?? [];
        if (!is_array($campusSelection)) {
            $campusSelection = [];
        }
        $campusSelection = array_values(array_unique(array_filter(array_map('strval', $campusSelection))));
        $allowedCampusChoices = array_keys($campusChoices);
        $plan    = trim($_POST['fee_plan'] ?? '');
        $ref     = trim($_POST['payment_ref'] ?? '');

        $chk = $pdo->prepare("SELECT id, student_name FROM students WHERE id=:id AND parentId=:pid LIMIT 1");
        $chk->execute([':id'=>$childId, ':pid'=>$parentId]);
        $child = $chk->fetch();

        if (!$child) {
            $flash = 'Invalid child selected.';
        } elseif (empty($campusSelection)) {
            $flash = 'Please select at least one campus.';
        } elseif (array_diff($campusSelection, $allowedCampusChoices)) {
            $flash = 'Please select valid campus choices.';
        } elseif ($ref === '') {
            $flash = 'Please provide a payment reference.';
        } elseif (!in_array($plan, ['Term-wise','Half-yearly','Yearly'])) {
            $flash = 'Invalid fee plan.';
        } else {
            $existingEnrol = $pdo->prepare("SELECT id, status FROM pcm_enrolments WHERE student_id=:id LIMIT 1");
            $existingEnrol->execute([':id'=>$childId]);
            $existingEnrol = $existingEnrol->fetch(PDO::FETCH_ASSOC);

            if ($existingEnrol && strtolower((string)($existingEnrol['status'] ?? '')) !== 'needs update') {
                $flash = 'This child already has an enrolment on file.';
            } else {
                $amount = pcm_plan_amount($plan);
                $proofPath = null;

                if (empty($_FILES['proof']['name']) || (int)($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $flash = 'Payment proof is required.';
                } else {
                    $allowed = ['jpg','jpeg','png','pdf'];
                    $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed)) {
                        $flash = 'Proof must be JPG, PNG, or PDF.';
                    } elseif ($_FILES['proof']['size'] > 5 * 1024 * 1024) {
                        $flash = 'File must be under 5 MB.';
                    } else {
                        $dir = 'uploads/enrolments';
                        pcm_ensure_dir($dir);
                        $filename  = 'enrol_' . $childId . '_' . time() . '.' . $ext;
                        $proofPath = $dir . '/' . $filename;
                        if (!move_uploaded_file($_FILES['proof']['tmp_name'], $proofPath)) {
                            $flash = 'Failed to upload file.';
                            $proofPath = null;
                        }
                    }
                }

                if ($flash === '') {
                    $campusStored = implode(',', $campusSelection);
                    $actor = (string)($_SESSION['username'] ?? $parent['full_name'] ?? 'parent');
                    if ($existingEnrol) {
                        $eid = (int)$existingEnrol['id'];
                        $upd = $pdo->prepare("
                            UPDATE pcm_enrolments
                            SET fee_plan=:plan, campus_preference=:campus, fee_amount=:amt, payment_ref=:ref, proof_path=:proof,
                                status='Pending', admin_note=NULL, reviewed_by=NULL, reviewed_at=NULL, submitted_at=NOW()
                            WHERE id=:id
                        ");
                        $upd->execute([
                            ':plan' => $plan,
                            ':campus' => $campusStored,
                            ':amt' => $amount,
                            ':ref' => $ref ?: null,
                            ':proof' => $proofPath,
                            ':id' => $eid
                        ]);
                        pcm_log_enrolment_event($pdo, $childId, $eid, 'enrolment_resubmitted', $actor, 'Parent resubmitted enrollment after requested changes.');
                    } else {
                        $ins = $pdo->prepare("INSERT INTO pcm_enrolments (student_id, parent_id, fee_plan, campus_preference, fee_amount, payment_ref, proof_path) VALUES (:sid, :pid, :plan, :campus, :amt, :ref, :proof)");
                        $ins->execute([':sid'=>$childId, ':pid'=>$parentId, ':plan'=>$plan, ':campus'=>$campusStored, ':amt'=>$amount, ':ref'=>$ref?:null, ':proof'=>$proofPath]);
                        $eid = (int)$pdo->lastInsertId();
                        pcm_log_enrolment_event($pdo, $childId, $eid, 'enrolment_submitted', $actor, 'Parent submitted enrollment.');
                    }

                    pcm_notify_admin_enrolment($child['student_name'], $parent['full_name']);
                    $flash = "Enrollment submitted for <strong>{$child['student_name']}</strong>. You will be notified once reviewed.";
                    $ok = true;
                }
            }
        }
    }
}

// ── Fetch all children ──
$children = $pdo->prepare("SELECT * FROM students WHERE parentId = :pid ORDER BY id DESC");
$children->execute([':pid'=>$parentId]);
$children = $children->fetchAll();

// ── Children eligible for enrolment completion (new OR needs update) ──
$eligible = $pdo->prepare("
    SELECT s.id, s.student_id, s.student_name, e.status AS enrol_status
    FROM students s
    LEFT JOIN pcm_enrolments e ON e.student_id = s.id
    WHERE s.parentId = :pid
      AND LOWER(COALESCE(s.approval_status,'pending')) = 'approved'
      AND (e.id IS NULL OR LOWER(COALESCE(e.status,'')) = 'needs update')
    ORDER BY s.student_name
");
$eligible->execute([':pid'=>$parentId]);
$eligible = $eligible->fetchAll();

// ── Existing enrolments ──
$enrolments = $pdo->prepare("
    SELECT e.*, s.student_id AS stu_code, s.student_name, c.class_name AS assigned_class_name
    FROM pcm_enrolments e
    JOIN students s ON s.id = e.student_id
    LEFT JOIN class_assignments ca ON ca.student_id = s.id
    LEFT JOIN classes c ON c.id = ca.class_id
    WHERE e.parent_id = :pid
    ORDER BY e.submitted_at DESC
");
$enrolments->execute([':pid'=>$parentId]);
$enrolments = $enrolments->fetchAll();

$childCount = count($children);
$enrolCount = count($enrolments);
$eligibleCount = count($eligible);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Enrolment — Parent Portal</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand:#881b12; --brand-light:#a82218; }

        /* Summary Cards */
        .summary-card {
            border-radius: 12px;
            padding: 18px 20px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform .2s, box-shadow .2s;
        }
        .summary-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.15); }
        .summary-card .icon { font-size: 1.8rem; opacity: 0.8; }
        .summary-card .info { line-height: 1.3; }
        .summary-card .info .count { font-size: 1.5rem; font-weight: 700; }
        .summary-card .info .label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: .5px; opacity: 0.85; }

        .bg-grad-primary { background: linear-gradient(135deg, #4e73df, #224abe); }
        .bg-grad-success { background: linear-gradient(135deg, #1cc88a, #17a673); }
        .bg-grad-warning { background: linear-gradient(135deg, #f6c23e, #dda20a); }
        .bg-grad-info { background: linear-gradient(135deg, #36b9cc, #258391); }

        /* Section */
        .section-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #5a5c69;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin: 28px 0 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e3e6f0;
        }
        .section-title i { color: #4e73df; margin-right: 8px; }

        /* Bank Details Box */
        .bank-panel {
            background: linear-gradient(135deg, #f8f9fc, #eaecf4);
            border: 1px solid #d1d3e2;
            border-radius: 12px;
            padding: 20px;
        }
        .bank-item {
            background: #fff;
            border-radius: 10px;
            padding: 14px 16px;
            border: 1px solid #e3e6f0;
            margin-bottom: 10px;
        }
        .bank-item:last-child { margin-bottom: 0; }
        .bank-label { font-size: 0.68rem; text-transform: uppercase; color: #858796; font-weight: 700; letter-spacing: .5px; }
        .bank-value { font-size: 0.92rem; font-weight: 600; color: #2d3436; }
        .copy-btn { cursor: pointer; color: #4e73df; font-size: 0.78rem; }
        .copy-btn:hover { color: #224abe; }

        /* Fee Plan Cards */
        .plan-card {
            border: 2px solid #e3e6f0;
            border-radius: 12px;
            padding: 18px;
            text-align: center;
            cursor: pointer;
            transition: all .2s;
            position: relative;
        }
        .plan-card:hover { border-color: #4e73df; background: #f8f9ff; }
        .plan-card.selected { border-color: var(--brand); background: #fef3f2; box-shadow: 0 0 0 3px rgba(136,27,18,0.15); }
        .plan-card .plan-name { font-weight: 700; font-size: 0.95rem; color: #2d3436; }
        .plan-card .plan-price { font-size: 1.5rem; font-weight: 800; color: var(--brand); margin: 6px 0; }
        .plan-card .plan-detail { font-size: 0.75rem; color: #858796; }
        .plan-card .check-mark {
            position: absolute; top: 10px; right: 10px;
            width: 22px; height: 22px; border-radius: 50%;
            background: var(--brand); color: #fff; font-size: 0.7rem;
            display: none; align-items: center; justify-content: center;
        }
        .plan-card.selected .check-mark { display: flex; }

        /* Enrolment status card */
        .enrol-card {
            border-radius: 12px;
            border: 1px solid #e3e6f0;
            transition: all .2s;
        }
        .enrol-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }

        /* Children table improved */
        .child-row { transition: background .15s; }
        .child-row:hover { background: #f8f9fc; }

        /* Quick add child inline form */
        .quick-add-form {
            background: #f8f9fc;
            border: 1px dashed #d1d3e2;
            border-radius: 12px;
            padding: 18px 20px;
        }

        /* Toast style */
        .mini-label { font-size: 0.7rem; text-transform: uppercase; color: #858796; font-weight: 700; letter-spacing: .3px; }

        .badge-status { font-size: 0.72rem; padding: 4px 10px; border-radius: 20px; }

        /* Smooth collapse */
        .collapse-section { overflow: hidden; transition: max-height .4s ease; }

        /* Mobile */
        @media (max-width: 768px) {
            .plan-card { padding: 14px 10px; }
            .plan-card .plan-price { font-size: 1.2rem; }
            .summary-card { padding: 14px 16px; }
            .summary-card .icon { font-size: 1.4rem; }
            .summary-card .info .count { font-size: 1.2rem; }
        }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
<?php include 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid py-3">

<!-- Flash Message -->
<?php if ($flash): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    Swal.fire({icon:'<?= $ok?"success":"error" ?>',html:<?= json_encode($flash) ?>,timer:2500,showConfirmButton:true,confirmButtonColor:'#881b12'})
    <?= $ok ? ".then(()=>window.location='children-enrollment.php')" : "" ?>;
});
</script>
<?php endif; ?>

<!-- ═══ SUMMARY CARDS ═══ -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="summary-card bg-grad-primary shadow">
            <div class="icon"><i class="fas fa-child"></i></div>
            <div class="info">
                <div class="count"><?= $childCount ?></div>
                <div class="label">Children</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="summary-card bg-grad-warning shadow">
            <div class="icon"><i class="fas fa-user-plus"></i></div>
            <div class="info">
                <div class="count"><?= $eligibleCount ?></div>
                <div class="label">Ready to Enrol</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="summary-card bg-grad-success shadow">
            <div class="icon"><i class="fas fa-file-signature"></i></div>
            <div class="info">
                <div class="count"><?= $enrolCount ?></div>
                <div class="label">Enrolments</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="summary-card bg-grad-info shadow">
            <div class="icon"><i class="fas fa-university"></i></div>
            <div class="info">
                <div class="count"><?= count($banks) ?></div>
                <div class="label">Payment Methods</div>
            </div>
        </div>
    </div>
</div>

<div class="row">

<!-- ═══ Single Column: Enrolment Form + Enrolments ═══ -->
<div class="col-12">

    <?php if (false): ?>
    <!-- SECTION: My Children -->
    <div class="section-title"><i class="fas fa-child"></i>My Children</div>

    <?php if (!empty($children)): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th style="width:30px">#</th>
                            <th>Name</th>
                            <th>Student ID</th>
                            <th>DOB</th>
                            <th>Status</th>
                            <th>Enrolled?</th>
                            <th style="width:50px"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($children as $i => $c):
                        $hasEnrol = $pdo->prepare("SELECT 1 FROM pcm_enrolments WHERE student_id=:id LIMIT 1");
                        $hasEnrol->execute([':id'=>$c['id']]);
                        $enrolled = (bool)$hasEnrol->fetch();
                    ?>
                        <tr class="child-row">
                            <td class="text-muted"><?= $i+1 ?></td>
                            <td class="font-weight-bold"><?= h($c['student_name']) ?></td>
                            <td><code class="text-primary"><?= h($c['student_id']) ?></code></td>
                            <td><?= $c['dob'] ? date('d M Y', strtotime($c['dob'])) : '<span class="text-muted">—</span>' ?></td>
                            <td><span class="badge badge-<?= pcm_badge($c['approval_status'] ?? 'Pending') ?> badge-status"><?= h($c['approval_status'] ?? 'Pending') ?></span></td>
                            <td>
                                <?php if ($enrolled): ?>
                                    <span class="text-success"><i class="fas fa-check-circle"></i> Yes</span>
                                <?php else: ?>
                                    <span class="text-muted"><i class="far fa-circle"></i> No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (strtolower($c['approval_status'] ?? '') === 'pending' && !$enrolled): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove this child?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_child">
                                    <input type="hidden" name="child_id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" title="Remove"><i class="fas fa-trash-alt"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Add Child -->
    <div class="quick-add-form mb-4" id="addChildSection">
        <div class="d-flex align-items-center mb-2">
            <i class="fas fa-plus-circle text-primary mr-2"></i>
            <strong style="font-size:0.88rem;">Add a Child</strong>
        </div>
        <form method="POST" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_child">
            <div class="col-md-3">
                <label class="mini-label"><i class="fas fa-user mr-1" style="color:var(--brand);font-size:0.6rem;"></i> Child's Full Name *</label>
                <input type="text" name="child_name" class="form-control form-control-sm" required maxlength="150" placeholder="e.g. Karma Dorji">
            </div>
            <div class="col-md-2">
                <label class="mini-label"><i class="fas fa-calendar mr-1" style="color:var(--brand);font-size:0.6rem;"></i> Date of Birth *</label>
                <input type="date" name="dob" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-2">
                <label class="mini-label"><i class="fas fa-venus-mars mr-1" style="color:var(--brand);font-size:0.6rem;"></i> Gender *</label>
                <select name="child_gender" class="form-control form-control-sm" required>
                    <option value="">— Select —</option>
                    <option>Male</option>
                    <option>Female</option>
                    <option>Other</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="mini-label"><i class="fas fa-heartbeat mr-1" style="color:var(--brand);font-size:0.6rem;"></i> Medical Issues *</label>
                <input type="text" name="medical" class="form-control form-control-sm" required maxlength="500" placeholder="None if no issues">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm btn-block"><i class="fas fa-user-plus mr-1"></i>Add</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- SECTION: Enrol a Child -->
    <?php if (!empty($eligible)): ?>
    <div class="section-title"><i class="fas fa-file-signature"></i>Complete Enrollment</div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" id="enrolForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="enrol">
                <input type="hidden" name="fee_plan" id="selectedPlan" value="">

                <!-- Step 1: Select Child -->
                <div class="mb-4">
                    <label class="font-weight-bold mb-2"><i class="fas fa-child mr-1 text-primary"></i>Select Child</label>
                    <select name="child_id" class="form-control" required id="childSelect">
                            <option value="">— Choose an approved child —</option>
                        <?php foreach ($eligible as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= h($c['student_name']) ?> (<?= h($c['student_id']) ?>)<?= (strtolower((string)($c['enrol_status'] ?? '')) === 'needs update') ? ' — update requested' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Step 2: Select Campus -->
                <div class="mb-4" id="campusSection" style="display:none;">
                    <label class="font-weight-bold mb-2"><i class="fas fa-school mr-1 text-primary"></i>Select Campus</label>
                    <div class="custom-control custom-checkbox mb-1">
                        <input type="checkbox" class="custom-control-input campus-choice" id="campusC1" name="campus_choice[]" value="c1">
                        <label class="custom-control-label" for="campusC1"><?= h($campusOneName) ?></label>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input campus-choice" id="campusC2" name="campus_choice[]" value="c2">
                        <label class="custom-control-label" for="campusC2"><?= h($campusTwoName) ?></label>
                    </div>
                    <small class="text-muted">You can select one campus or both campuses.</small>
                </div>

                <!-- Step 3: Choose Fee Plan -->
                <div class="mb-4" id="planSection" style="display:none;">
                    <label class="font-weight-bold mb-2"><i class="fas fa-tags mr-1 text-primary"></i>Choose Fee Plan</label>
                    <div class="row" id="planCards">
                        <div class="col-md-4 mb-2">
                            <div class="plan-card" data-plan="Term-wise" data-amount="65">
                                <div class="check-mark"><i class="fas fa-check"></i></div>
                                <div class="plan-name">Term-wise</div>
                                <div class="plan-price">$65</div>
                                <div class="plan-detail">Per term (4 terms/year)</div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="plan-card" data-plan="Half-yearly" data-amount="125">
                                <div class="check-mark"><i class="fas fa-check"></i></div>
                                <div class="plan-name">Half-yearly</div>
                                <div class="plan-price">$125</div>
                                <div class="plan-detail">Per half (2 payments/year)</div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="plan-card" data-plan="Yearly" data-amount="250">
                                <div class="check-mark"><i class="fas fa-check"></i></div>
                                <div class="plan-name">Yearly</div>
                                <div class="plan-price">$250</div>
                                <div class="plan-detail">Single annual payment</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Payment Details -->
                <div class="mb-4" id="paymentSection" style="display:none;">
                    <label class="font-weight-bold mb-2"><i class="fas fa-credit-card mr-1 text-primary"></i>Payment Details</label>

                    <?php if (!empty($banks)): ?>
                    <div class="bank-panel mb-3">
                        <div class="mb-2 font-weight-bold text-primary"><i class="fas fa-university mr-1"></i>Bank Details</div>
                        <?php foreach ($banks as $b): ?>
                        <div class="bank-item">
                            <div class="row mt-1">
                                <div class="col-md-6 mb-2">
                                    <div class="bank-label">Account Name</div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="bank-value"><?= h($b['account_name']) ?></div>
                                        <button type="button" class="btn btn-sm btn-outline-primary ml-2 copy-btn js-copy" data-copy="<?= h((string)$b['account_name']) ?>">Copy</button>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <div class="bank-label">BSB</div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="bank-value"><?= h($b['bsb']) ?></div>
                                        <button type="button" class="btn btn-sm btn-outline-primary ml-2 copy-btn js-copy" data-copy="<?= h((string)$b['bsb']) ?>">Copy</button>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <div class="bank-label">Account #</div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="bank-value"><?= h($b['account_number']) ?></div>
                                        <button type="button" class="btn btn-sm btn-outline-primary ml-2 copy-btn js-copy" data-copy="<?= h((string)$b['account_number']) ?>">Copy</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        Bank details are not configured yet. Please contact admin.
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-hashtag mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Payment Reference <span class="text-danger">*</span></label>
                                <div class="p-2 border rounded bg-light d-flex justify-content-between align-items-center">
                                    <div class="bank-value" id="paymentRefLabel">Select child and fee plan first</div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary js-copy-from" data-copy-from="paymentRefInput">Copy</button>
                                </div>
                                <input type="hidden" name="payment_ref" id="paymentRefInput" value="">
                                <small class="text-muted">Suggested: ChildName_plan (e.g. TenzinWangmo_hy or TenzinWangmo_y)</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-file-upload mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Payment Proof <span class="text-danger">*</span></label>
                                <input type="file" name="proof" id="proofInput" class="form-control-file" accept=".jpg,.jpeg,.png,.pdf" required>
                                <small class="text-muted">JPG / PNG / PDF — max 5 MB</small>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 p-3 bg-light rounded border">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="font-weight-bold">Amount to pay now:</span>
                            <span class="h5 mb-0 font-weight-bold" style="color:var(--brand);" id="amountDisplay">$0</span>
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div id="submitSection" style="display:none;">
                    <button type="submit" class="btn btn-primary btn-lg" id="enrolBtn" disabled>
                        <i class="fas fa-paper-plane mr-2"></i>Submit Enrolment
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php elseif (empty($children)): ?>
    <div class="section-title"><i class="fas fa-file-signature"></i>Complete Enrollment</div>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="text-center py-4 text-muted">
                <i class="fas fa-child fa-3x mb-3" style="opacity:0.3;"></i>
                <p class="mb-0">Add child records in the Children menu, then wait for admin approval before completing enrollment here.</p>
            </div>
        </div>
    </div>
    <?php elseif (empty($eligible)): ?>
    <div class="section-title"><i class="fas fa-file-signature"></i>Complete Enrollment</div>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="text-center py-4">
                <i class="fas fa-check-circle fa-3x text-success mb-3" style="opacity:0.5;"></i>
                <p class="text-muted mb-0">No approved children are pending enrollment completion right now.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- SECTION: My Enrolments -->
    <?php if (!empty($enrolments)): ?>
    <div class="section-title"><i class="fas fa-list-alt"></i>My Enrolments</div>

    <div class="row">
    <?php foreach ($enrolments as $e): ?>
        <div class="col-md-6 mb-3">
            <div class="card enrol-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h6 class="font-weight-bold mb-0"><?= h($e['student_name']) ?></h6>
                            <small class="text-muted"><?= h($e['stu_code']) ?></small>
                        </div>
                        <span class="badge badge-<?= pcm_badge($e['status']) ?> badge-status"><?= h($e['status']) ?></span>
                    </div>

                    <div class="row mt-3">
                        <div class="col-6">
                            <div class="mini-label">Campus Preference</div>
                            <div class="font-weight-bold"><?= h(pcm_campus_selection_label((string)($e['campus_preference'] ?? ''))) ?></div>
                        </div>
                        <div class="col-6">
                            <div class="mini-label">Class</div>
                            <div class="font-weight-bold"><?= h($e['assigned_class_name'] ?? 'Pending assignment') ?></div>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-6">
                            <div class="mini-label">Fee Plan</div>
                            <div class="font-weight-bold"><?= h($e['fee_plan']) ?></div>
                        </div>
                        <div class="col-6">
                            <div class="mini-label">Submitted</div>
                            <div><?= date('d M Y', strtotime($e['submitted_at'])) ?></div>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-6">
                            <div class="mini-label">Reference</div>
                            <div><?= h($e['payment_ref'] ?? '—') ?></div>
                        </div>
                        <div class="col-6">
                            <div class="mini-label">&nbsp;</div>
                        </div>
                    </div>

                    <?php if ($e['proof_path']): ?>
                    <div class="mt-2">
                        <a href="<?= h($e['proof_path']) ?>" target="_blank" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-file-alt mr-1"></i>View Proof
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if ($e['admin_note']): ?>
                    <div class="mt-2 small text-muted">
                        <i class="fas fa-comment-alt mr-1"></i><?= h($e['admin_note']) ?>
                    </div>
                    <?php endif; ?>

                    <?php
                        $statusLower = strtolower((string)($e['status'] ?? 'pending'));
                        $hasAssignedClass = !empty($e['assigned_class_name']);
                        $step2 = in_array($statusLower, ['pending','approved','needs update','rejected'], true);
                        $step3 = in_array($statusLower, ['pending','approved','needs update'], true);
                        $step4 = ($statusLower === 'approved' && $hasAssignedClass);
                    ?>
                    <div class="mt-3 p-2 bg-light rounded">
                        <div class="mini-label mb-1">Enrollment Timeline</div>
                        <div style="font-size:.8rem;">
                            <span class="<?= $step2 ? 'text-success' : 'text-muted' ?>">1. Child Approved</span>
                            <span class="text-muted"> -> </span>
                            <span class="<?= $step2 ? 'text-success' : 'text-muted' ?>">2. Enrollment Submitted</span>
                            <span class="text-muted"> -> </span>
                            <span class="<?= $step3 ? 'text-success' : 'text-muted' ?>">3. Under Review</span>
                            <span class="text-muted"> -> </span>
                            <span class="<?= $step4 ? 'text-success' : 'text-muted' ?>">4. Approved + Class Assigned</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

</div><!-- /row -->
</div><!-- /container -->
</div><!-- /content -->
<?php include 'include/admin-footer.php'; ?>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const childSelect = document.getElementById('childSelect');
    const campusSection = document.getElementById('campusSection');
    const campusChoices = document.querySelectorAll('.campus-choice');
    const planSection = document.getElementById('planSection');
    const paymentSection = document.getElementById('paymentSection');
    const submitSection = document.getElementById('submitSection');
    const selectedPlanInput = document.getElementById('selectedPlan');
    const amountDisplay = document.getElementById('amountDisplay');
    const enrolBtn = document.getElementById('enrolBtn');
    const planCards = document.querySelectorAll('.plan-card');
    const paymentRefInput = document.getElementById('paymentRefInput');
    const paymentRefLabel = document.getElementById('paymentRefLabel');
    const proofInput = document.getElementById('proofInput');

    // Step 1 → Step 2: Show campus section when child is selected
    if (childSelect) {
        childSelect.addEventListener('change', function() {
            if (this.value) {
                campusSection.style.display = 'block';
                campusSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                updateSuggestedReference();
            } else {
                campusSection.style.display = 'none';
                planSection.style.display = 'none';
                paymentSection.style.display = 'none';
                submitSection.style.display = 'none';
                campusChoices.forEach(c => { c.checked = false; });
                resetPlanSelection();
                if (paymentRefInput) paymentRefInput.value = '';
                if (paymentRefLabel) paymentRefLabel.textContent = 'Select child and fee plan first';
            }
        });
    }

    // Step 2 → Step 3: Show plan section when at least one campus is selected
    campusChoices.forEach(function(choice) {
        choice.addEventListener('change', function() {
            const hasCampusSelection = Array.from(campusChoices).some(c => c.checked);
            if (hasCampusSelection) {
                planSection.style.display = 'block';
                planSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                planSection.style.display = 'none';
                paymentSection.style.display = 'none';
                submitSection.style.display = 'none';
                resetPlanSelection();
            }
        });
    });

    // Plan card selection
    planCards.forEach(card => {
        card.addEventListener('click', function() {
            planCards.forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
            const plan = this.dataset.plan;
            const amount = this.dataset.amount;
            selectedPlanInput.value = plan;
            amountDisplay.textContent = '$' + amount;
            updateSuggestedReference();

            paymentSection.style.display = 'block';
            submitSection.style.display = 'block';
            enrolBtn.disabled = false;

            paymentSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });

    function resetPlanSelection() {
        planCards.forEach(c => c.classList.remove('selected'));
        selectedPlanInput.value = '';
        amountDisplay.textContent = '$0';
        enrolBtn.disabled = true;
    }

    function slugifyRef(value) {
        return (value || '').replace(/[^A-Za-z0-9]/g, '');
    }

    function planCode(plan) {
        if (plan === 'Half-yearly') return 'hy';
        if (plan === 'Yearly') return 'y';
        if (plan === 'Term-wise') return 'tw';
        return 'pay';
    }

    function updateSuggestedReference() {
        if (!paymentRefInput) return;
        const selectedOption = childSelect ? childSelect.options[childSelect.selectedIndex] : null;
        const selectedPlan = selectedPlanInput ? selectedPlanInput.value : '';
        if (!selectedOption || !selectedOption.value || !selectedPlan) {
            return;
        }
        const label = selectedOption.text || '';
        const childName = label.split('(')[0].trim();
        const ref = slugifyRef(childName) + '_' + planCode(selectedPlan);
        paymentRefInput.value = ref;
        paymentRefInput.dataset.autofilled = '1';
        if (paymentRefLabel) paymentRefLabel.textContent = ref;
    }

    // Form validation before submit
    const enrolForm = document.getElementById('enrolForm');
    if (enrolForm) {
        enrolForm.addEventListener('submit', function(e) {
            if (!childSelect.value) {
                e.preventDefault();
                Swal.fire({icon:'warning', title:'Please select a child', confirmButtonColor:'#881b12'});
                return;
            }
            if (!Array.from(campusChoices).some(c => c.checked)) {
                e.preventDefault();
                Swal.fire({icon:'warning', title:'Please select at least one campus', confirmButtonColor:'#881b12'});
                return;
            }
            if (!selectedPlanInput.value) {
                e.preventDefault();
                Swal.fire({icon:'warning', title:'Please choose a fee plan', confirmButtonColor:'#881b12'});
                return;
            }
            if (!paymentRefInput.value.trim()) {
                e.preventDefault();
                Swal.fire({icon:'warning', title:'Payment reference is required', confirmButtonColor:'#881b12'});
                return;
            }
            if (!proofInput || !proofInput.files || !proofInput.files.length) {
                e.preventDefault();
                Swal.fire({icon:'warning', title:'Please upload payment proof', confirmButtonColor:'#881b12'});
                return;
            }
            enrolBtn.disabled = true;
            enrolBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Submitting...';
        });
    }

    // Copy helper
    window.copyValue = async function(info) {
        const text = (info || '').toString();
        if (text.trim() === '') return;
        try {
            await navigator.clipboard.writeText(text);
            Swal.fire({icon:'success', title:'Copied!', timer:800, showConfirmButton:false});
        } catch(e) {
            // fallback
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            Swal.fire({icon:'success', title:'Copied!', timer:800, showConfirmButton:false});
        }
    };

    // Copy static values
    document.querySelectorAll('.js-copy').forEach(function(btn) {
        btn.addEventListener('click', function() {
            copyValue(this.getAttribute('data-copy') || '');
        });
    });

    // Copy value from referenced field id
    document.querySelectorAll('.js-copy-from').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-copy-from') || '';
            const el = id ? document.getElementById(id) : null;
            const val = el ? (el.value || el.textContent || '') : '';
            copyValue(val);
        });
    });
});
</script>
</body>
</html>
