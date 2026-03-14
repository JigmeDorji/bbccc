<?php
// parent-enrolment.php — Unified: Manage Children + Enrol + Bank Details + Fee Plans
// Redesigned for international standards: single-page, minimal clicks, smooth UX
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_parent_role()) { header("Location: unauthorized"); exit; }

$pdo      = pcm_pdo();
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
        $name   = trim($_POST['child_name'] ?? '');
        $dob    = trim($_POST['dob'] ?? '');
        $gender = trim($_POST['child_gender'] ?? '');
        $med    = trim($_POST['medical'] ?? '');

        if ($name === '') {
            $flash = 'Child name is required.';
        } else {
            $sid = pcm_next_student_id($pdo);
            $stmt = $pdo->prepare("INSERT INTO students (student_id, student_name, dob, gender, medical_issue, registration_date, approval_status, parentId) VALUES (:sid, :name, :dob, :g, :med, CURDATE(), 'Pending', :pid)");
            $stmt->execute([':sid'=>$sid, ':name'=>$name, ':dob'=>$dob?:null, ':g'=>$gender?:null, ':med'=>$med?:null, ':pid'=>$parentId]);
            $flash = "Child <strong>{$name}</strong> added successfully (ID: {$sid}).";
            $ok = true;
        }
    }

    // --- Remove Child ---
    if ($act === 'remove_child') {
        $cid = (int)($_POST['child_id'] ?? 0);
        $chk = $pdo->prepare("SELECT 1 FROM pcm_enrolments WHERE student_id = :id LIMIT 1");
        $chk->execute([':id'=>$cid]);
        if ($chk->fetch()) {
            $flash = 'Cannot remove a child who has an enrolment. Contact admin.';
        } else {
            $del = $pdo->prepare("DELETE FROM students WHERE id = :id AND parentId = :pid AND approval_status = 'Pending'");
            $del->execute([':id'=>$cid, ':pid'=>$parentId]);
            $flash = $del->rowCount() ? 'Child removed.' : 'Cannot remove this child.';
            $ok = (bool)$del->rowCount();
        }
    }

    // --- Submit Enrolment ---
    if ($act === 'enrol') {
        $childId = (int)($_POST['child_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $plan    = trim($_POST['fee_plan'] ?? '');
        $ref     = trim($_POST['payment_ref'] ?? '');

        $chk = $pdo->prepare("SELECT id, student_name FROM students WHERE id=:id AND parentId=:pid LIMIT 1");
        $chk->execute([':id'=>$childId, ':pid'=>$parentId]);
        $child = $chk->fetch();

        if (!$child) {
            $flash = 'Invalid child selected.';
        } elseif ($classId <= 0) {
            $flash = 'Please select a class.';
        } elseif (!in_array($plan, ['Term-wise','Half-yearly','Yearly'])) {
            $flash = 'Invalid fee plan.';
        } else {
            $dup = $pdo->prepare("SELECT 1 FROM pcm_enrolments WHERE student_id=:id LIMIT 1");
            $dup->execute([':id'=>$childId]);
            if ($dup->fetch()) {
                $flash = 'This child already has an enrolment on file.';
            } else {
                $amount = pcm_plan_amount($plan);
                $proofPath = null;

                if (!empty($_FILES['proof']['name']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
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
                    $ins = $pdo->prepare("INSERT INTO pcm_enrolments (student_id, parent_id, fee_plan, fee_amount, payment_ref, proof_path) VALUES (:sid, :pid, :plan, :amt, :ref, :proof)");
                    $ins->execute([':sid'=>$childId, ':pid'=>$parentId, ':plan'=>$plan, ':amt'=>$amount, ':ref'=>$ref?:null, ':proof'=>$proofPath]);

                    // Assign the selected class in class_assignments
                    $delOld = $pdo->prepare("DELETE FROM class_assignments WHERE student_id = :sid");
                    $delOld->execute([':sid' => $childId]);
                    $insCA = $pdo->prepare("INSERT INTO class_assignments (class_id, student_id, assigned_by) VALUES (:cid, :sid, :by)");
                    $insCA->execute([':cid' => $classId, ':sid' => $childId, ':by' => 'parent']);

                    pcm_notify_admin_enrolment($child['student_name'], $parent['full_name']);
                    $flash = "Enrolment submitted for <strong>{$child['student_name']}</strong>. You will be notified once reviewed.";
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

// ── Children eligible for enrolment (no existing pcm_enrolment) ──
$eligible = $pdo->prepare("SELECT s.id, s.student_id, s.student_name FROM students s WHERE s.parentId = :pid AND s.id NOT IN (SELECT student_id FROM pcm_enrolments) ORDER BY s.student_name");
$eligible->execute([':pid'=>$parentId]);
$eligible = $eligible->fetchAll();

// ── Existing enrolments ──
$enrolments = $pdo->prepare("SELECT e.*, s.student_id AS stu_code, s.student_name, c.class_name AS class_name FROM pcm_enrolments e JOIN students s ON s.id = e.student_id LEFT JOIN class_assignments ca ON ca.student_id = e.student_id LEFT JOIN classes c ON c.id = ca.class_id WHERE e.parent_id = :pid ORDER BY e.submitted_at DESC");
$enrolments->execute([':pid'=>$parentId]);
$enrolments = $enrolments->fetchAll();

// ── Active classes for enrolment ──
$activeClasses = $pdo->query("SELECT id, class_name, schedule_text FROM classes WHERE active = 1 ORDER BY class_name")->fetchAll();

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
    <?= $ok ? ".then(()=>window.location='parent-enrolment.php')" : "" ?>;
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

<!-- ═══ LEFT COLUMN: Children + Enrolment Form ═══ -->
<div class="col-lg-8">

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

    <!-- SECTION: Enrol a Child -->
    <?php if (!empty($eligible)): ?>
    <div class="section-title"><i class="fas fa-file-signature"></i>Enrol a Child</div>

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
                        <option value="">— Choose a child to enrol —</option>
                        <?php foreach ($eligible as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= h($c['student_name']) ?> (<?= h($c['student_id']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Step 2: Select Class -->
                <div class="mb-4" id="classSection" style="display:none;">
                    <label class="font-weight-bold mb-2"><i class="fas fa-chalkboard mr-1 text-primary"></i>Select Class</label>
                    <select name="class_id" class="form-control" required id="classSelect">
                        <option value="">— Choose a class —</option>
                        <?php foreach ($activeClasses as $ac): ?>
                            <option value="<?= (int)$ac['id'] ?>"><?= h($ac['class_name']) ?><?= $ac['schedule_text'] ? ' — ' . h($ac['schedule_text']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($activeClasses)): ?>
                        <small class="text-muted"><i class="fas fa-info-circle mr-1"></i>No classes are currently available. Please contact admin.</small>
                    <?php endif; ?>
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

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-hashtag mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Payment Reference</label>
                                <input type="text" name="payment_ref" class="form-control" maxlength="150" placeholder="Bank transfer reference number">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-file-upload mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Payment Proof</label>
                                <input type="file" name="proof" class="form-control-file" accept=".jpg,.jpeg,.png,.pdf">
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
    <div class="section-title"><i class="fas fa-file-signature"></i>Enrol a Child</div>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="text-center py-4 text-muted">
                <i class="fas fa-child fa-3x mb-3" style="opacity:0.3;"></i>
                <p class="mb-0">Add a child above first, then you can enrol them here.</p>
            </div>
        </div>
    </div>
    <?php elseif (empty($eligible)): ?>
    <div class="section-title"><i class="fas fa-file-signature"></i>Enrol a Child</div>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="text-center py-4">
                <i class="fas fa-check-circle fa-3x text-success mb-3" style="opacity:0.5;"></i>
                <p class="text-muted mb-0">All your children have been enrolled. <i class="fas fa-smile"></i></p>
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
                            <div class="mini-label">Class</div>
                            <div class="font-weight-bold"><?= h($e['class_name'] ?? '—') ?></div>
                        </div>
                        <div class="col-6">
                            <div class="mini-label">Fee Plan</div>
                            <div class="font-weight-bold"><?= h($e['fee_plan']) ?></div>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-6">
                            <div class="mini-label">Amount</div>
                            <div class="font-weight-bold" style="color:var(--brand);">$<?= number_format($e['fee_amount'],2) ?></div>
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
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- ═══ RIGHT COLUMN: Bank Details + Fee Plans Reference ═══ -->
<div class="col-lg-4">

    <!-- Bank Details -->
    <div class="section-title"><i class="fas fa-university"></i>Bank Details for Payment</div>

    <?php if (!empty($banks)): ?>
    <div class="bank-panel mb-4">
        <?php foreach ($banks as $b): ?>
        <div class="bank-item">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <strong style="font-size:0.9rem;"><?= h($b['bank_name']) ?></strong>
                <span class="copy-btn" onclick="copyAllBank(this)" data-info="<?= h($b['account_name'].' | BSB: '.$b['bsb'].' | Acc: '.$b['account_number']) ?>">
                    <i class="fas fa-copy mr-1"></i>Copy
                </span>
            </div>
            <div class="row mt-2">
                <div class="col-12 mb-1">
                    <div class="bank-label">Account Name</div>
                    <div class="bank-value"><?= h($b['account_name']) ?></div>
                </div>
                <div class="col-6">
                    <div class="bank-label">BSB</div>
                    <div class="bank-value"><?= h($b['bsb']) ?></div>
                </div>
                <div class="col-6">
                    <div class="bank-label">Account #</div>
                    <div class="bank-value"><?= h($b['account_number']) ?></div>
                </div>
            </div>
            <?php if ($b['reference_hint']): ?>
            <div class="mt-2" style="font-size:0.78rem; color:#858796;">
                <i class="fas fa-info-circle mr-1"></i><?= h($b['reference_hint']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body text-muted text-center py-4">
            <i class="fas fa-university fa-2x mb-2" style="opacity:0.3;"></i>
            <p class="mb-0">Bank details not configured yet. Contact admin.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Fee Plans Reference -->
    <div class="section-title"><i class="fas fa-calculator"></i>Fee Plans Reference</div>
    <div class="card shadow-sm mb-4">
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="thead-light">
                    <tr><th>Plan</th><th class="text-right">Amount</th><th class="text-right">Payments/yr</th></tr>
                </thead>
                <tbody>
                    <tr><td><i class="fas fa-calendar-week text-primary mr-1"></i>Term-wise</td><td class="text-right font-weight-bold">$65</td><td class="text-right">4</td></tr>
                    <tr><td><i class="fas fa-calendar-alt text-info mr-1"></i>Half-yearly</td><td class="text-right font-weight-bold">$125</td><td class="text-right">2</td></tr>
                    <tr><td><i class="fas fa-calendar-check text-success mr-1"></i>Yearly</td><td class="text-right font-weight-bold">$250</td><td class="text-right">1</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- How It Works -->
    <div class="card shadow-sm mb-4 border-left-primary">
        <div class="card-body">
            <h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-question-circle mr-1"></i>How It Works</h6>
            <div class="d-flex align-items-start mb-2">
                <span class="badge badge-primary mr-2" style="min-width:22px;">1</span>
                <small>Add your child(ren) above</small>
            </div>
            <div class="d-flex align-items-start mb-2">
                <span class="badge badge-primary mr-2" style="min-width:22px;">2</span>
                <small>Select a child and choose a fee plan</small>
            </div>
            <div class="d-flex align-items-start mb-2">
                <span class="badge badge-primary mr-2" style="min-width:22px;">3</span>
                <small>Make payment using bank details above</small>
            </div>
            <div class="d-flex align-items-start mb-2">
                <span class="badge badge-primary mr-2" style="min-width:22px;">4</span>
                <small>Upload proof and submit enrolment</small>
            </div>
            <div class="d-flex align-items-start">
                <span class="badge badge-success mr-2" style="min-width:22px;"><i class="fas fa-check" style="font-size:0.6rem;"></i></span>
                <small>Admin reviews and approves</small>
            </div>
        </div>
    </div>

    <!-- Need Help -->
    <div class="card shadow-sm mb-4">
        <div class="card-body text-center">
            <i class="fas fa-headset fa-2x text-muted mb-2" style="opacity:0.4;"></i>
            <p class="small text-muted mb-1">Need help with enrolment?</p>
            <a href="contact-us" class="btn btn-outline-primary btn-sm"><i class="fas fa-envelope mr-1"></i>Contact Us</a>
        </div>
    </div>

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
    const classSection = document.getElementById('classSection');
    const classSelect = document.getElementById('classSelect');
    const planSection = document.getElementById('planSection');
    const paymentSection = document.getElementById('paymentSection');
    const submitSection = document.getElementById('submitSection');
    const selectedPlanInput = document.getElementById('selectedPlan');
    const amountDisplay = document.getElementById('amountDisplay');
    const enrolBtn = document.getElementById('enrolBtn');
    const planCards = document.querySelectorAll('.plan-card');

    // Step 1 → Step 2: Show class section when child is selected
    if (childSelect) {
        childSelect.addEventListener('change', function() {
            if (this.value) {
                classSection.style.display = 'block';
                classSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                classSection.style.display = 'none';
                planSection.style.display = 'none';
                paymentSection.style.display = 'none';
                submitSection.style.display = 'none';
                if (classSelect) classSelect.value = '';
                resetPlanSelection();
            }
        });
    }

    // Step 2 → Step 3: Show plan section when class is selected
    if (classSelect) {
        classSelect.addEventListener('change', function() {
            if (this.value) {
                planSection.style.display = 'block';
                planSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                planSection.style.display = 'none';
                paymentSection.style.display = 'none';
                submitSection.style.display = 'none';
                resetPlanSelection();
            }
        });
    }

    // Plan card selection
    planCards.forEach(card => {
        card.addEventListener('click', function() {
            planCards.forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
            const plan = this.dataset.plan;
            const amount = this.dataset.amount;
            selectedPlanInput.value = plan;
            amountDisplay.textContent = '$' + amount;

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

    // Form validation before submit
    const enrolForm = document.getElementById('enrolForm');
    if (enrolForm) {
        enrolForm.addEventListener('submit', function(e) {
            if (!childSelect.value) {
                e.preventDefault();
                Swal.fire({icon:'warning', title:'Please select a child', confirmButtonColor:'#881b12'});
                return;
            }
            if (!classSelect.value) {
                e.preventDefault();
                Swal.fire({icon:'warning', title:'Please select a class', confirmButtonColor:'#881b12'});
                return;
            }
            if (!selectedPlanInput.value) {
                e.preventDefault();
                Swal.fire({icon:'warning', title:'Please choose a fee plan', confirmButtonColor:'#881b12'});
                return;
            }
            enrolBtn.disabled = true;
            enrolBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Submitting...';
        });
    }

    // Copy bank details
    window.copyAllBank = async function(el) {
        const info = el.dataset.info || '';
        try {
            await navigator.clipboard.writeText(info);
            Swal.fire({icon:'success', title:'Copied!', timer:800, showConfirmButton:false});
        } catch(e) {
            // fallback
            const ta = document.createElement('textarea');
            ta.value = info;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            Swal.fire({icon:'success', title:'Copied!', timer:800, showConfirmButton:false});
        }
    };
});
</script>
</body>
</html>