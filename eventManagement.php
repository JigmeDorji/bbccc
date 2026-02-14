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

        /* ── Modal Professional Styling ── */
        #eventModal .modal-dialog { max-width: 720px; }
        #eventModal .modal-content {
            border: none;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.15);
        }
        #eventModal .modal-header {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            color: #fff;
            border-bottom: none;
            padding: 1.25rem 1.5rem;
        }
        #eventModal .modal-header .modal-title {
            font-weight: 700;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        #eventModal .modal-header .close,
        #eventModal .modal-header .btn-close-custom {
            color: #fff;
            opacity: .85;
            font-size: 1.4rem;
            background: none;
            border: none;
            cursor: pointer;
            transition: opacity .2s;
        }
        #eventModal .modal-header .btn-close-custom:hover { opacity: 1; }
        #eventModal .modal-body {
            padding: 1.75rem 1.5rem 1rem;
            background: #f8f9fc;
        }
        #eventModal .modal-body .form-group { margin-bottom: 1rem; }
        #eventModal .modal-body label {
            font-weight: 600;
            font-size: .82rem;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #5a5c69;
            margin-bottom: .3rem;
        }
        #eventModal .modal-body .form-control {
            border-radius: 8px;
            border: 1px solid #d1d3e2;
            padding: .55rem .85rem;
            font-size: .9rem;
            transition: border-color .2s, box-shadow .2s;
        }
        #eventModal .modal-body .form-control:focus {
            border-color: #4e73df;
            box-shadow: 0 0 0 3px rgba(78,115,223,.15);
        }
        #eventModal .modal-body textarea.form-control { resize: vertical; min-height: 80px; }
        #eventModal .modal-body .section-divider {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            color: #b7b9cc;
            margin: .75rem 0 .5rem;
            padding-bottom: .35rem;
            border-bottom: 1px solid #e3e6f0;
        }
        #eventModal .modal-footer {
            background: #fff;
            border-top: 1px solid #e3e6f0;
            padding: 1rem 1.5rem;
        }
        #eventModal .modal-footer .btn { border-radius: 8px; padding: .5rem 1.5rem; font-weight: 600; }
        #eventModal .btn-create {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            border: none; color: #fff;
        }
        #eventModal .btn-create:hover { background: linear-gradient(135deg, #224abe 0%, #1a339a 100%); }
        #eventModal .btn-update {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            border: none; color: #fff;
        }
        #eventModal .btn-update:hover { background: linear-gradient(135deg, #224abe 0%, #1a339a 100%); }
        #eventModal .btn-cancel-modal {
            background: #e3e6f0; color: #5a5c69; border: none;
        }
        #eventModal .btn-cancel-modal:hover { background: #d1d3e2; }

        /* ── New Event button ── */
        .btn-new-event {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            border: none; color: #fff; border-radius: 8px;
            font-weight: 600; padding: .45rem 1.1rem;
            transition: transform .15s, box-shadow .2s;
        }
        .btn-new-event:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(78,115,223,.35);
            color: #fff;
        }
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
        <button type="button" class="btn btn-sm btn-new-event" id="btnNewEvent" aria-label="Create a new event">
            <i class="fas fa-plus-circle mr-1" aria-hidden="true"></i> New Event
        </button>
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
                        <button type="button" class="btn btn-primary btn-sm edit-btn"
                                data-id="<?= $ev['id'] ?>"
                                data-title="<?= htmlspecialchars($ev['title']) ?>"
                                data-desc="<?= htmlspecialchars($ev['description'] ?? '') ?>"
                                data-date="<?= htmlspecialchars($ev['event_date']) ?>"
                                data-start="<?= htmlspecialchars($ev['start_time'] ?? '') ?>"
                                data-end="<?= htmlspecialchars($ev['end_time'] ?? '') ?>"
                                data-location="<?= htmlspecialchars($ev['location'] ?? '') ?>"
                                data-sponsors="<?= htmlspecialchars($ev['sponsors'] ?? '') ?>"
                                data-contacts="<?= htmlspecialchars($ev['contacts'] ?? '') ?>"
                                data-status="<?= htmlspecialchars($ev['status']) ?>"
                                title="Edit event" aria-label="Edit event <?= htmlspecialchars($ev['title']) ?>"><i class="fas fa-edit" aria-hidden="true"></i></button>
                        <a href="#" class="btn btn-danger btn-sm delete-btn" data-id="<?= $ev['id'] ?>" title="Delete event" aria-label="Delete event <?= htmlspecialchars($ev['title']) ?>"><i class="fas fa-trash" aria-hidden="true"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- ═══ EVENT MODAL (Create / Edit) ═══ -->
