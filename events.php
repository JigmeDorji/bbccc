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

    $calendarEvents = [];
    foreach ($events as $ev) {
        $calendarEvents[$ev['event_date']][] = $ev;
    }
} catch (Exception $e) {
    $events = [];
    $calendarEvents = [];
}

$calYear  = (int)($filterYear ?: date('Y'));
$calMonth = $filterMonth !== '' ? (int)$filterMonth : (int)date('m');

$firstDayObj = new DateTime(sprintf('%04d-%02d-01', $calYear, $calMonth));
$daysInMonth = (int)$firstDayObj->format('t');
$startDow    = (int)$firstDayObj->format('w');
$monthName   = $firstDayObj->format('F');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Events — BBCC</title>
    <meta name="description" content="Browse upcoming events and ceremonies at BBCC Canberra.">
    <?php include_once 'include/global_css.php'; ?>

    <style>
        /* Events-specific styles using design tokens */
        .ev-filter {
            background: var(--gray-100); border-radius: var(--radius-lg); padding: 24px;
            margin-bottom: 32px; display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
        }
        .ev-filter .fg { flex: 1; min-width: 140px; }
        .ev-filter label {
            display: block; font-size: .78rem; font-weight: 600; color: var(--gray-700);
            margin-bottom: 4px; text-transform: uppercase; letter-spacing: .4px;
        }
        .ev-filter select, .ev-filter input[type=text] {
            width: 100%; padding: 10px 14px; border: 1.5px solid var(--gray-200); border-radius: var(--radius-md);
            font-size: .9rem; font-family: var(--font-body); background: #fff; color: var(--gray-900);
            transition: var(--transition-fast);
        }
        .ev-filter select:focus, .ev-filter input:focus {
            outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(136,27,18,.08);
        }
        .ev-filter .btn-group { display: flex; gap: 8px; }

        .view-btns { display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 20px; }
        .view-btns button {
            background: var(--white); border: 1.5px solid var(--gray-200); border-radius: var(--radius-sm);
            padding: 8px 16px; font-size: .85rem; font-weight: 600; cursor: pointer;
            font-family: var(--font-body); color: var(--gray-600); transition: var(--transition-fast);
        }
        .view-btns button:hover { border-color: var(--brand); color: var(--brand); }
        .view-btns button.active { background: var(--brand); color: #fff; border-color: var(--brand); }

        /* Event Card (list) */
        .ev-card {
            display: flex; border: 1px solid var(--gray-200); border-radius: var(--radius-lg);
            overflow: hidden; margin-bottom: 16px; transition: var(--transition); background: var(--white);
        }
        .ev-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .ev-card__date {
            background: var(--brand); color: #fff; padding: 20px 24px; text-align: center;
            display: flex; flex-direction: column; justify-content: center; min-width: 100px;
        }
        .ev-card__date .day { font-size: 2rem; font-weight: 800; line-height: 1; }
        .ev-card__date .month { font-size: .8rem; text-transform: uppercase; font-weight: 600; margin-top: 2px; }
        .ev-card__date .dow { font-size: .72rem; opacity: .8; margin-top: 2px; }
        .ev-card__body { flex: 1; padding: 20px; display: flex; gap: 16px; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; }
        .ev-card__info { flex: 1; min-width: 200px; }
        .ev-card__info h4 { font-size: 1.05rem; font-weight: 700; margin: 0 0 6px; color: var(--gray-900); }
        .ev-card__info .desc { font-size: .88rem; color: var(--gray-600); margin-bottom: 8px; }
        .ev-card__meta { font-size: .82rem; color: var(--gray-600); display: flex; flex-wrap: wrap; gap: 12px; }
        .ev-card__meta i { color: var(--brand); margin-right: 4px; font-size: .7rem; }
        .ev-card__actions { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }

        /* Status badges */
        .badge-status {
            display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px;
            border-radius: var(--radius-full); font-size: .78rem; font-weight: 600;
        }
        .badge-avail { background: #ecfdf5; color: #059669; }
        .badge-pend  { background: #fffbeb; color: #d97706; }
        .badge-booked { background: #fef2f2; color: #dc2626; }

        /* Calendar */
        .cal-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .cal-nav h3 { font-size: 1.3rem; font-weight: 700; margin: 0; }
        .cal-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .cal-table th {
            background: var(--dark); color: #fff; padding: 10px 8px; text-align: center;
            font-size: .82rem; font-weight: 600;
        }
        .cal-table td {
            border: 1px solid var(--gray-200); vertical-align: top; padding: 6px; height: 100px; font-size: .82rem;
        }
        .cal-table td.today { background: #fffbeb; }
        .cal-table td.empty { background: var(--gray-100); }
        .cal-table td .day-num { font-weight: 700; margin-bottom: 4px; color: var(--gray-900); }
        .cal-ev {
            display: block; padding: 2px 6px; margin-bottom: 2px; border-radius: 4px;
            font-size: .72rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            cursor: pointer; text-decoration: none; color: #fff;
        }
        .cal-ev.avail { background: #10b981; }
        .cal-ev.pend { background: #f59e0b; color: #333; }
        .cal-ev.booked { background: #ef4444; }

        .ev-legend { display: flex; justify-content: center; gap: 20px; margin-top: 32px; }

        @media (max-width: 768px) {
            .cal-table td { height: 70px; font-size: .7rem; padding: 3px; }
            .ev-card { flex-direction: column; }
            .ev-card__date { flex-direction: row; gap: 8px; min-width: auto; padding: 12px 16px; }
            .ev-card__actions { flex-direction: row; align-items: center; }
        }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<!-- Page Hero -->
<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-calendar-days"></i> Events Calendar</h1>
        <p class="bbcc-page-hero__subtitle">Browse and book upcoming community events</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index.php">Home</a></li>
            <li class="sep">/</li>
            <li>Events</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">

        <div class="section-header fade-up" style="margin-bottom:40px;">
            <span class="section-badge"><i class="fa-solid fa-calendar-days"></i> Events</span>
            <h2>BBCC Events <span>Calendar <?= $calYear ?></span></h2>
            <p>To sponsor an event, contact Khenchen, Khenpo Sonam or Program Coordinator Namgay.</p>
        </div>

        <!-- Filter Bar -->
        <form method="GET" class="ev-filter fade-up">
            <div class="fg">
                <label>Year</label>
                <select name="year">
                    <?php for ($y = 2025; $y <= 2030; $y++): ?>
                    <option value="<?= $y ?>" <?= $calYear==$y?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="fg">
                <label>Month</label>
                <select name="month">
                    <option value="">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $calMonth==$m && $filterMonth!==''?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="fg" style="flex:2;">
                <label>Search</label>
                <input type="text" name="search" placeholder="Search events..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="btn-group" style="padding-top:18px;">
                <button type="submit" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm">
                    <i class="fa-solid fa-search"></i> Filter
                </button>
                <a href="events.php" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm">Reset</a>
            </div>
        </form>

        <!-- View Toggle -->
        <div class="view-btns">
            <button class="active" id="btnList"><i class="fa-solid fa-list"></i> List</button>
            <button id="btnCal"><i class="fa-solid fa-calendar"></i> Calendar</button>
        </div>

        <!-- ═══ LIST VIEW ═══ -->
        <div id="listView">
            <?php if (empty($events)): ?>
            <div style="text-align:center;padding:60px 0;">
                <i class="fa-regular fa-calendar-xmark" style="font-size:3rem;color:var(--gray-400);"></i>
                <p style="margin-top:16px;color:var(--gray-600);">No events found for the selected criteria.</p>
            </div>
            <?php else: ?>
                <?php foreach ($events as $ev): ?>
                <div class="ev-card fade-up">
                    <div class="ev-card__date">
                        <span class="day"><?= date('d', strtotime($ev['event_date'])) ?></span>
                        <span class="month"><?= date('M', strtotime($ev['event_date'])) ?></span>
                        <span class="dow"><?= date('l', strtotime($ev['event_date'])) ?></span>
                    </div>
                    <div class="ev-card__body">
                        <div class="ev-card__info">
                            <h4><?= htmlspecialchars($ev['title']) ?></h4>
                            <?php if ($ev['description']): ?>
                            <p class="desc"><?= htmlspecialchars($ev['description']) ?></p>
                            <?php endif; ?>
                            <div class="ev-card__meta">
                                <span>
                                    <i class="fa-regular fa-clock"></i>
                                    <?= $ev['start_time'] ? date('h:i A', strtotime($ev['start_time'])) : 'TBA' ?>
                                    <?= $ev['end_time'] ? ' – '.date('h:i A', strtotime($ev['end_time'])) : '' ?>
                                </span>
                                <?php if ($ev['location']): ?>
                                <span><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($ev['location']) ?></span>
                                <?php endif; ?>
                                <?php if ($ev['sponsors']): ?>
                                <span><i class="fa-solid fa-user"></i> <?= htmlspecialchars($ev['sponsors']) ?></span>
                                <?php endif; ?>
                                <?php if ($ev['contacts']): ?>
                                <span><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($ev['contacts']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ev-card__actions">
                            <?php
                            $isBooked = !empty($ev['sponsors']);
                            if ($ev['status'] === 'Pending Approval') {
                                echo '<span class="badge-status badge-pend"><i class="fa-solid fa-hourglass-half"></i> Pending</span>';
                            } elseif ($isBooked || $ev['status'] === 'Booked') {
                                echo '<span class="badge-status badge-booked"><i class="fa-solid fa-xmark"></i> Booked</span>';
                            } else {
                                echo '<span class="badge-status badge-avail"><i class="fa-solid fa-check"></i> Available</span>';
                            }
                            ?>
                            <?php if (!$isBooked && $ev['status'] === 'Available'): ?>
                            <a href="book-event.php?id=<?= $ev['id'] ?>" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm">
                                <i class="fa-solid fa-ticket"></i> Book / Sponsor
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
            <div class="cal-nav">
                <?php
                $prevM = $calMonth - 1; $prevY = $calYear;
                if ($prevM < 1) { $prevM = 12; $prevY--; }
                $nextM = $calMonth + 1; $nextY = $calYear;
                if ($nextM > 12) { $nextM = 1; $nextY++; }
                ?>
                <a href="events.php?year=<?= $prevY ?>&month=<?= $prevM ?>" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm">&laquo; Prev</a>
                <h3><?= $monthName ?> <?= $calYear ?></h3>
                <a href="events.php?year=<?= $nextY ?>&month=<?= $nextM ?>" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm">Next &raquo;</a>
            </div>

            <table class="cal-table">
                <thead>
                    <tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr>
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
                            <a href="book-event.php?id=<?= $cev['id'] ?>" class="cal-ev <?= $evClass ?>"
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
        <div class="ev-legend">
            <span class="badge-status badge-avail"><i class="fa-solid fa-check"></i> Available</span>
            <span class="badge-status badge-pend"><i class="fa-solid fa-hourglass-half"></i> Pending</span>
            <span class="badge-status badge-booked"><i class="fa-solid fa-xmark"></i> Booked</span>
        </div>

    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

<script>
document.getElementById('btnList').addEventListener('click', function() {
    document.getElementById('listView').style.display = '';
    document.getElementById('calView').style.display = 'none';
    this.classList.add('active');
    document.getElementById('btnCal').classList.remove('active');
    localStorage.setItem('eventsView', 'list');
});
document.getElementById('btnCal').addEventListener('click', function() {
    document.getElementById('listView').style.display = 'none';
    document.getElementById('calView').style.display = '';
    this.classList.add('active');
    document.getElementById('btnList').classList.remove('active');
    localStorage.setItem('eventsView', 'calendar');
});
if (localStorage.getItem('eventsView') === 'calendar') {
    document.getElementById('btnCal').click();
}
</script>

</body>
</html>
