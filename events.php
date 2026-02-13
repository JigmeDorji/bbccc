<?php
require_once "include/config.php";
date_default_timezone_set('Australia/Melbourne');

$events = [];
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $filterYear  = $_GET['year']  ?? date('Y');
    $filterMonth = $_GET['month'] ?? '';
    $search      = trim($_GET['search'] ?? '');

    $sql    = "SELECT * FROM events WHERE 1=1";
    $params = [];

    $sql .= " AND YEAR(event_date) = :yr";
    $params[':yr'] = (int)$filterYear;

    if ($filterMonth !== '') {
        $sql .= " AND MONTH(event_date) = :mo";
        $params[':mo'] = (int)$filterMonth;
    }
    if ($search !== '') {
        $sql .= " AND (title LIKE :s OR description LIKE :s2)";
        $params[':s']  = "%$search%";
        $params[':s2'] = "%$search%";
    }
    $sql .= " ORDER BY event_date ASC, start_time ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build calendar data (events indexed by date)
    $calendarEvents = [];
    foreach ($events as $ev) {
        $calendarEvents[$ev['event_date']][] = $ev;
    }

} catch (Exception $e) {
    $events = [];
    $calendarEvents = [];
}

// Determine calendar month/year to display
$calYear  = (int)($filterYear ?: date('Y'));
$calMonth = $filterMonth !== '' ? (int)$filterMonth : (int)date('m');

