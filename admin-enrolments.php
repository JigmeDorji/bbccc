<?php
// admin-enrolments.php — Review / Approve / Reject parent enrolment requests
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized"); exit; }

$pdo   = pcm_pdo();
$flash = '';
$ok    = false;
pcm_ensure_enrolment_campus_preference($pdo);
$currentActor = (string)($_SESSION['username'] ?? 'admin');
$campusChoices = pcm_campus_choice_labels();
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

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve','reject','request_changes','assign_class'], true)) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'assign_class') {
        $eid = (int)($_POST['enrolment_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($eid <= 0 || $classId <= 0) {
            $flash = 'Please select a valid class.';
        } else {
            $row = $pdo->prepare("
                SELECT e.id, e.student_id, e.status, s.student_name
                FROM pcm_enrolments e
                JOIN students s ON s.id = e.student_id
                WHERE e.id = :id LIMIT 1
            ");
            $row->execute([':id' => $eid]);
            $en = $row->fetch();
            if (!$en) {
                $flash = 'Enrolment not found.';
            } elseif (($en['status'] ?? '') !== 'Approved') {
                $flash = 'Class can be assigned only after enrolment approval.';
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
                $flash = 'Class assigned for <strong>' . h((string)$en['student_name']) . '</strong>.';
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
                    pcm_log_enrolment_event($pdo, (int)$en['student_id'], (int)$en['id'], 'changes_requested', $currentActor, $note);
                    $flash = "Changes requested for <strong>{$en['student_name']}</strong>.";
                    $ok = true;
                } else {
                    $result = pcm_process_enrolment_decision($pdo, (int)$en['student_id'], $action, $reviewer, $note);

                    // Email parent (single mail on approval for faster response)
                    if ($result['new_status'] === 'Approved') {
                        pcm_notify_parent_enrolment_confirmed(
                            (string)$result['parent_email'],
                            (string)$result['parent_name'],
                            (string)$result['student_name']
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
$all = $pdo->query("
    SELECT e.*, s.student_id AS stu_code, s.student_name, s.dob, s.class_option,
           p.full_name AS parent_name, p.email AS parent_email, p.phone AS parent_phone,
           ca.class_id AS assigned_class_id, c.class_name AS assigned_class_name
    FROM pcm_enrolments e
    JOIN students s ON s.id = e.student_id
    JOIN parents  p ON p.id = e.parent_id
    LEFT JOIN class_assignments ca ON ca.student_id = e.student_id
    LEFT JOIN classes c ON c.id = ca.class_id
    ORDER BY FIELD(e.status,'Pending','Approved','Rejected'), e.submitted_at DESC
")->fetchAll();

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
foreach ($auditRows as $ar) {
    $k = (int)($ar['enrolment_id'] ?? 0);
    if ($k <= 0) continue;
    $auditByEnrolment[$k][] = $ar;
}

// Counts
$total   = count($all);
$pending = count(array_filter($all, fn($r)=>$r['status']==='Pending'));
$needsUpdate = count(array_filter($all, fn($r)=>$r['status']==='Needs Update'));
$approved= count(array_filter($all, fn($r)=>$r['status']==='Approved'));
$rejected= count(array_filter($all, fn($r)=>$r['status']==='Rejected'));

$pageScripts = [
    "https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js",
    "https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js",
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

<div class="d-flex justify-content-end mb-3">
    <a href="dzoClassManagement" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
        <i class="fas fa-user-plus mr-1"></i> Child Registration
    </a>
</div>

<!-- Summary Cards -->
<div class="row mb-3">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-primary shadow h-100 py-2"><div class="card-body"><div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total ?></div></div></div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-warning shadow h-100 py-2"><div class="card-body"><div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?= $pending ?></div></div></div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-success shadow h-100 py-2"><div class="card-body"><div class="text-xs font-weight-bold text-success text-uppercase mb-1">Approved</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?= $approved ?></div></div></div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-danger shadow h-100 py-2"><div class="card-body"><div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Rejected</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?= $rejected ?></div></div></div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-info shadow h-100 py-2"><div class="card-body"><div class="text-xs font-weight-bold text-info text-uppercase mb-1">Needs Update</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?= $needsUpdate ?></div></div></div>
    </div>
</div>

<!-- Status Filter Buttons -->
<div class="mb-3">
    <button class="btn btn-sm btn-primary filter-btn active" data-filter="all">All</button>
    <button class="btn btn-sm btn-warning filter-btn" data-filter="Pending">Pending</button>
    <button class="btn btn-sm btn-info filter-btn" data-filter="Needs Update">Needs Update</button>
    <button class="btn btn-sm btn-success filter-btn" data-filter="Approved">Approved</button>
    <button class="btn btn-sm btn-danger filter-btn"  data-filter="Rejected">Rejected</button>
</div>

<!-- Enrolments Table -->
<div class="card shadow mb-4">
    <div class="card-body">
        <div class="table-responsive">
            <table id="enrolTable" class="table table-bordered table-hover" style="width:100%">
                <thead class="thead-light">
                    <tr>
                        <th>#</th><th>Child</th><th>Student ID</th><th>Parent</th><th>Phone</th>
                        <th>Campus</th><th>Plan</th><th>Amount</th><th>Ref</th><th>Proof</th><th>Class</th><th>Status</th><th>Submitted</th><th style="width:300px">Actions</th><th>History</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all as $i => $e): ?>
                <tr data-status="<?= $e['status'] ?>">
                    <td><?= $i+1 ?></td>
                    <td><?= h($e['student_name']) ?></td>
                    <td><code><?= h($e['stu_code']) ?></code></td>
                    <td><?= h($e['parent_name']) ?><br><small class="text-muted"><?= h($e['parent_email']) ?></small></td>
                    <td><?= h($e['parent_phone'] ?? '-') ?></td>
                    <td><?= h(pcm_campus_selection_label((string)($e['campus_preference'] ?? ''))) ?></td>
                    <td><?= h($e['fee_plan']) ?></td>
                    <td>$<?= number_format($e['fee_amount'],2) ?></td>
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
                    <td><?= h($e['assigned_class_name'] ?? 'Not assigned') ?></td>
                    <td><span class="badge badge-<?= pcm_badge($e['status']) ?>"><?= h($e['status']) ?></span>
                        <?php if ($e['admin_note']): ?><br><small><?= h($e['admin_note']) ?></small><?php endif; ?>
                    </td>
                    <td><?= date('d M Y', strtotime($e['submitted_at'])) ?></td>
                    <td>
                        <?php if ($e['status'] === 'Pending'): ?>
                        <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#approveModal<?= $e['id'] ?>"><i class="fas fa-check mr-1"></i>Approve</button>
                        <button class="btn btn-danger btn-sm"  data-toggle="modal" data-target="#rejectModal<?= $e['id'] ?>"><i class="fas fa-times mr-1"></i>Reject</button>
                        <button class="btn btn-warning btn-sm" data-toggle="modal" data-target="#changesModal<?= $e['id'] ?>"><i class="fas fa-edit mr-1"></i>Request Changes</button>

                        <!-- Approve Modal -->
                        <div class="modal fade" id="approveModal<?= $e['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST" class="js-enrol-action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="enrolment_id" value="<?= $e['id'] ?>">
                                    <div class="modal-header bg-success text-white"><h5 class="modal-title">Approve Enrolment</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
                                    <div class="modal-body">
                                        <p>Approve enrolment for <strong><?= h($e['student_name']) ?></strong>?</p>
                                        <p class="small text-muted">This will also create fee instalment records and approve the first payment if proof is attached.</p>
                                        <div class="form-group"><label>Note (optional)</label><textarea name="admin_note" class="form-control" rows="2"></textarea></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success js-submit-action-btn">Approve</button></div>
                                </form>
                            </div></div>
                        </div>

                        <!-- Reject Modal -->
                        <div class="modal fade" id="rejectModal<?= $e['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST" class="js-enrol-action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="enrolment_id" value="<?= $e['id'] ?>">
                                    <div class="modal-header bg-danger text-white"><h5 class="modal-title">Reject Enrolment</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
                                    <div class="modal-body">
                                        <p>Reject enrolment for <strong><?= h($e['student_name']) ?></strong>?</p>
                                        <div class="form-group"><label>Reason / Note</label><textarea name="admin_note" class="form-control" rows="2" required></textarea></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger js-submit-action-btn">Reject</button></div>
                                </form>
                            </div></div>
                        </div>

                        <!-- Request Changes Modal -->
                        <div class="modal fade" id="changesModal<?= $e['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST" class="js-enrol-action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_changes">
                                    <input type="hidden" name="enrolment_id" value="<?= $e['id'] ?>">
                                    <div class="modal-header bg-warning text-dark"><h5 class="modal-title">Request Changes</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                                    <div class="modal-body">
                                        <p>Ask parent to update submission for <strong><?= h($e['student_name']) ?></strong>.</p>
                                        <div class="form-group"><label>Required update note</label><textarea name="admin_note" class="form-control" rows="2" required placeholder="e.g. Please upload clearer payment proof"></textarea></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning js-submit-action-btn">Send Request</button></div>
                                </form>
                            </div></div>
                        </div>
                        <?php else: ?>
                            <?php if ($e['status'] === 'Approved'): ?>
                                <?php
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
                                ?>
                                <form method="POST" class="form-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="assign_class">
                                    <input type="hidden" name="enrolment_id" value="<?= (int)$e['id'] ?>">
                                    <select name="class_id" class="form-control form-control-sm mr-1" style="min-width:155px;" required>
                                        <option value="">Assign class</option>
                                        <?php foreach ($matchingClasses as $cl): ?>
                                            <option value="<?= (int)$cl['id'] ?>" <?= ((int)$e['assigned_class_id'] === (int)$cl['id']) ? 'selected' : '' ?>>
                                                <?= h($cl['class_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted small"><?= h($e['reviewed_by'] ?? '') ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                            $historyItems = $auditByEnrolment[(int)$e['id']] ?? [];
                            $historyCount = count($historyItems);
                        ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="modal" data-target="#historyModal<?= (int)$e['id'] ?>">
                            View (<?= $historyCount ?>)
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php foreach ($all as $e): ?>
<?php $historyItems = $auditByEnrolment[(int)$e['id']] ?? []; ?>
<div class="modal fade" id="historyModal<?= (int)$e['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Audit History — <?= h($e['student_name']) ?></h5>
            <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
            <?php if (count($historyItems) === 0): ?>
                <p class="text-muted mb-0">No audit history yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="thead-light">
                            <tr><th style="width:170px">Time</th><th style="width:170px">Event</th><th style="width:160px">Actor</th><th>Details</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($historyItems as $h): ?>
                            <tr>
                                <td><?= date('d M Y H:i', strtotime((string)$h['created_at'])) ?></td>
                                <td><?= h((string)$h['event_type']) ?></td>
                                <td><?= h((string)($h['actor'] ?? '')) ?></td>
                                <td><?= h((string)($h['details'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div></div>
</div>
<?php endforeach; ?>

</div>
</div>
<?php include 'include/admin-footer.php'; ?>
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
    var dt = $('#enrolTable').DataTable({pageLength:25, order:[[12,'desc']]});

    // Status filter buttons
    $('.filter-btn').on('click', function(){
        $('.filter-btn').removeClass('active');
        $(this).addClass('active');
        var f = $(this).data('filter');
        if(f === 'all') {
            dt.column(11).search('').draw();
        } else {
            dt.column(11).search('^'+f+'$', true, false).draw();
        }
    });

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
});
</script>
</body>
</html>
