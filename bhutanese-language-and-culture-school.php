<?php
require_once "include/config.php";
require_once "include/blcs_schedule.php";

$blcsSchedule = [
    'intro_text' => bbcc_blcs_default_intro_text(),
    'terms_text' => bbcc_blcs_default_terms_text(),
    'sunday_dates_text' => bbcc_blcs_default_sunday_dates_text(),
];
$termLines = [];
$dateLines = [];
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
    $blcsSchedule = bbcc_blcs_load_schedule($pdo);
} catch (Throwable $e) {
    // Keep defaults.
}
$termLines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", (string)$blcsSchedule['terms_text']))));
$dateLines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", (string)$blcsSchedule['sunday_dates_text']))));

$termColumns = [];
$unmatchedDates = [];
$scheduleRows = [];

function bbcc_blcs_format_text(string $text): string {
    $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
    $html = '';
    $inUl = false;
    $inOl = false;
    $inP = false;

    $closeLists = static function () use (&$html, &$inUl, &$inOl): void {
        if ($inUl) { $html .= '</ul>'; $inUl = false; }
        if ($inOl) { $html .= '</ol>'; $inOl = false; }
    };

    foreach ($lines as $raw) {
        $line = trim((string)$raw);
        if ($line === '') {
            if ($inP) { $html .= '</p>'; $inP = false; }
            $closeLists();
            continue;
        }

        if (preg_match('/^#{1,3}\s+(.+)$/', $line, $m)) {
            if ($inP) { $html .= '</p>'; $inP = false; }
            $closeLists();
            $lvl = strspn($line, '#');
            $lvl = max(2, min(4, $lvl + 1));
            $html .= '<h' . $lvl . '>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</h' . $lvl . '>';
            continue;
        }

        if (preg_match('/^(?:[-*]|•)\s+(.+)$/u', $line, $m)) {
            if ($inP) { $html .= '</p>'; $inP = false; }
            if ($inOl) { $html .= '</ol>'; $inOl = false; }
            if (!$inUl) { $html .= '<ul>'; $inUl = true; }
            $html .= '<li>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</li>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
            if ($inP) { $html .= '</p>'; $inP = false; }
            if ($inUl) { $html .= '</ul>'; $inUl = false; }
            if (!$inOl) { $html .= '<ol>'; $inOl = true; }
            $html .= '<li>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</li>';
            continue;
        }

        $closeLists();
        if (!$inP) { $html .= '<p>'; $inP = true; }
        else { $html .= '<br>'; }
        $html .= htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
    }

    if ($inP) $html .= '</p>';
    if ($inUl) $html .= '</ul>';
    if ($inOl) $html .= '</ol>';

    return $html;
}

$parseDate = static function (string $raw): ?DateTime {
    $raw = trim($raw);
    if ($raw === '') return null;
    $formats = ['j M Y', 'd M Y', 'j F Y', 'd F Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt instanceof DateTime) return $dt;
    }
    return null;
};

foreach ($termLines as $line) {
    $termColumns[] = [
        'label' => $line,
        'start' => null,
        'end' => null,
        'dates' => [],
    ];
}

foreach ($termColumns as $i => $term) {
    $line = $term['label'];
    if (preg_match('/\(([^)]+)\)/', $line, $m)) {
        $rangeRaw = trim((string)$m[1]);
        $parts = preg_split('/\s*[–-]\s*/u', $rangeRaw);
        if (is_array($parts) && count($parts) === 2) {
            $startRaw = trim((string)$parts[0]);
            $endRaw = trim((string)$parts[1]);
            $endDate = $parseDate($endRaw);
            if ($endDate) {
                if (!preg_match('/\b\d{4}\b/', $startRaw)) {
                    $startRaw .= ' ' . $endDate->format('Y');
                }
                $startDate = $parseDate($startRaw);
                if ($startDate) {
                    $termColumns[$i]['start'] = $startDate;
                    $termColumns[$i]['end'] = $endDate;
                }
            }
        }
    }
}

