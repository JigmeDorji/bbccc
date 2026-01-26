<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role === 'parent') {
    header("Location: index-admin.php");
    exit;
}

$message = "";
$success = false;
$reload = false;

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
    die("DB connection failed: " . $e->getMessage());
}

// ---------------- LOAD FEES SETTINGS (BANK + DUE DATES) ----------------
$stmtSet = $pdo->query("SELECT * FROM fees_settings WHERE id = 1 LIMIT 1");
$feesSettings = $stmtSet->fetch() ?: [];

// ---------------- HELPERS ----------------
function badge_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'approved') return 'success';
    if ($s === 'rejected') return 'danger';
    return 'warning';
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

function proof_type(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return ($ext === 'pdf') ? 'pdf' : 'img';
}

function plan_installments(string $plan): array {
    $p = strtolower(trim($plan));
    if ($p === 'term-wise') return ['TERM1','TERM2','TERM3','TERM4'];
    if ($p === 'half-yearly') return ['HALF1','HALF2'];
    if ($p === 'yearly') return ['YEARLY'];
    return [];
}

/**
 * Due date rules requested:
 * TERM1 = HALF1 = YEARLY -> due_term1
 * TERM3 = HALF2 -> due_term3
 * TERM2 -> due_term2
 * TERM4 -> due_term4
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

function pretty_date(?string $d): string {
    if (!$d) return '-';
    return htmlspecialchars($d);
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ---------------- APPROVE / REJECT INSTALLMENT ----------------
if (isset($_GET['fee_action'], $_GET['fee_id'])) {
    try {
        $feeId = (int)$_GET['fee_id'];
        $act = strtolower(trim($_GET['fee_action']));

        if ($feeId <= 0) throw new Exception("Invalid fee ID.");
        if (!in_array($act, ['approve','reject'], true)) throw new Exception("Invalid action.");

        $newStatus = ($act === 'approve') ? 'Approved' : 'Rejected';
        $verifiedBy = $_SESSION['username'] ?? 'admin';

        $stmt = $pdo->prepare("
            UPDATE fees_payments
            SET status = :st,
                verified_by = :vb,
                verified_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':st' => $newStatus,
            ':vb' => $verifiedBy,
            ':id' => $feeId
        ]);

        $message = "Installment {$newStatus} successfully.";
        $success = true;
        $reload = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- LOAD ALL FEE DATA ----------------
$stmt = $pdo->prepare("
    SELECT
        fp.*,
        s.student_id AS public_student_id,
        s.student_name,
        s.approval_status AS enrollment_status,
        s.payment_plan,
        s.payment_proof AS enrollment_proof,
        p.full_name AS parent_name,
        p.email AS parent_email,
        p.phone AS parent_phone,
        p.address AS parent_address
    FROM fees_payments fp
    JOIN students s ON s.id = fp.student_id
    LEFT JOIN parents p ON p.id = s.parentId
    ORDER BY s.id DESC, fp.id ASC
");
$stmt->execute();
$rows = $stmt->fetchAll();

// Group by plan -> student -> installment
$group = [
    'Term-wise' => [],
    'Half-yearly' => [],
    'Yearly' => [],
];

foreach ($rows as $r) {
    $plan = (string)$r['plan_type'];
    if (!isset($group[$plan])) $group[$plan] = [];

    $sid = (string)$r['student_id']; // students.id stored in fees_payments
    if (!isset($group[$plan][$sid])) {
        $group[$plan][$sid] = [
            'student_db_id' => $sid,
            'public_student_id' => $r['public_student_id'] ?? '',
            'student_name' => $r['student_name'] ?? '',
            'payment_plan' => $r['payment_plan'] ?? $plan,
            'enrollment_status' => $r['enrollment_status'] ?? 'Pending',
            'enrollment_proof' => $r['enrollment_proof'] ?? '',
            'parent_name' => $r['parent_name'] ?? '',
            'parent_email' => $r['parent_email'] ?? '',
            'parent_phone' => $r['parent_phone'] ?? '',
            'parent_address' => $r['parent_address'] ?? '',
            'installments' => []
        ];
    }

    $code = (string)$r['installment_code'];
    $group[$plan][$sid]['installments'][$code] = $r;
}

// order plans
$plans = [
    'Term-wise' => ['TERM1','TERM2','TERM3','TERM4'],
    'Half-yearly' => ['HALF1','HALF2'],
    'Yearly' => ['YEARLY'],
];

// Summary values
$bankName = $feesSettings['bank_name'] ?? '';
$accName  = $feesSettings['account_name'] ?? '';
$bsb      = $feesSettings['bsb'] ?? '';
$accNo    = $feesSettings['account_number'] ?? '';
$notes    = $feesSettings['bank_notes'] ?? '';
$due1 = $feesSettings['due_term1'] ?? null;
$due2 = $feesSettings['due_term2'] ?? null;
$due3 = $feesSettings['due_term3'] ?? null;
$due4 = $feesSettings['due_term4'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Fees Management</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .mini { font-size:12px; color:#6c757d; }
        .nowrap { white-space:nowrap; }
        td.wrap { white-space: normal !important; max-width: 240px; }

        .summary-box { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:12px; padding:14px; }
        .due-pill { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#1b4fd6; font-size:11px; font-weight:700; margin-right:6px; margin-bottom:6px; }
        .kv strong { display:inline-block; min-width:120px; }

        /* Proof UI */
        .proof-thumb {
            display:inline-flex;
            align-items:center;
            gap:8px;
            cursor:pointer;
            text-decoration:none;
        }
        .thumb-img {
            width:42px;
            height:42px;
            object-fit:cover;
            border-radius:8px;
            border:1px solid #e3e6f0;
            background:#fff;
        }
        .thumb-icon {
            width:42px;
            height:42px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:8px;
            border:1px solid #e3e6f0;
            background:#fff;
            color:#d93025;
            font-size:18px;
        }

        /* SweetAlert modal sizing */
        .swal2-popup { width: 920px !important; max-width: 96vw !important; }
        .proof-frame { width: 100%; height: 70vh; border: 1px solid #e3e6f0; border-radius: 12px; }

        .proof-stage {
            width: 100%;
            max-height: 72vh;
            overflow: auto;
            border: 1px solid #e3e6f0;
            border-radius: 12px;
            padding: 10px;
            background: #fafbff;
        }
        .proof-img {
            display:block;
            transform-origin: top left;
            border-radius: 10px;
            border: 1px solid #e3e6f0;
            background:#fff;
        }
        .swal-toolbar {
            display:flex;
            gap:8px;
            justify-content:center;
            flex-wrap:wrap;
            margin-top:10px;
        }
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
                    <h1 class="h3 text-gray-800 mb-0">Fees Management</h1>

                    <a href="feesSetting.php" class="btn btn-sm btn-primary">
                        <i class="fas fa-cog"></i> Fees Settings
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
                            }).then(()=> { if (ok && reload) window.location.href = 'feesManagement.php'; });
                        }
                    });
                </script>

                <!-- ✅ SUMMARY BOX -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-info-circle"></i> Current Bank & Due Dates Summary
                        </h6>
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
                                        <span class="due-pill">TERM1 / HALF1 / YEARLY: <?php echo pretty_date($due1); ?></span>
                                        <span class="due-pill">TERM2: <?php echo pretty_date($due2); ?></span>
                                        <span class="due-pill">TERM3 / HALF2: <?php echo pretty_date($due3); ?></span>
                                        <span class="due-pill">TERM4: <?php echo pretty_date($due4); ?></span>
                                    </div>
                                    <div class="mini text-muted">
                                        Rule applied: Term1 = Half1 = Yearly, and Term3 = Half2.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php foreach ($plans as $planName => $codes): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <?php echo htmlspecialchars($planName); ?> Fees
                            </h6>
                            <span class="mini">Installments: <?php echo count($codes); ?></span>
                        </div>

                        <div class="card-body">
                            <?php if (empty($group[$planName])): ?>
                                <div class="alert alert-light mb-0">No records found for this plan.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered" width="100%">
                                        <thead class="thead-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Parent</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th class="wrap">Address</th>
                                            <?php foreach ($codes as $c): ?>
                                                <th class="nowrap"><?php echo htmlspecialchars(installment_label($c)); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($group[$planName] as $sid => $info): ?>
                                            <tr>
                                                <td class="wrap">
                                                    <strong><?php echo htmlspecialchars($info['student_name']); ?></strong><br>
                                                    <span class="mini">Student ID: <?php echo htmlspecialchars($info['public_student_id']); ?></span><br>
                                                    <span class="mini">Enrollment:
                                                        <span class="badge badge-<?php echo badge_class($info['enrollment_status'] ?? 'Pending'); ?>">
                                                            <?php echo htmlspecialchars($info['enrollment_status'] ?? 'Pending'); ?>
                                                        </span>
                                                    </span>
                                                </td>

                                                <td class="wrap"><?php echo htmlspecialchars($info['parent_name'] ?: '-'); ?></td>
                                                <td class="wrap"><?php echo htmlspecialchars($info['parent_email'] ?: '-'); ?></td>
                                                <td class="wrap"><?php echo htmlspecialchars($info['parent_phone'] ?: '-'); ?></td>
                                                <td class="wrap"><?php echo htmlspecialchars($info['parent_address'] ?: '-'); ?></td>

                                                <?php foreach ($codes as $code): ?>
                                                    <?php
                                                        $r = $info['installments'][$code] ?? null;
                                                        $status = $r['status'] ?? 'Pending';
                                                        $proof = trim((string)($r['proof_path'] ?? ''));
                                                        $feeId = (int)($r['id'] ?? 0);
                                                        $due = installment_due_date($feesSettings, $code);
                                                    ?>
                                                    <td>
                                                        <div class="mb-1">
                                                            <span class="badge badge-<?php echo badge_class($status); ?>">
                                                                <?php echo htmlspecialchars($status); ?>
                                                            </span>
                                                        </div>

                                                        <div class="mini mb-2">
                                                            <span class="due-pill">Due: <?php echo pretty_date($due); ?></span>
                                                        </div>

                                                        <?php if ($proof !== ''): ?>
                                                            <?php $type = proof_type($proof); ?>
                                                            <div class="mb-2">
                                                                <a href="javascript:void(0)"
                                                                   class="proof-thumb"
                                                                   data-proof="<?php echo htmlspecialchars($proof); ?>"
                                                                   data-type="<?php echo htmlspecialchars($type); ?>"
                                                                   data-name="<?php echo htmlspecialchars(basename($proof)); ?>">
                                                                    <?php if ($type === 'img'): ?>
                                                                        <img class="thumb-img" src="<?php echo htmlspecialchars($proof); ?>" alt="proof">
                                                                    <?php else: ?>
                                                                        <span class="thumb-icon"><i class="fas fa-file-pdf"></i></span>
                                                                    <?php endif; ?>
                                                                    <span class="mini"><i class="fas fa-eye"></i> View</span>
                                                                </a>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="mini text-muted mb-2">No proof</div>
                                                        <?php endif; ?>

                                                        <?php if ($feeId > 0): ?>
                                                            <div class="btn-group btn-group-sm" role="group">
                                                                <a class="btn btn-success"
                                                                   href="feesManagement.php?fee_action=approve&fee_id=<?php echo (int)$feeId; ?>"
                                                                   onclick="return confirm('Approve this installment?');">
                                                                    Approve
                                                                </a>
                                                                <a class="btn btn-warning"
                                                                   href="feesManagement.php?fee_action=reject&fee_id=<?php echo (int)$feeId; ?>"
                                                                   onclick="return confirm('Reject this installment?');">
                                                                    Reject
                                                                </a>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="mini text-muted">Missing fee row</div>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
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

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    function openProofModal(path, type, filename) {
        filename = filename || 'proof';

        if (type === 'img') {
            let scale = 1;

            Swal.fire({
                title: 'Payment Proof',
                html: `
                    <div class="proof-stage">
                        <img id="swalProofImg" class="proof-img" src="${path}" alt="Proof" />
                    </div>
                    <div class="swal-toolbar">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="zoomInBtn">
                            <i class="fas fa-search-plus"></i> Zoom In
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="zoomOutBtn">
                            <i class="fas fa-search-minus"></i> Zoom Out
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="resetZoomBtn">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <a class="btn btn-sm btn-success" href="${path}" download="${filename}">
                            <i class="fas fa-download"></i> Download
                        </a>
                        <a class="btn btn-sm btn-primary" href="${path}" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Open
                        </a>
                    </div>
                `,
                showCloseButton: true,
                showConfirmButton: false,
                didOpen: () => {
                    const img = document.getElementById('swalProofImg');
                    if (!img) return;

                    img.onload = () => {
                        img.style.width = img.naturalWidth + 'px';
                        img.style.height = 'auto';
                        applyScale();
                    };

                    function applyScale() {
                        img.style.transform = `scale(${scale})`;
                    }

                    document.getElementById('zoomInBtn')?.addEventListener('click', () => {
                        scale = Math.min(5, +(scale + 0.25).toFixed(2));
                        applyScale();
                    });

                    document.getElementById('zoomOutBtn')?.addEventListener('click', () => {
                        scale = Math.max(0.25, +(scale - 0.25).toFixed(2));
                        applyScale();
                    });

                    document.getElementById('resetZoomBtn')?.addEventListener('click', () => {
                        scale = 1;
                        applyScale();
                    });
                }
            });
        } else {
            Swal.fire({
                title: 'Payment Proof (PDF)',
                html: `
                    <iframe class="proof-frame" src="${path}#toolbar=1&navpanes=0&scrollbar=1"></iframe>
                    <div class="swal-toolbar">
                        <a class="btn btn-sm btn-success" href="${path}" download="${filename}">
                            <i class="fas fa-download"></i> Download
                        </a>
                        <a class="btn btn-sm btn-primary" href="${path}" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Open PDF
                        </a>
                    </div>
                `,
                showCloseButton: true,
                showConfirmButton: false
            });
        }
    }

    document.querySelectorAll('.proof-thumb').forEach(el => {
        el.addEventListener('click', function () {
            const path = this.getAttribute('data-proof');
            const type = this.getAttribute('data-type');
            const name = this.getAttribute('data-name') || 'proof';
            if (!path) return;
            openProofModal(path, type, name);
        });
    });

});
</script>

</body>
</html>
