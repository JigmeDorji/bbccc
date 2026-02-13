<?php
require_once "include/config.php";

$message     = "";
$msgType     = "success";
$reloadPage  = false;

// Form defaults
$edit_id     = null;
$f_title     = "";
$f_desc      = "";
$f_date      = "";
$f_start     = "";
$f_end       = "";
$f_location  = "";
$f_sponsors  = "";
$f_contacts  = "";
$f_status    = "Available";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // ── DELETE ──────────────────────────────────────────────
    if (isset($_GET['delete'])) {
        $stmt = $pdo->prepare("DELETE FROM events WHERE id = :id");
        $stmt->execute([':id' => (int)$_GET['delete']]);
        $message    = "Event deleted successfully.";
        $reloadPage = true;
    }

    // ── INSERT / UPDATE ─────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF check
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            throw new Exception("Invalid CSRF token.");
        }

        // Sanitize
        $title    = trim(htmlspecialchars($_POST['title']    ?? '', ENT_QUOTES, 'UTF-8'));
        $desc     = trim(htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8'));
        $date     = $_POST['event_date'] ?? '';
        $start    = $_POST['start_time'] ?? '';
        $end      = $_POST['end_time']   ?? '';
        $location = trim(htmlspecialchars($_POST['location'] ?? '', ENT_QUOTES, 'UTF-8'));
        $sponsors = trim(htmlspecialchars($_POST['sponsors'] ?? '', ENT_QUOTES, 'UTF-8'));
        $contacts = trim(htmlspecialchars($_POST['contacts'] ?? '', ENT_QUOTES, 'UTF-8'));
        $status   = $_POST['status']     ?? 'Available';

        // Validate
        if ($title === '' || $date === '') {
            throw new Exception("Title and Event Date are required.");
        }
        if (!in_array($status, ['Available', 'Pending Approval', 'Booked'])) {
            $status = 'Available';
        }

        // Auto-set status: if sponsor is present, mark as Booked
        if ($sponsors !== '') {
            $status = 'Booked';
        }

        if (isset($_POST['edit_id']) && $_POST['edit_id'] !== '') {
            // UPDATE
            $stmt = $pdo->prepare("UPDATE events SET
                title       = :title,
                description = :desc,
                event_date  = :edate,
                start_time  = :stime,
                end_time    = :etime,
                location    = :loc,
                sponsors    = :sponsors,
                contacts    = :contacts,
                status      = :status
                WHERE id = :id");
            $stmt->execute([
                ':title'    => $title,
                ':desc'     => $desc,
                ':edate'    => $date,
                ':stime'    => $start ?: null,
                ':etime'    => $end   ?: null,
                ':loc'      => $location,
                ':sponsors' => $sponsors,
                ':contacts' => $contacts,
                ':status'   => $status,
                ':id'       => (int)$_POST['edit_id']
            ]);
            $message = "Event updated successfully.";
        } else {
            // INSERT
            $stmt = $pdo->prepare("INSERT INTO events
                (title, description, event_date, start_time, end_time, location, sponsors, contacts, status)
                VALUES (:title, :desc, :edate, :stime, :etime, :loc, :sponsors, :contacts, :status)");
            $stmt->execute([
                ':title'    => $title,
                ':desc'     => $desc,
                ':edate'    => $date,
                ':stime'    => $start ?: null,
                ':etime'    => $end   ?: null,
                ':loc'      => $location,
                ':sponsors' => $sponsors,
                ':contacts' => $contacts,
                ':status'   => $status
            ]);
            $message = "Event created successfully.";
        }
        $reloadPage = true;
    }

    // ── EDIT: load row into form ────────────────────────────
    if (isset($_GET['edit'])) {
        $edit_id = (int)$_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
        $stmt->execute([':id' => $edit_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $f_title    = $row['title']      ?? '';
            $f_desc     = $row['description'] ?? '';
            $f_date     = $row['event_date']  ?? '';
            $f_start    = $row['start_time']  ?? '';
            $f_end      = $row['end_time']    ?? '';
            $f_location = $row['location']    ?? '';
            $f_sponsors = $row['sponsors']    ?? '';
            $f_contacts = $row['contacts']    ?? '';
            $f_status   = $row['status']      ?? 'Available';
        }
    }

    // ── Fetch all events for the table ──────────────────────
    // Search / filter
    $filterDate = $_GET['filter_date'] ?? '';
    $search     = trim($_GET['search'] ?? '');

    $sql    = "SELECT e.* FROM events e WHERE 1=1";
    $params = [];

    if ($filterDate !== '') {
        $sql .= " AND e.event_date = :fd";
        $params[':fd'] = $filterDate;
    }
    if ($search !== '') {
        $sql .= " AND (e.title LIKE :s OR e.description LIKE :s2)";
        $params[':s']  = "%$search%";
        $params[':s2'] = "%$search%";
    }
    $sql .= " ORDER BY e.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = $e->getMessage();
    $msgType = "error";
    $events  = $events ?? [];
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Event Management</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <style>
        .badge-available  { background:#28a745; color:#fff; }
        .badge-pending    { background:#ffc107; color:#333; }
        .badge-booked     { background:#dc3545; color:#fff; }
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
    <h1 class="h3 mb-0 text-gray-800">Event Management</h1>
</div>

<!-- ═══ FILTER BAR ═══ -->
<div class="card shadow mb-4">
    <div class="card-body py-2">
        <form method="GET" class="form-inline">
            <input type="date" name="filter_date" class="form-control form-control-sm mr-2"
                   value="<?= htmlspecialchars($filterDate) ?>" placeholder="Filter by date">
            <input type="text" name="search" class="form-control form-control-sm mr-2"
                   value="<?= htmlspecialchars($search) ?>" placeholder="Search title...">
            <button class="btn btn-sm btn-primary mr-2"><i class="fas fa-search"></i> Filter</button>
            <a href="eventManagement.php" class="btn btn-sm btn-secondary">Clear</a>
        </form>
    </div>
</div>

<!-- ═══ EVENTS TABLE ═══ -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">Events List</h6>
        <a href="#eventForm" class="btn btn-sm btn-success"><i class="fas fa-plus"></i> New Event</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-bordered table-hover" id="eventsTable" width="100%">
            <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Sponsors</th>
                    <th>Contacts</th>
                    <th>Status</th>
                    <th style="min-width:130px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $i => $ev): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($ev['title']) ?></td>
                    <td><?= date('d M Y', strtotime($ev['event_date'])) ?></td>
                    <td><?= htmlspecialchars($ev['sponsors'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($ev['contacts'] ?? '—') ?></td>
                    <td>
                        <?php
                        $isBooked = !empty($ev['sponsors']) || $ev['status'] === 'Booked';
                        $badgeClass = 'badge-available';
                        $icon = '✅';
                        $displayStatus = 'Available';
                        if ($ev['status'] === 'Pending Approval') { $badgeClass = 'badge-pending'; $icon = '⏳'; $displayStatus = 'Pending Approval'; }
                        elseif ($isBooked) { $badgeClass = 'badge-booked'; $icon = '❌'; $displayStatus = 'Booked'; }
                        ?>
                        <span class="badge <?= $badgeClass ?>"><?= $icon ?> <?= $displayStatus ?></span>
                    </td>
                    <td>
                        <a href="eventManagement.php?edit=<?= $ev['id'] ?>#eventForm" class="btn btn-info btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                        <a href="#" class="btn btn-danger btn-sm delete-btn" data-id="<?= $ev['id'] ?>" title="Delete"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- ═══ EVENT FORM (Create / Edit) ═══ -->
<div class="card shadow mb-4" id="eventForm">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary"><?= $edit_id ? 'Edit Event #'.$edit_id : 'Create New Event' ?></h6>
    </div>
    <div class="card-body">
        <form method="POST" action="eventManagement.php" id="eventFormInner">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <?php if ($edit_id): ?>
                <input type="hidden" name="edit_id" value="<?= $edit_id ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required
                               value="<?= htmlspecialchars($f_title) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Event Date <span class="text-danger">*</span></label>
                        <input type="date" name="event_date" class="form-control" required
                               value="<?= htmlspecialchars($f_date) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="Available"        <?= $f_status==='Available'?'selected':'' ?>>Available</option>
                            <option value="Pending Approval" <?= $f_status==='Pending Approval'?'selected':'' ?>>Pending Approval</option>
                            <option value="Booked"           <?= $f_status==='Booked'?'selected':'' ?>>Booked</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Start Time</label>
                        <input type="time" name="start_time" class="form-control"
                               value="<?= htmlspecialchars($f_start) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>End Time</label>
                        <input type="time" name="end_time" class="form-control"
                               value="<?= htmlspecialchars($f_end) ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Location</label>
                        <input type="text" name="location" class="form-control"
                               value="<?= htmlspecialchars($f_location) ?>">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Sponsors</label>
                        <input type="text" name="sponsors" class="form-control"
                               value="<?= htmlspecialchars($f_sponsors) ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Contacts</label>
                        <input type="text" name="contacts" class="form-control"
                               value="<?= htmlspecialchars($f_contacts) ?>" placeholder="e.g. 0402 096 551">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($f_desc) ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-save"></i> <?= $edit_id ? 'Update Event' : 'Create Event' ?>
            </button>
            <?php if ($edit_id): ?>
                <a href="eventManagement.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </form>
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

</div><!-- content-wrapper -->
</div><!-- wrapper -->

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
<script>
$(document).ready(function() {
    $('#eventsTable').DataTable({ pageLength: 25, order: [[0, 'asc']] });
});

// Delete with confirmation
document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const id = this.dataset.id;
        Swal.fire({
            title: 'Delete this event?',
            text: 'This will also delete all related bookings.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it'
        }).then(result => {
            if (result.isConfirmed) {
                window.location.href = 'eventManagement.php?delete=' + id;
            }
        });
    });
});

// Loading state on submit
document.getElementById('eventFormInner').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
});

<?php if ($message): ?>
Swal.fire({
    icon: '<?= $msgType ?>',
    title: '<?= addslashes($message) ?>',
    showConfirmButton: false,
    timer: 1800
}).then(() => {
    <?php if ($reloadPage): ?>window.location.href = 'eventManagement.php';<?php endif; ?>
});
<?php endif; ?>
</script>

</body>
</html>
