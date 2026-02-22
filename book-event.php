<?php
require_once "include/config.php";
require_once "include/mailer.php";

$message = "";
$msgType = "success";
$event   = null;
$submitted = false;

if (!isset($_SESSION['booking_timestamps'])) {
    $_SESSION['booking_timestamps'] = [];
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($eventId <= 0) { header('Location: events.php'); exit; }

    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) { header('Location: events.php'); exit; }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            throw new Exception("Invalid request. Please try again.");
        }

        $now = time();
        $_SESSION['booking_timestamps'] = array_filter($_SESSION['booking_timestamps'], fn($t) => ($now - $t) < 600);
        if (count($_SESSION['booking_timestamps']) >= 3) {
            throw new Exception("Too many booking requests. Please try again in a few minutes.");
        }

        $stmt = $pdo->prepare("SELECT status, sponsors FROM events WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $eventId]);
        $eventRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($eventRow['status'] === 'Booked' || !empty($eventRow['sponsors'])) {
            throw new Exception("Sorry, this event has already been booked/sponsored.");
        }

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

        $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE event_id = :eid AND email = :email AND status != 'Rejected'");
        $stmtDup->execute([':eid' => $eventId, ':email' => $email]);
        if ((int)$stmtDup->fetchColumn() > 0) {
            throw new Exception("You have already submitted a booking request for this event.");
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO bookings (event_id, name, address, phone, email, message, status)
                                VALUES (:eid, :name, :addr, :phone, :email, :msg, 'Pending')");
        $stmt->execute([
            ':eid' => $eventId, ':name' => $name, ':addr' => $address,
            ':phone' => $phone, ':email' => $email, ':msg' => $msg
        ]);

        $stmt = $pdo->prepare("UPDATE events SET status = 'Pending Approval' WHERE id = :id AND status = 'Available'");
        $stmt->execute([':id' => $eventId]);

        $pdo->commit();
        $_SESSION['booking_timestamps'][] = $now;

        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
        $stmt->execute([':id' => $eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        $submitted = true;
        $message = "Your booking request has been submitted successfully! You will receive an email once it is reviewed.";

        // Admin notification
        $adminBody = "<h2>New Booking Request</h2>
        <table style='border-collapse:collapse;width:100%;'>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Event:</strong></td><td style='padding:8px;border:1px solid #ddd;'>{$event['title']}</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Date:</strong></td><td style='padding:8px;border:1px solid #ddd;'>" . date('d M Y', strtotime($event['event_date'])) . "</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Requester:</strong></td><td style='padding:8px;border:1px solid #ddd;'>$name</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Email:</strong></td><td style='padding:8px;border:1px solid #ddd;'>$email</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Phone:</strong></td><td style='padding:8px;border:1px solid #ddd;'>$phone</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Address:</strong></td><td style='padding:8px;border:1px solid #ddd;'>$address</td></tr>
            <tr><td style='padding:8px;border:1px solid #ddd;'><strong>Message:</strong></td><td style='padding:8px;border:1px solid #ddd;'>$msg</td></tr>
        </table><p>Please log in to the admin panel to approve or reject this booking.</p>";
        send_mail(MAIL_FROM_EMAIL, MAIL_FROM_NAME, "New Booking Request – " . $event['title'], $adminBody);

        // User confirmation
        $userBody = "<h2>Booking Request Received</h2>
        <p>Dear $name,</p>
        <p>Thank you for your booking request for <strong>{$event['title']}</strong> on <strong>" . date('d M Y', strtotime($event['event_date'])) . "</strong>.</p>
        <p>Your request is now <strong>Pending Approval</strong>. We will notify you via email once it has been reviewed.</p>
        <br><p>Best regards,<br>Bhutanese Buddhist & Cultural Centre, Canberra</p>";
        send_mail($email, $name, "Booking Request Received – " . $event['title'], $userBody);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $message = $e->getMessage();
    $msgType = "error";
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$isEventBooked = $event && (!empty($event['sponsors']) || $event['status'] === 'Booked');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($event['title'] ?? 'Event') ?> — BBCC</title>
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .bk-grid { display: grid; grid-template-columns: 5fr 7fr; gap: 40px; align-items: flex-start; }
        .bk-detail {
            background: var(--white); border: 1px solid var(--gray-200); border-radius: var(--radius-lg); overflow: hidden;
        }
        .bk-detail__header {
            background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; padding: 24px 28px;
        }
        .bk-detail__header h3 { font-size: 1.3rem; font-weight: 700; margin: 0 0 8px; color: #fff; }
        .bk-detail__body { padding: 24px 28px; }
        .bk-detail__body table { width: 100%; }
        .bk-detail__body td { padding: 10px 0; font-size: .92rem; vertical-align: top; color: var(--gray-700); }
        .bk-detail__body td:first-child { width: 40%; font-weight: 600; color: var(--gray-900); }
        .bk-detail__body td i { color: var(--brand); margin-right: 6px; font-size: .8rem; }
        .bk-detail__body .desc { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--gray-200); font-size: .92rem; color: var(--gray-600); line-height: 1.7; }

        .bk-status { display: inline-flex; align-items: center; gap: 4px; padding: 5px 14px; border-radius: var(--radius-full); font-size: .8rem; font-weight: 600; }
        .bk-status--avail { background: rgba(255,255,255,.2); }
        .bk-status--pend { background: #ffc107; color: #333; }
        .bk-status--booked { background: #ef4444; color: #fff; }

        .bk-form { background: var(--gray-100); border-radius: var(--radius-lg); padding: 32px; }
        .bk-form h4 { font-size: 1.15rem; font-weight: 700; margin-bottom: 24px; }
        .bk-form .fg { margin-bottom: 18px; }
        .bk-form label { display: block; font-size: .8rem; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .3px; }
        .bk-form input, .bk-form textarea {
            width: 100%; padding: 12px 16px; border: 1.5px solid var(--gray-200); border-radius: var(--radius-md);
            font-size: .92rem; font-family: var(--font-body); color: var(--gray-900); background: #fff;
            transition: var(--transition-fast);
        }
        .bk-form input:focus, .bk-form textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(136,27,18,.08); }
        .bk-form textarea { min-height: 100px; resize: vertical; }
        .bk-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .req { color: var(--brand); }

        .success-card { text-align: center; padding: 48px; background: #ecfdf5; border-radius: var(--radius-lg); border: 1px solid #a7f3d0; }
        .success-card i { font-size: 3rem; color: var(--success); }
        .success-card h3 { margin: 16px 0 8px; }
        .success-card p { color: var(--gray-600); max-width: 500px; margin: 0 auto 24px; }

        .error-card { text-align: center; padding: 48px; background: #fef2f2; border-radius: var(--radius-lg); border: 1px solid #fecaca; }
        .error-card i { font-size: 3rem; color: #ef4444; }

        .alert-err { background: #fef2f2; border-left: 4px solid var(--brand); color: #7f1d1d; padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 20px; font-size: .9rem; }

        @media (max-width: 991px) { .bk-grid { grid-template-columns: 1fr; } }
        @media (max-width: 576px) { .bk-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-ticket"></i> <?= htmlspecialchars($event['title'] ?? 'Book Event') ?></h1>
        <p class="bbcc-page-hero__subtitle">Submit a booking or sponsorship request</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index.php">Home</a></li>
            <li class="sep">/</li>
            <li><a href="events.php">Events</a></li>
            <li class="sep">/</li>
            <li>Book</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="bk-grid">

            <!-- Event Details -->
            <div>
                <div class="bk-detail fade-up">
                    <div class="bk-detail__header">
                        <h3><?= htmlspecialchars($event['title']) ?></h3>
                        <?php
                        if ($event['status'] === 'Pending Approval') {
                            echo '<span class="bk-status bk-status--pend"><i class="fa-solid fa-hourglass-half"></i> Pending Approval</span>';
                        } elseif ($isEventBooked) {
                            echo '<span class="bk-status bk-status--booked"><i class="fa-solid fa-xmark"></i> Booked</span>';
                        } else {
                            echo '<span class="bk-status bk-status--avail"><i class="fa-solid fa-check"></i> Available</span>';
                        }
                        ?>
                    </div>
                    <div class="bk-detail__body">
                        <table>
                            <tr>
                                <td><i class="fa-regular fa-calendar"></i> Date</td>
                                <td><?= date('l, d F Y', strtotime($event['event_date'])) ?></td>
                            </tr>
                            <tr>
                                <td><i class="fa-regular fa-clock"></i> Time</td>
                                <td>
                                    <?= $event['start_time'] ? date('h:i A', strtotime($event['start_time'])) : 'TBA' ?>
                                    <?= $event['end_time'] ? ' – '.date('h:i A', strtotime($event['end_time'])) : '' ?>
                                </td>
                            </tr>
                            <?php if ($event['location']): ?>
                            <tr>
                                <td><i class="fa-solid fa-location-dot"></i> Location</td>
                                <td><?= htmlspecialchars($event['location']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($event['sponsors']): ?>
                            <tr>
                                <td><i class="fa-solid fa-user"></i> Sponsors</td>
                                <td><?= htmlspecialchars($event['sponsors']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($event['contacts']): ?>
                            <tr>
                                <td><i class="fa-solid fa-phone"></i> Contact</td>
                                <td><?= htmlspecialchars($event['contacts']) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                        <?php if ($event['description']): ?>
                        <div class="desc"><?= nl2br(htmlspecialchars($event['description'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="events.php" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm" style="margin-top:16px;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Events
                </a>
            </div>

            <!-- Booking Form -->
            <div>
                <?php if ($submitted): ?>
                <div class="success-card fade-up">
                    <i class="fa-solid fa-circle-check"></i>
                    <h3>Thank You!</h3>
                    <p><?= $message ?></p>
                    <a href="events.php" class="bbcc-btn bbcc-btn--primary">Browse More Events</a>
                </div>

                <?php elseif ($isEventBooked): ?>
                <div class="error-card fade-up">
                    <i class="fa-solid fa-circle-xmark"></i>
                    <h3 style="margin:16px 0 8px;">This Event is Already Booked</h3>
                    <p style="color:var(--gray-600);margin-bottom:24px;">Please check other available events.</p>
                    <a href="events.php" class="bbcc-btn bbcc-btn--primary">Browse Events</a>
                </div>

                <?php else: ?>

                <?php if ($msgType === 'error' && $message): ?>
                <div class="alert-err"><i class="fa-solid fa-exclamation-circle" style="margin-right:6px;"></i><?= $message ?></div>
                <?php endif; ?>

                <div class="bk-form fade-up">
                    <h4><i class="fa-solid fa-ticket" style="color:var(--brand);margin-right:8px;"></i> Booking / Sponsorship Request</h4>
                    <form method="POST" action="book-event.php?id=<?= $event['id'] ?>" id="bookingForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

                        <div class="fg">
                            <label>Full Name <span class="req">*</span></label>
                            <input type="text" name="name" required placeholder="Enter your full name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                        </div>
                        <div class="fg">
                            <label>Address <span class="req">*</span></label>
                            <input type="text" name="address" required placeholder="Enter your address" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                        </div>
                        <div class="bk-row">
                            <div class="fg">
                                <label>Phone Number <span class="req">*</span></label>
                                <input type="tel" name="phone" required placeholder="e.g. 0402 096 551" pattern="[0-9\s\+\-\(\)]{6,20}" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            </div>
                            <div class="fg">
                                <label>Email Address <span class="req">*</span></label>
                                <input type="email" name="email" required placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="fg">
                            <label>Message / Notes (Optional)</label>
                            <textarea name="message" placeholder="Any additional notes..."><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="bbcc-btn bbcc-btn--primary" style="width:100%;justify-content:center;" id="submitBtn">
                            <i class="fa-solid fa-paper-plane"></i> Submit Booking Request
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

<script>
var form = document.getElementById('bookingForm');
if (form) {
    form.addEventListener('submit', function() {
        var btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    });
}
</script>

</body>
</html>
