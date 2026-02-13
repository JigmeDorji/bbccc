<?php
require_once "include/config.php";
require_once "include/mailer.php";

$message    = "";
$msgType    = "success";
$reloadPage = false;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // ── APPROVE ──────────────────────────────────────────────
    if (isset($_GET['approve'])) {
        if (!isset($_GET['token']) || $_GET['token'] !== ($_SESSION['csrf_token'] ?? '')) {
            throw new Exception("Invalid CSRF token.");
        }
        $bookingId = (int)$_GET['approve'];

        $pdo->beginTransaction();

        // Get booking info
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id");
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new Exception("Booking not found.");

        // Check no other approved booking exists for same event
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE event_id = :eid AND status = 'Approved' AND id != :bid");
        $stmtCheck->execute([':eid' => $booking['event_id'], ':bid' => $bookingId]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            throw new Exception("Another booking is already approved for this event.");
        }

        // Update booking status
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'Approved' WHERE id = :id");
        $stmt->execute([':id' => $bookingId]);

        // Update event status
        $stmt = $pdo->prepare("UPDATE events SET status = 'Booked' WHERE id = :eid");
        $stmt->execute([':eid' => $booking['event_id']]);

        // Reject other pending bookings for same event
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'Rejected' WHERE event_id = :eid AND id != :bid AND status = 'Pending'");
        $stmt->execute([':eid' => $booking['event_id'], ':bid' => $bookingId]);

        $pdo->commit();

        // Email the user
        $eventStmt = $pdo->prepare("SELECT title, event_date FROM events WHERE id = :eid");
        $eventStmt->execute([':eid' => $booking['event_id']]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

        $emailBody = "
        <h2>Booking Approved!</h2>
        <p>Dear {$booking['name']},</p>
        <p>Your booking request for <strong>{$event['title']}</strong> on <strong>" . date('d M Y', strtotime($event['event_date'])) . "</strong> has been <span style='color:green;font-weight:bold;'>APPROVED</span>.</p>
        <p>Thank you for sponsoring this event!</p>
        <br>
        <p>Best regards,<br>Bhutanese Buddhist & Cultural Centre, Canberra</p>";
        send_mail($booking['email'], $booking['name'], "Booking Approved – " . $event['title'], $emailBody);

        $message    = "Booking approved successfully.";
        $reloadPage = true;
    }

    // ── REJECT ───────────────────────────────────────────────
    if (isset($_GET['reject'])) {
        if (!isset($_GET['token']) || $_GET['token'] !== ($_SESSION['csrf_token'] ?? '')) {
            throw new Exception("Invalid CSRF token.");
        }
        $bookingId = (int)$_GET['reject'];

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id");
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new Exception("Booking not found.");

        // Update booking status
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'Rejected' WHERE id = :id");
        $stmt->execute([':id' => $bookingId]);

        // If no other pending bookings, set event back to Available
        $stmtOthers = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE event_id = :eid AND status = 'Pending' AND id != :bid");
        $stmtOthers->execute([':eid' => $booking['event_id'], ':bid' => $bookingId]);
        $pendingCount = (int)$stmtOthers->fetchColumn();

        // Also check if there's an approved booking
        $stmtApproved = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE event_id = :eid AND status = 'Approved'");
        $stmtApproved->execute([':eid' => $booking['event_id']]);
        $approvedCount = (int)$stmtApproved->fetchColumn();

        if ($pendingCount === 0 && $approvedCount === 0) {
            $stmt = $pdo->prepare("UPDATE events SET status = 'Available' WHERE id = :eid");
            $stmt->execute([':eid' => $booking['event_id']]);
        }

        $pdo->commit();

        // Email the user
        $eventStmt = $pdo->prepare("SELECT title, event_date FROM events WHERE id = :eid");
        $eventStmt->execute([':eid' => $booking['event_id']]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

        $emailBody = "
        <h2>Booking Update</h2>
        <p>Dear {$booking['name']},</p>
        <p>We regret to inform you that your booking request for <strong>{$event['title']}</strong> on <strong>" . date('d M Y', strtotime($event['event_date'])) . "</strong> has been <span style='color:red;font-weight:bold;'>REJECTED</span>.</p>
        <p>Please feel free to book another available event.</p>
        <br>
        <p>Best regards,<br>Bhutanese Buddhist & Cultural Centre, Canberra</p>";
        send_mail($booking['email'], $booking['name'], "Booking Update – " . $event['title'], $emailBody);

        $message    = "Booking rejected.";
        $reloadPage = true;
    }

    // ── DELETE BOOKING ───────────────────────────────────────
    if (isset($_GET['delete_booking'])) {
        if (!isset($_GET['token']) || $_GET['token'] !== ($_SESSION['csrf_token'] ?? '')) {
            throw new Exception("Invalid CSRF token.");
        }
        $bookingId = (int)$_GET['delete_booking'];

        $stmt = $pdo->prepare("SELECT event_id FROM bookings WHERE id = :id");
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("DELETE FROM bookings WHERE id = :id");
        $stmt->execute([':id' => $bookingId]);

        if ($booking) {
            // Recalculate event status
            $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE event_id = :eid AND status IN ('Pending','Approved')");
            $stmtPending->execute([':eid' => $booking['event_id']]);
            if ((int)$stmtPending->fetchColumn() === 0) {
                $pdo->prepare("UPDATE events SET status = 'Available' WHERE id = :eid")->execute([':eid' => $booking['event_id']]);
            }
        }

        $message    = "Booking deleted.";
        $reloadPage = true;
    }

    // ── FETCH DATA ───────────────────────────────────────────
    $eventFilter = isset($_GET['event_id']) ? (int)$_GET['event_id'] : null;

    if ($eventFilter) {
        $stmtEv = $pdo->prepare("SELECT * FROM events WHERE id = :id");
        $stmtEv->execute([':id' => $eventFilter]);
        $currentEvent = $stmtEv->fetch(PDO::FETCH_ASSOC);

        $stmtB = $pdo->prepare("SELECT * FROM bookings WHERE event_id = :eid ORDER BY created_at DESC");
        $stmtB->execute([':eid' => $eventFilter]);
        $bookings = $stmtB->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $currentEvent = null;
        $stmtB = $pdo->query("SELECT b.*, e.title AS event_title, e.event_date
                               FROM bookings b
                               JOIN events e ON e.id = b.event_id
                               ORDER BY b.created_at DESC");
        $bookings = $stmtB->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $message = $e->getMessage();
    $msgType = "error";
    $bookings = $bookings ?? [];
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Build redirect base
$redirectBase = 'bookingManagement.php' . ($eventFilter ? "?event_id=$eventFilter" : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking Management</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <style>
        .badge-pending  { background:#ffc107; color:#333; }
        .badge-approved { background:#28a745; color:#fff; }
        .badge-rejected { background:#dc3545; color:#fff; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">

<?php include 'include/admin-nav.php'; ?>

<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid">

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">
        Booking Management
        <?php if ($currentEvent): ?>
            <small class="text-muted">— <?= htmlspecialchars($currentEvent['title']) ?> (<?= date('d M Y', strtotime($currentEvent['event_date'])) ?>)</small>
        <?php endif; ?>
    </h1>
    <div>
        <a href="eventManagement.php" class="btn btn-sm btn-secondary"><i class="fas fa-arrow-left"></i> Back to Events</a>
        <?php if (!empty($bookings)): ?>
            <a href="exportBookings.php<?= $eventFilter ? '?event_id='.$eventFilter : '' ?>" class="btn btn-sm btn-success"><i class="fas fa-file-csv"></i> Export CSV</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($currentEvent): ?>
<div class="card shadow mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-3"><strong>Event:</strong> <?= htmlspecialchars($currentEvent['title']) ?></div>
            <div class="col-md-2"><strong>Date:</strong> <?= date('d M Y', strtotime($currentEvent['event_date'])) ?></div>
            <div class="col-md-2"><strong>Time:</strong>
                <?= $currentEvent['start_time'] ? date('h:i A', strtotime($currentEvent['start_time'])) : '—' ?>
                <?= $currentEvent['end_time'] ? ' – '.date('h:i A', strtotime($currentEvent['end_time'])) : '' ?>
            </div>
            <div class="col-md-2"><strong>Location:</strong> <?= htmlspecialchars($currentEvent['location'] ?? '—') ?></div>
            <div class="col-md-3"><strong>Status:</strong>
                <?php
                $cls = 'badge-approved';
                if ($currentEvent['status'] === 'Pending Approval') $cls = 'badge-pending';
                elseif ($currentEvent['status'] === 'Booked') $cls = 'badge-rejected';
                ?>
                <span class="badge <?= $cls ?>"><?= $currentEvent['status'] ?></span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ BOOKINGS TABLE ═══ -->
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">
            Booking Requests <?= $currentEvent ? '(' . count($bookings) . ')' : '' ?>
        </h6>
    </div>
    <div class="card-body">
        <?php if (empty($bookings)): ?>
            <div class="alert alert-info">No booking requests found.</div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-bordered table-hover" id="bookingsTable" width="100%">
            <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <?php if (!$eventFilter): ?><th>Event</th><th>Event Date</th><?php endif; ?>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>Message</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th style="min-width:150px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $i => $bk): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <?php if (!$eventFilter): ?>
                        <td><?= htmlspecialchars($bk['event_title'] ?? '') ?></td>
                        <td><?= isset($bk['event_date']) ? date('d M Y', strtotime($bk['event_date'])) : '' ?></td>
                    <?php endif; ?>
                    <td><strong><?= htmlspecialchars($bk['name']) ?></strong></td>
                    <td><a href="mailto:<?= htmlspecialchars($bk['email']) ?>"><?= htmlspecialchars($bk['email']) ?></a></td>
                    <td><?= htmlspecialchars($bk['phone']) ?></td>
                    <td><?= htmlspecialchars($bk['address']) ?></td>
                    <td><?= htmlspecialchars($bk['message'] ?? '—') ?></td>
                    <td>
                        <?php
                        $bc = 'badge-pending';
                        if ($bk['status'] === 'Approved') $bc = 'badge-approved';
                        elseif ($bk['status'] === 'Rejected') $bc = 'badge-rejected';
                        ?>
                        <span class="badge <?= $bc ?>"><?= $bk['status'] ?></span>
                    </td>
                    <td><?= date('d M Y H:i', strtotime($bk['created_at'])) ?></td>
                    <td>
                        <?php if ($bk['status'] === 'Pending'): ?>
                            <a href="<?= $redirectBase . (strpos($redirectBase,'?')!==false?'&':'?') ?>approve=<?= $bk['id'] ?>&token=<?= $csrf ?>"
                               class="btn btn-success btn-sm approve-btn" title="Approve"><i class="fas fa-check"></i></a>
                            <a href="<?= $redirectBase . (strpos($redirectBase,'?')!==false?'&':'?') ?>reject=<?= $bk['id'] ?>&token=<?= $csrf ?>"
                               class="btn btn-warning btn-sm reject-btn" title="Reject"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                        <a href="<?= $redirectBase . (strpos($redirectBase,'?')!==false?'&':'?') ?>delete_booking=<?= $bk['id'] ?>&token=<?= $csrf ?>"
                           class="btn btn-danger btn-sm del-booking-btn" title="Delete"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- container-fluid -->
</div><!-- content -->

<?php
$pageScripts = [
    'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js'
];
include 'include/admin-footer.php';
?>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
<script>
$(document).ready(function() {
    $('#bookingsTable').DataTable({ pageLength: 25, order: [[0, 'asc']] });
});

document.querySelectorAll('.approve-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const href = this.href;
        Swal.fire({
            title: 'Approve this booking?',
            text: 'The event will be marked as Booked and the user will be notified.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            confirmButtonText: 'Approve'
        }).then(result => { if (result.isConfirmed) window.location.href = href; });
    });
});

document.querySelectorAll('.reject-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const href = this.href;
        Swal.fire({
            title: 'Reject this booking?',
            text: 'The user will be notified via email.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ffc107',
            confirmButtonText: 'Reject'
        }).then(result => { if (result.isConfirmed) window.location.href = href; });
    });
});

document.querySelectorAll('.del-booking-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const href = this.href;
        Swal.fire({
            title: 'Delete this booking?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Delete'
        }).then(result => { if (result.isConfirmed) window.location.href = href; });
    });
});

<?php if ($message): ?>
Swal.fire({
    icon: '<?= $msgType ?>',
    title: '<?= addslashes($message) ?>',
    showConfirmButton: false,
    timer: 1800
}).then(() => {
    <?php if ($reloadPage): ?>window.location.href = '<?= $redirectBase ?>';<?php endif; ?>
});
<?php endif; ?>
</script>

</body>
</html>