<div class="modal fade" id="eventModal" tabindex="-1" role="dialog" aria-labelledby="eventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="eventModalLabel">
                    <i class="fas fa-calendar-plus" id="modalIcon"></i>
                    <span id="modalTitleText">Create New Event</span>
                </h5>
                <button type="button" class="btn-close-custom" data-dismiss="modal" aria-label="Close dialog">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <form method="POST" action="eventManagement.php" id="eventFormInner">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="edit_id" id="modal_edit_id" value="">

                    <div class="section-divider"><i class="fas fa-info-circle mr-1"></i> Event Details</div>
                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group">
                                <label>Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="modal_title" class="form-control" required
                                       placeholder="Enter event title">
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>Event Date <span class="text-danger">*</span></label>
                                <input type="date" name="event_date" id="modal_date" class="form-control" required>
                            </div>
                        </div>
                    </div>

                    <div class="section-divider"><i class="fas fa-clock mr-1"></i> Schedule & Location</div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Start Time</label>
                                <input type="time" name="start_time" id="modal_start" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>End Time</label>
                                <input type="time" name="end_time" id="modal_end" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="modal_status" class="form-control">
                                    <option value="Available">Available</option>
                                    <option value="Pending Approval">Pending Approval</option>
                                    <option value="Booked">Booked</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Location</label>
                        <input type="text" name="location" id="modal_location" class="form-control"
                               placeholder="e.g. BBCC Hall">
                    </div>

                    <div class="section-divider"><i class="fas fa-users mr-1"></i> Sponsor & Contact</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Sponsors</label>
                                <input type="text" name="sponsors" id="modal_sponsors" class="form-control"
                                       placeholder="Sponsor name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Contacts</label>
                                <input type="text" name="contacts" id="modal_contacts" class="form-control"
                                       placeholder="e.g. 0402 096 551">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="modal_desc" class="form-control" rows="3"
                                  placeholder="Brief event description..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel-modal" data-dismiss="modal">
                        <i class="fas fa-times mr-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn" id="submitBtn">
                        <i class="fas fa-save mr-1"></i> <span id="submitBtnText">Create Event</span>
                    </button>
                </div>
            </form>
        </div>
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

    // ── NEW EVENT: reset form and open modal ──
    $('#btnNewEvent').on('click', function() {
        $('#modal_edit_id').val('');
        $('#modal_title').val('');
        $('#modal_date').val('');
        $('#modal_start').val('');
        $('#modal_end').val('');
        $('#modal_location').val('');
        $('#modal_sponsors').val('');
        $('#modal_contacts').val('');
        $('#modal_status').val('Available');
        $('#modal_desc').val('');
        $('#modalTitleText').text('Create New Event');
        $('#modalIcon').attr('class', 'fas fa-calendar-plus');
        $('#submitBtn').removeClass('btn-update').addClass('btn-create');
        $('#submitBtnText').text('Create Event');
        $('#eventModal').modal('show');
    });

    // ── EDIT EVENT: populate form and open modal ──
    $(document).on('click', '.edit-btn', function() {
        var btn = $(this);
        $('#modal_edit_id').val(btn.data('id'));
        $('#modal_title').val(btn.data('title'));
        $('#modal_date').val(btn.data('date'));
        $('#modal_start').val(btn.data('start') || '');
        $('#modal_end').val(btn.data('end') || '');
        $('#modal_location').val(btn.data('location') || '');
        $('#modal_sponsors').val(btn.data('sponsors') || '');
        $('#modal_contacts').val(btn.data('contacts') || '');
        $('#modal_status').val(btn.data('status'));
        $('#modal_desc').val(btn.data('desc') || '');
        $('#modalTitleText').text('Edit Event #' + btn.data('id'));
        $('#modalIcon').attr('class', 'fas fa-calendar-check');
        $('#submitBtn').removeClass('btn-create').addClass('btn-update');
        $('#submitBtnText').text('Update Event');
        $('#eventModal').modal('show');
    });

    // ── Loading state on submit ──
    $('#eventFormInner').on('submit', function() {
        var btn = $('#submitBtn');
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    });
});

// ── Delete with confirmation ──
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

<?php if ($edit_id): ?>
// Auto-open modal in edit mode when loaded via ?edit=
$(document).ready(function() {
    $('#modal_edit_id').val('<?= $edit_id ?>');
    $('#modal_title').val(<?= json_encode($f_title) ?>);
    $('#modal_date').val(<?= json_encode($f_date) ?>);
    $('#modal_start').val(<?= json_encode($f_start) ?>);
    $('#modal_end').val(<?= json_encode($f_end) ?>);
    $('#modal_location').val(<?= json_encode($f_location) ?>);
    $('#modal_sponsors').val(<?= json_encode($f_sponsors) ?>);
    $('#modal_contacts').val(<?= json_encode($f_contacts) ?>);
    $('#modal_status').val(<?= json_encode($f_status) ?>);
    $('#modal_desc').val(<?= json_encode($f_desc) ?>);
    $('#modalTitleText').text('Edit Event #<?= $edit_id ?>');
    $('#modalIcon').attr('class', 'fas fa-calendar-check');
    $('#submitBtn').removeClass('btn-create').addClass('btn-update');
    $('#submitBtnText').text('Update Event');
    $('#eventModal').modal('show');
});
<?php endif; ?>
</script>

</body>
</html>
