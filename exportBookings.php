<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $eventFilter = isset($_GET['event_id']) ? (int)$_GET['event_id'] : null;

    if ($eventFilter) {
        $stmt = $pdo->prepare("
            SELECT b.id, e.title AS event_title, e.event_date, e.start_time, e.end_time, e.location,
                   b.name, b.email, b.phone, b.address, b.message, b.status, b.created_at
            FROM bookings b
            JOIN events e ON e.id = b.event_id
            WHERE b.event_id = :eid
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([':eid' => $eventFilter]);
        $filename = "bookings_event_{$eventFilter}_" . date('Ymd') . ".csv";
    } else {
        $stmt = $pdo->query("
            SELECT b.id, e.title AS event_title, e.event_date, e.start_time, e.end_time, e.location,
                   b.name, b.email, b.phone, b.address, b.message, b.status, b.created_at
            FROM bookings b
            JOIN events e ON e.id = b.event_id
            ORDER BY e.event_date ASC, b.created_at DESC
        ");
        $filename = "all_bookings_" . date('Ymd') . ".csv";
    }

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Output CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Header row
    fputcsv($output, [
        'Booking ID', 'Event', 'Event Date', 'Start Time', 'End Time', 'Location',
        'Name', 'Email', 'Phone', 'Address', 'Message', 'Booking Status', 'Submitted At'
    ]);

    foreach ($bookings as $row) {
        fputcsv($output, [
            $row['id'],
            $row['event_title'],
            $row['event_date'],
            $row['start_time'],
            $row['end_time'],
            $row['location'],
            $row['name'],
            $row['email'],
            $row['phone'],
            $row['address'],
            $row['message'],
            $row['status'],
            $row['created_at']
        ]);
    }

    fclose($output);
    exit;

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    exit;
}