foreach ($dateLines as $dateLine) {
    $d = $parseDate($dateLine);
    if (!$d) {
        $unmatchedDates[] = $dateLine;
        continue;
    }
    $matched = false;
    foreach ($termColumns as $i => $term) {
        if (($term['start'] instanceof DateTime) && ($term['end'] instanceof DateTime)) {
            if ($d >= $term['start'] && $d <= $term['end']) {
                $termColumns[$i]['dates'][] = $dateLine;
                $matched = true;
                break;
            }
        }
    }
    if (!$matched) {
        $unmatchedDates[] = $dateLine;
    }
}

if (empty($termColumns) || (count($termColumns) > 0 && array_sum(array_map(fn($t) => count($t['dates']), $termColumns)) === 0)) {
    $termColumns = [];
} else {
    $maxRows = 0;
    foreach ($termColumns as $c) {
        $maxRows = max($maxRows, count($c['dates']));
    }
    for ($r = 0; $r < $maxRows; $r++) {
        $row = [];
        foreach ($termColumns as $c) {
            $row[] = $c['dates'][$r] ?? '';
        }
        $scheduleRows[] = $row;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Bhutanese Language and Culture School — BBCC</title>
    <meta name="description" content="Bhutanese Language and Culture School at BBCC teaching Dzongkha, Bhutanese culture and values.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .blcs-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
            margin-top: 12px;
        }
        .blcs-highlight {
            margin-top: 18px;
            background: linear-gradient(135deg, #fef2f2 0%, #fff7ed 100%);
            color: #7f1d1d;
            border: 1px solid #f5d0d0;
            border-radius: 16px;
            padding: 20px 22px;
            box-shadow: 0 8px 18px rgba(127, 29, 29, 0.08);
        }
        .blcs-highlight p {
            margin: 0;
            font-size: 1.02rem;
            line-height: 1.75;
            font-weight: 600;
            letter-spacing: .1px;
        }
        .blcs-flow-content {
            margin-top: 28px;
            color: #4b5563;
            line-height: 1.9;
            font-size: 1rem;
            max-width: 860px;
        }
        .blcs-flow-content h2,
        .blcs-flow-content h3,
        .blcs-flow-content h4 {
            margin: 22px 0 10px;
            color: #111827;
            line-height: 1.4;
            font-weight: 700;
            font-size: 1.18rem;
            letter-spacing: 0;
            border-left: 0;
            padding-left: 0;
        }
        .blcs-flow-content ul,
        .blcs-flow-content ol {
            margin: 4px 0 14px;
            padding-left: 20px;
        }
        .blcs-flow-content li { margin-bottom: 6px; }
        .blcs-flow-content p {
            margin: 0 0 12px;
        }
        .blcs-flow-content > p:first-child {
            margin-top: 14px;
        }
        .blcs-flow-content h2 + p,
        .blcs-flow-content h3 + p,
        .blcs-flow-content h4 + p {
            margin-top: 2px;
        }
        .blcs-info-card h3 {
            margin: 0 0 10px;
            font-size: 1.05rem;
            line-height: 1.35;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .blcs-info-card h3 i { color: #881b12; }
        .blcs-info-card p {
            margin: 0 0 10px;
            color: #4b5563;
            line-height: 1.6;
            font-size: 0.94rem;
        }
        .blcs-info-card ul {
            margin: 0;
            padding-left: 18px;
            color: #374151;
        }
        .blcs-info-card li { margin-bottom: 6px; }

        .blcs-schedule-wrap {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
        }
        .blcs-schedule-table-wrap {
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
        }
        .blcs-schedule-table {
            width: 100%;
            min-width: 760px;
            border-collapse: separate;
            border-spacing: 0;
        }
        .blcs-schedule-table thead th {
            padding: 12px 14px;
            text-align: left;
            font-size: .9rem;
            font-weight: 700;
            color: #111827;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }
        .blcs-schedule-table thead th:nth-child(odd) { background: #f8fafc; }
        .blcs-schedule-table thead th:nth-child(even) { background: #eef2ff; }
        .blcs-schedule-table tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: .92rem;
            color: #374151;
        }
        .blcs-schedule-table tbody tr:nth-child(even) td { background: #fcfcfd; }
        .blcs-empty-cell { color: #9ca3af; }
        .blcs-subsection-title {
            margin: 0 0 12px;
            font-size: 1.12rem;
            font-weight: 700;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .blcs-subsection-title i { color: #881b12; }
        @media (max-width: 768px) {
            .blcs-highlight { padding: 16px; border-radius: 14px; }
            .blcs-flow-content { font-size: .96rem; }
            .blcs-flow-content h2,
            .blcs-flow-content h3,
            .blcs-flow-content h4 { margin-top: 16px; font-size: 1.04rem; }
            .blcs-subsection-title { font-size: 1.05rem; }
        }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-school"></i> Bhutanese Language and Culture School</h1>
        <p class="bbcc-page-hero__subtitle">Teaching Dzongkha, Bhutanese culture and values</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li><a href="services">Services</a></li>
            <li class="sep">/</li>
            <li>Bhutanese Language and Culture School</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container" style="max-width:980px;">
        <div class="section-header fade-up" style="text-align:left;max-width:none;margin-bottom:20px;">
            <span class="section-badge"><i class="fa-solid fa-book-open"></i> Bhutanese Language and Culture School Overview</span>
            <h2>About Our <span>School Program</span></h2>
        </div>

        <div class="blcs-highlight fade-up">
            <p><?= htmlspecialchars((string)($blcsSchedule['highlight_text'] ?? '')) ?></p>
        </div>

        <div class="blcs-flow-content fade-up" style="margin-top:0;">
            <?= bbcc_blcs_format_text((string)($blcsSchedule['page_text'] ?? '')) ?>
        </div>

        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;" class="fade-up">
            <h3 class="blcs-subsection-title"><i class="fa-solid fa-user-plus"></i>Enrollment</h3>
            <p style="margin:0 0 14px;color:#4b5563;">
                Create a parent account to register your child for classes.
            </p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <a href="parentAccountSetup" class="bbcc-btn bbcc-btn--primary">
                    <i class="fa-solid fa-user-plus"></i> Register Now
                </a>
                <a href="contact-us" class="bbcc-btn bbcc-btn--outline">
                    Contact Us <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>

        <div class="blcs-schedule-wrap fade-up">
            <h3 class="blcs-subsection-title"><i class="fa-solid fa-calendar-days"></i>Class Schedule</h3>
            <p style="margin:0 0 16px;color:#4b5563;"><?= htmlspecialchars((string)$blcsSchedule['intro_text']) ?></p>
            <?php if (!empty($dateLines)): ?>
                <?php if (!empty($termColumns)): ?>
                    <div class="blcs-schedule-table-wrap">
                        <table class="blcs-schedule-table">
                            <thead>
                                <tr>
                                    <?php foreach ($termColumns as $idx => $col): ?>
                                        <th>
                                            <?= htmlspecialchars($col['label']) ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($scheduleRows as $r): ?>
                                    <tr>
                                        <?php foreach ($r as $cell): ?>
                                            <td>
                                                <?= $cell !== '' ? htmlspecialchars($cell) : '<span class="blcs-empty-cell">—</span>' ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($unmatchedDates)): ?>
                        <div style="margin-top:12px;">
                            <h4 style="margin:0 0 8px;font-size:.95rem;color:#374151;">Other Dates</h4>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:8px;">
                                <?php foreach ($unmatchedDates as $d): ?>
                                    <div style="padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:.92rem;color:#374151;"><?= htmlspecialchars($d) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:8px;">
                        <?php foreach ($dateLines as $d): ?>
                            <div style="padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;font-size:.92rem;color:#374151;"><?= htmlspecialchars($d) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

</body>
</html>
