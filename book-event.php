<?php
require_once "include/config.php";
require_once "include/mailer.php";

$message = "";
$msgType = "success";
$event   = null;
$submitted = false;

// Simple rate limiting via session
if (!isset($_SESSION['booking_timestamps'])) {
    $_SESSION['booking_timestamps'] = [];
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($eventId <= 0) {
        header('Location: events.php');
        exit;
    }

    // Fetch event
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        header('Location: events.php');
        exit;
    }

    // ── HANDLE FORM SUBMISSION ────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // CSRF check
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            throw new Exception("Invalid request. Please try again.");
        }

        // Rate limiting: max 3 bookings per 10 minutes
        $now = time();
        $_SESSION['booking_timestamps'] = array_filter($_SESSION['booking_timestamps'], fn($t) => ($now - $t) < 600);
        if (count($_SESSION['booking_timestamps']) >= 3) {
            throw new Exception("Too many booking requests. Please try again in a few minutes.");
        }

        // Verify event is still available (check both status and sponsors field)
        $stmt = $pdo->prepare("SELECT status, sponsors FROM events WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $eventId]);
        $eventRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($eventRow['status'] === 'Booked' || !empty($eventRow['sponsors'])) {
            throw new Exception("Sorry, this event has already been booked/sponsored.");
        }

        // Sanitize & validate
        $name    = trim(htmlspecialchars($_POST['name']    ?? '', ENT_QUOTES, 'UTF-8'));
        $address = trim(htmlspecialchars($_POST['address'] ?? '', ENT_QUOTES, 'UTF-8'));
        $phone   = trim(htmlspecialchars($_POST['phone']   ?? '', ENT_QUOTES, 'UTF-8'));
        $email   = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
        $msg     = trim(htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8'));

        if ($name === '' || $address === '' || $phone === '' || $email === '') {
            throw new Exception("Please fill in all required fields.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid email address.");
        }
        if (!preg_match('/^[0-9\s\+\-\(\)]{6,20}$/', $phone)) {
            throw new Exception("Please enter a valid phone number.");
        }

        // Check for duplicate booking from same email for same event
        $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE event_id = :eid AND email = :email AND status != 'Rejected'");
        $stmtDup->execute([':eid' => $eventId, ':email' => $email]);
        if ((int)$stmtDup->fetchColumn() > 0) {
            throw new Exception("You have already submitted a booking request for this event.");
        }

        // Insert booking
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO bookings (event_id, name, address, phone, email, message, status)
                                VALUES (:eid, :name, :addr, :phone, :email, :msg, 'Pending')");
        $stmt->execute([
            ':eid'   => $eventId,
            ':name'  => $name,
            ':addr'  => $address,
            ':phone' => $phone,
            ':email' => $email,
            ':msg'   => $msg
        ]);

        // Update event status to Pending Approval
        $stmt = $pdo->prepare("UPDATE events SET status = 'Pending Approval' WHERE id = :id AND status = 'Available'");
        $stmt->execute([':id' => $eventId]);

        $pdo->commit();

        // Rate-limit tracking
        $_SESSION['booking_timestamps'][] = $now;

        // Refresh event data
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
        $stmt->execute([':id' => $eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        $submitted = true;
        $message = "Your booking request has been submitted successfully! You will receive an email once it is reviewed.";

        // Send admin notification email
        $adminBody = "
        <h2>New Booking Request</h2>
        <table style='border-collapse:collapse;width:100%;'>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Event:</strong></td><td style='padding:8px;border:1px solid #ddd;'>{$event['title']}</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Date:</strong></td><td style='padding:8px;border:1px solid #ddd;'>" . date('d M Y', strtotime($event['event_date'])) . "</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Requester:</strong></td><td style='padding:8px;border:1px solid #ddd;'>$name</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Email:</strong></td><td style='padding:8px;border:1px solid #ddd;'>$email</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Phone:</strong></td><td style='padding:8px;border:1px solid #ddd;'>$phone</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Address:</strong></td><td style='padding:8px;border:1px solid #ddd;'>$address</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Message:</strong></td><td style='padding:8px;border:1px solid #ddd;'>$msg</td></tr>
        </table>
        <p>Please log in to the admin panel to approve or reject this booking.</p>";
        send_mail(MAIL_FROM_EMAIL, MAIL_FROM_NAME, "New Booking Request – " . $event['title'], $adminBody);

        // Send user confirmation
        $userBody = "
        <h2>Booking Request Received</h2>
        <p>Dear $name,</p>
        <p>Thank you for your booking request for <strong>{$event['title']}</strong> on <strong>" . date('d M Y', strtotime($event['event_date'])) . "</strong>.</p>
        <p>Your request is now <strong>Pending Approval</strong>. We will notify you via email once it has been reviewed.</p>
        <br>
        <p>Best regards,<br>Bhutanese Buddhist & Cultural Centre, Canberra</p>";
        send_mail($email, $name, "Booking Request Received – " . $event['title'], $userBody);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $message = $e->getMessage();
    $msgType = "error";
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$statusClass = 'status-available';
$statusLabel = '✅ Available';
$isEventBooked = $event && (!empty($event['sponsors']) || $event['status'] === 'Booked');
if ($event && $event['status'] === 'Pending Approval') {
    $statusClass = 'status-pending'; $statusLabel = '⏳ Pending Approval';
} elseif ($isEventBooked) {
    $statusClass = 'status-booked'; $statusLabel = '❌ Booked';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= htmlspecialchars($event['title'] ?? 'Event') ?> – BBCC</title>
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .booking-section { padding:60px 0; }
        .event-detail-card { border:1px solid #e3e6f0; border-radius:10px; overflow:hidden; margin-bottom:30px; }
        .event-detail-header { background:var(--primary-color, #881b12); color:#fff; padding:25px; }
        .event-detail-body { padding:25px; }
        .status-available { background:#28a745; color:#fff; padding:4px 14px; border-radius:12px; font-size:0.85rem; }
        .status-pending   { background:#ffc107; color:#333; padding:4px 14px; border-radius:12px; font-size:0.85rem; }
        .status-booked    { background:#dc3545; color:#fff; padding:4px 14px; border-radius:12px; font-size:0.85rem; }
        .booking-form { background:#f8f9fa; border-radius:10px; padding:30px; }
        .booking-form .form-group label { font-weight:600; }
        .success-box { background:#d4edda; border:1px solid #c3e6cb; border-radius:10px; padding:30px; text-align:center; }
        .success-box i { font-size:3rem; color:#28a745; }
    </style>
</head>
<body>

<?php include_once 'include/nav.php'; ?>

<!-- Breadcrumb -->
<div class="hero_brd_area">
    <div class="container">
        <div class="hero_content">
            <h2 class="wow fadeInUp" data-wow-delay="0.3s"><?= htmlspecialchars($event['title'] ?? 'Event Details') ?></h2>
            <ul class="wow fadeInUp" data-wow-delay="0.5s">
                <li><a href="index.php">Home</a></li>
                <li>/</li>
                <li><a href="events.php">Events</a></li>
                <li>/</li>
                <li>Book Event</li>
            </ul>
        </div>
    </div>
</div>

<div class="booking-section">
    <div class="container">
        <div class="row">

            <!-- Event Details -->
            <div class="col-md-5">
                <div class="event-detail-card">
                    <div class="event-detail-header">
                        <h3 class="mb-2"><?= htmlspecialchars($event['title']) ?></h3>
                        <span class="<?= $statusClass ?>"><?= $statusLabel ?></span>
                    </div>
                    <div class="event-detail-body">
                        <table class="table table-borderless mb-0">
                            <tr>
                                <td><i class="fa fa-calendar"></i> <strong>Date</strong></td>
                                <td><?= date('l, d F Y', strtotime($event['event_date'])) ?></td>
                            </tr>
                            <tr>
                                <td><i class="fa fa-clock"></i> <strong>Time</strong></td>
                                <td>
                                    <?= $event['start_time'] ? date('h:i A', strtotime($event['start_time'])) : 'TBA' ?>
                                    <?= $event['end_time'] ? ' – '.date('h:i A', strtotime($event['end_time'])) : '' ?>
                                </td>
                            </tr>
                            <?php if ($event['location']): ?>
                            <tr>
                                <td><i class="fa fa-map-marker"></i> <strong>Location</strong></td>
                                <td><?= htmlspecialchars($event['location']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($event['sponsors']): ?>
                            <tr>
                                <td><i class="fa fa-user"></i> <strong>Sponsors</strong></td>
                                <td><?= htmlspecialchars($event['sponsors']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($event['contacts']): ?>
                            <tr>
                                <td><i class="fa fa-phone"></i> <strong>Contact</strong></td>
                                <td><?= htmlspecialchars($event['contacts']) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                        <?php if ($event['description']): ?>
                            <hr>
                            <p class="text-muted"><?= nl2br(htmlspecialchars($event['description'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="events.php" class="btn btn-outline-danger">&laquo; Back to Events</a>
            </div>

            <!-- Booking Form -->
            <div class="col-md-7">
                <?php if ($submitted): ?>
                    <div class="success-box">
                        <i class="fa fa-check-circle"></i>
                        <h3 class="mt-3">Thank You!</h3>
                        <p class="lead"><?= $message ?></p>
                        <a href="events.php" class="btn btn-danger mt-3">Browse More Events</a>
                    </div>

                <?php elseif ($isEventBooked): ?>
                    <div class="alert alert-danger text-center py-5">
                        <i class="fa fa-times-circle" style="font-size:2.5rem;"></i>
                        <h4 class="mt-3">This event is already booked</h4>
                        <p>Please check other available events.</p>
                        <a href="events.php" class="btn btn-danger mt-2">Browse Events</a>
                    </div>

                <?php else: ?>
                    <?php if ($msgType === 'error' && $message): ?>
                        <div class="alert alert-danger"><?= $message ?></div>
                    <?php endif; ?>

                    <div class="booking-form">
                        <h4 class="mb-4"><i class="fa fa-ticket"></i> Booking / Sponsorship Request</h4>
                        <form method="POST" action="book-event.php?id=<?= $event['id'] ?>" id="bookingForm">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

                            <div class="form-group">
                                <label>Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required
                                       placeholder="Enter your full name"
                                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>Address <span class="text-danger">*</span></label>
                                <input type="text" name="address" class="form-control" required
                                       placeholder="Enter your address"
                                       value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Phone Number <span class="text-danger">*</span></label>
                                        <input type="tel" name="phone" class="form-control" required
                                               placeholder="e.g. 0402 096 551"
                                               pattern="[0-9\s\+\-\(\)]{6,20}"
                                               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Email Address <span class="text-danger">*</span></label>
                                        <input type="email" name="email" class="form-control" required
                                               placeholder="you@example.com"
                                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Message / Notes (Optional)</label>
                                <textarea name="message" class="form-control" rows="3"
                                          placeholder="Any additional notes..."><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                            </div>

                            <button type="submit" class="btn btn-danger btn-lg btn-block" id="submitBtn">
                                <i class="fa fa-paper-plane"></i> Submit Booking Request
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>

<script>
// Loading state on submit
var form = document.getElementById('bookingForm');
if (form) {
    form.addEventListener('submit', function() {
        var btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting...';
    });
}
</script>

</body>
</html>