// Use DateTime for reliable day-of-week calculation
$firstDayObj = new DateTime(sprintf('%04d-%02d-01', $calYear, $calMonth));
$daysInMonth = (int)$firstDayObj->format('t');
$startDow    = (int)$firstDayObj->format('w'); // 0=Sun
$monthName   = $firstDayObj->format('F');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Events – BBCC</title>
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .events-section { padding: 60px 0; }
        /* View toggle */
        .view-toggle .btn { margin: 0 2px; }
        .view-toggle .btn.active { background: var(--primary-color, #881b12); color: #fff; border-color: var(--primary-color, #881b12); }

        /* Status badges */
        .status-available { background:#28a745; color:#fff; padding:3px 10px; border-radius:12px; font-size:0.8rem; }
        .status-pending   { background:#ffc107; color:#333; padding:3px 10px; border-radius:12px; font-size:0.8rem; }
        .status-booked    { background:#dc3545; color:#fff; padding:3px 10px; border-radius:12px; font-size:0.8rem; }

        /* List view cards */
        .event-card { border:1px solid #e3e6f0; border-radius:8px; margin-bottom:15px; transition:0.3s; overflow:hidden; }
        .event-card:hover { box-shadow:0 4px 20px rgba(0,0,0,0.1); transform:translateY(-2px); }
        .event-card .card-left { background:var(--primary-color, #881b12); color:#fff; padding:15px; text-align:center; min-width:100px; display:flex; flex-direction:column; justify-content:center; }
        .event-card .card-left .day { font-size:2rem; font-weight:700; line-height:1; }
        .event-card .card-left .month { text-transform:uppercase; font-size:0.85rem; }
        .event-card .card-right { padding:15px; flex:1; }
        .event-card .card-right h5 { margin-bottom:5px; }

        /* Calendar view */
        .cal-table { width:100%; border-collapse:collapse; table-layout:fixed; }
        .cal-table th { background:#881b12; color:#fff; padding:8px; text-align:center; font-size:0.85rem; }
        .cal-table td { border:1px solid #ddd; vertical-align:top; padding:4px; height:100px; font-size:0.8rem; }
        .cal-table td .day-num { font-weight:700; margin-bottom:3px; }
        .cal-table td.today { background:#fff8e1; }
        .cal-table td.empty { background:#f8f9fa; }
        .cal-event { display:block; padding:2px 4px; margin-bottom:2px; border-radius:3px; font-size:0.75rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer; text-decoration:none; color:#fff; }
        .cal-event.avail  { background:#28a745; }
        .cal-event.pend   { background:#ffc107; color:#333; }
        .cal-event.booked { background:#dc3545; }

        .filter-bar { background:#f8f9fa; border-radius:8px; padding:15px; margin-bottom:25px; }
        .sponsor-text { font-size: 0.85rem; color: #555; }

        @media (max-width:768px) {
            .cal-table td { height:70px; font-size:0.7rem; }
            .event-card .card-left { min-width:70px; padding:10px; }
        }
    </style>
</head>
<body>

<?php include_once 'include/nav.php'; ?>

<!-- Breadcrumb -->
<div class="hero_brd_area">
    <div class="container">
        <div class="hero_content">
            <h2 class="wow fadeInUp" data-wow-delay="0.3s">Events Calendar 2026</h2>
            <ul class="wow fadeInUp" data-wow-delay="0.5s">
                <li><a href="index.php">Home</a></li>
                <li>/</li>
                <li>Events</li>
            </ul>
        </div>
    </div>
</div>

<div class="events-section">
    <div class="container">

        <!-- Section Title -->
        <div class="section_title text-center mb-4">
            <h2>BBCC Events <span>Calendar <?= $calYear ?></span></h2>
            <p>To sponsor an event, contact Khenchen, Khenpo Sonam or Program Coordinator Namgay</p>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" class="row align-items-end">
                <div class="col-md-2 mb-2">
                    <label class="small font-weight-bold">Year</label>
                    <select name="year" class="form-control form-control-sm">
                        <?php for ($y = 2025; $y <= 2030; $y++): ?>
                            <option value="<?= $y ?>" <?= $calYear==$y?'selected':'' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <label class="small font-weight-bold">Month</label>
                    <select name="month" class="form-control form-control-sm">
                        <option value="">All Months</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $calMonth==$m && $filterMonth!==''?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-2">
                    <label class="small font-weight-bold">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search events..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2 mb-2">
                    <button class="btn btn-sm btn-danger btn-block">Filter</button>
                </div>
                <div class="col-md-2 mb-2">
                    <a href="events.php" class="btn btn-sm btn-outline-secondary btn-block">Reset</a>
                </div>
            </form>
        </div>

        <!-- View Toggle -->
        <div class="text-right mb-3 view-toggle">
            <button class="btn btn-sm btn-outline-dark active" id="btnListView"><i class="fa fa-list"></i> List</button>
            <button class="btn btn-sm btn-outline-dark" id="btnCalView"><i class="fa fa-calendar"></i> Calendar</button>
        </div>

        <!-- ═══ LIST VIEW ═══ -->
        <div id="listView">
            <?php if (empty($events)): ?>
                <div class="text-center py-5">
                    <i class="fa fa-calendar-times" style="font-size:3rem;color:#ccc;"></i>
                    <p class="mt-3 text-muted">No events found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($events as $ev): ?>
                    <div class="event-card d-flex flex-column flex-md-row">
                        <div class="card-left">
                            <div class="day"><?= date('d', strtotime($ev['event_date'])) ?></div>
                            <div class="month"><?= date('M', strtotime($ev['event_date'])) ?></div>
                            <div class="small"><?= date('l', strtotime($ev['event_date'])) ?></div>
                        </div>
                        <div class="card-right d-flex flex-column flex-md-row justify-content-between w-100">
                            <div class="flex-grow-1">
                                <h5><?= htmlspecialchars($ev['title']) ?></h5>
                                <?php if ($ev['description']): ?>
                                    <p class="text-muted small mb-1"><?= htmlspecialchars($ev['description']) ?></p>
                                <?php endif; ?>
                                <p class="small mb-1">
                                    <i class="fa fa-clock"></i>
                                    <?= $ev['start_time'] ? date('h:i A', strtotime($ev['start_time'])) : 'TBA' ?>
                                    <?= $ev['end_time'] ? ' – '.date('h:i A', strtotime($ev['end_time'])) : '' ?>
                                    <?php if ($ev['location']): ?>
                                        &nbsp;&bull;&nbsp;<i class="fa fa-map-marker"></i> <?= htmlspecialchars($ev['location']) ?>
                                    <?php endif; ?>
                                </p>
                                <?php if ($ev['sponsors']): ?>
                                    <p class="sponsor-text mb-1"><i class="fa fa-user"></i> Sponsor: <?= htmlspecialchars($ev['sponsors']) ?></p>
                                <?php endif; ?>
                                <?php if ($ev['contacts']): ?>
                                    <p class="sponsor-text mb-0"><i class="fa fa-phone"></i> <?= htmlspecialchars($ev['contacts']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="text-md-right mt-2 mt-md-0 d-flex flex-column align-items-md-end justify-content-between">
                                <?php
                                // Determine status: if sponsors field is filled, treat as Booked
                                $isBooked = !empty($ev['sponsors']);
                                $statusClass = 'status-available';
                                $statusIcon  = '✅';
                                $statusLabel = 'Available';
                                if ($ev['status'] === 'Pending Approval') {
                                    $statusClass = 'status-pending'; $statusIcon = '⏳'; $statusLabel = 'Pending';
                                } elseif ($isBooked || $ev['status'] === 'Booked') {
                                    $statusClass = 'status-booked'; $statusIcon = '❌'; $statusLabel = 'Booked';
                                }
                                ?>
                                <span class="<?= $statusClass ?>"><?= $statusIcon ?> <?= $statusLabel ?></span>
                                <?php if (!$isBooked && $ev['status'] === 'Available'): ?>
                                    <a href="book-event.php?id=<?= $ev['id'] ?>" class="btn btn-sm btn-danger mt-2">
                                        <i class="fa fa-ticket"></i> Book / Sponsor
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ═══ CALENDAR VIEW ═══ -->
        <div id="calView" style="display:none;">
            <!-- Calendar navigation -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <?php
                $prevM = $calMonth - 1; $prevY = $calYear;
                if ($prevM < 1) { $prevM = 12; $prevY--; }
                $nextM = $calMonth + 1; $nextY = $calYear;
                if ($nextM > 12) { $nextM = 1; $nextY++; }
                ?>
                <a href="events.php?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-sm btn-outline-dark">&laquo; Prev</a>
                <h4 class="mb-0"><?= $monthName ?> <?= $calYear ?></h4>
                <a href="events.php?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-sm btn-outline-dark">Next &raquo;</a>
            </div>

            <table class="cal-table">
                <thead>
                    <tr>
                        <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $todayStr = date('Y-m-d');
                $day = 1;
                $totalCells = $startDow + $daysInMonth;
                $rows = ceil($totalCells / 7);
                for ($r = 0; $r < $rows; $r++):
                ?>
                    <tr>
                    <?php for ($c = 0; $c < 7; $c++):
                        $cellIndex = $r * 7 + $c;
                        if ($cellIndex < $startDow || $day > $daysInMonth):
                    ?>
                        <td class="empty"></td>
                    <?php else:
                        $dateStr = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $day);
                        $isToday = ($dateStr === $todayStr);
                    ?>
                        <td class="<?= $isToday ? 'today' : '' ?>">
                            <div class="day-num"><?= $day ?></div>
                            <?php if (isset($calendarEvents[$dateStr])): ?>
                                <?php foreach ($calendarEvents[$dateStr] as $cev): ?>
                                    <?php
                                    $evClass = 'avail';
                                    if ($cev['status'] === 'Pending Approval') $evClass = 'pend';
                                    elseif (!empty($cev['sponsors']) || $cev['status'] === 'Booked') $evClass = 'booked';
                                    ?>
                                    <a href="book-event.php?id=<?= $cev['id'] ?>" class="cal-event <?= $evClass ?>"
                                       title="<?= htmlspecialchars($cev['title']) ?> (<?= $cev['status'] ?>)">
                                        <?= htmlspecialchars($cev['title']) ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    <?php $day++; endif; endfor; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- Legend -->
        <div class="mt-4 text-center">
            <span class="status-available mx-2">✅ Available</span>
            <span class="status-pending mx-2">⏳ Pending</span>
            <span class="status-booked mx-2">❌ Booked</span>
        </div>

    </div>
</div>

<?php include_once 'include/footer.php'; ?>

<script>
// View toggle
document.getElementById('btnListView').addEventListener('click', function() {
    document.getElementById('listView').style.display = '';
    document.getElementById('calView').style.display = 'none';
    this.classList.add('active');
    document.getElementById('btnCalView').classList.remove('active');
    localStorage.setItem('eventsView', 'list');
});
document.getElementById('btnCalView').addEventListener('click', function() {
    document.getElementById('listView').style.display = 'none';
    document.getElementById('calView').style.display = '';
    this.classList.add('active');
    document.getElementById('btnListView').classList.remove('active');
    localStorage.setItem('eventsView', 'calendar');
});
// Restore preference
if (localStorage.getItem('eventsView') === 'calendar') {
    document.getElementById('btnCalView').click();
}
</script>

</body>
</html>
