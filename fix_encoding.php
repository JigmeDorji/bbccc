<?php
/**
 * fix_encoding.php — One-time repair script for double-encoded Dzongkha text
 *
 * Covers both the `events` table and the `menu` table (used by event_detail.php).
 *
 * HOW IT HAPPENED:
 *   Data was saved via MySQL connections without charset=utf8mb4.
 *   MySQL treated incoming UTF-8 bytes as latin1, then re-encoded them into
 *   utf8mb4 storage — doubling the encoding.
 *
 * HOW THE REPAIR WORKS:
 *   CONVERT(BINARY CONVERT(col USING latin1) USING utf8mb4)
 *   Reverses the double-encode by re-reading the stored bytes as raw binary.
 *
 * HOW TO USE:
 *   1. Deploy to live server (git pull).
 *   2. Open  https://bhutanesecentre.org/fix_encoding.php?secret=bbcc_fix_2026
 *   3. Review the Before/After preview for each table.
 *   4. Click "Run Repair" — it fixes both tables at once.
 *   5. DELETE this file from the server immediately after.
 *
 * WARNING: Run ONCE only. Running a second time will mangle the data again.
 */

require_once __DIR__ . '/include/config.php';

$SECRET = 'bbcc_fix_2026';
if (($_GET['secret'] ?? '') !== $SECRET) {
    http_response_code(403);
    exit('403 Forbidden — supply ?secret=bbcc_fix_2026');
}

$doRepair = isset($_GET['run']) && $_GET['run'] === '1';

// Tables and their text columns that may contain Dzongkha
$TABLES = [
    'events' => ['title', 'description', 'location', 'sponsors', 'contacts'],
    'menu'   => ['menuName', 'menuDetail'],
];

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );

    // Collect affected rows per table
    $affected = [];   // [ 'events' => [...rows], 'menu' => [...rows] ]
    foreach ($TABLES as $table => $columns) {
        $affected[$table] = [];
        $rows = $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                if (!empty($row[$col]) && preg_match('/\xC3[\x80-\xBF]/', $row[$col])) {
                    $affected[$table][] = $row;
                    break;
                }
            }
        }
    }

    if ($doRepair) {
        $totalFixed = 0;
        foreach ($TABLES as $table => $columns) {
            foreach ($affected[$table] as $row) {
                $setParts = [];
                foreach ($columns as $col) {
                    if (!empty($row[$col]) && preg_match('/\xC3[\x80-\xBF]/', $row[$col])) {
                        $setParts[] = "`$col` = CONVERT(BINARY CONVERT(`$col` USING latin1) USING utf8mb4)";
                    }
                }
                if ($setParts) {
                    $pdo->prepare("UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE id = :id")
                        ->execute([':id' => $row['id']]);
                    $totalFixed++;
                }
            }
        }
        echo "<h2 style='color:green;font-family:sans-serif'>✔ Repair complete — $totalFixed row(s) updated across all tables.</h2>";
        echo "<p style='font-family:sans-serif'>You may now <strong>delete this file</strong> from the server.</p>";
        echo "<p style='font-family:sans-serif'><a href='events'>View Events page</a> &nbsp;|&nbsp; <a href='event_detail?id=10'>View Event Detail</a></p>";
        exit;
    }

} catch (Exception $e) {
    exit('DB Error: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Encoding Repair — Preview</title>
    <style>
        body  { font-family: sans-serif; padding: 24px; max-width: 1100px; margin: 0 auto; }
        h1    { color: #7b1717; }
        h2    { color: #444; margin-top: 32px; border-bottom: 2px solid #eee; padding-bottom: 6px; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; vertical-align: top; word-break: break-word; }
        th    { background: #f0f0f0; }
        .bad  { color: #c00; }
        .good { color: #070; }
        .btn  { display: inline-block; margin-top: 24px; padding: 12px 28px; background: #7b1717;
                color: #fff; text-decoration: none; border-radius: 6px; font-size: 15px; }
        .none { color: #888; font-style: italic; }
    </style>
</head>
<body>
<h1>Dzongkha Encoding Repair — Preview</h1>
<p>All double-encoded rows found below will be fixed when you click <strong>Run Repair</strong>.</p>

<?php
$anyAffected = false;
foreach ($TABLES as $table => $columns):
    $rows = $affected[$table];
    if (empty($rows)) continue;
    $anyAffected = true;
?>
<h2>Table: <code><?= htmlspecialchars($table) ?></code> — <?= count($rows) ?> row(s) affected</h2>
<table>
    <thead>
        <tr><th>ID</th><th>Column</th><th>Before (garbled)</th><th>After (repaired)</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row):
        foreach ($columns as $col):
            if (empty($row[$col]) || !preg_match('/\xC3[\x80-\xBF]/', $row[$col])) continue;
            $repaired = mb_convert_encoding($row[$col], 'UTF-8', 'Windows-1252');
    ?>
        <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><code><?= htmlspecialchars($col) ?></code></td>
            <td class="bad"><?= htmlspecialchars($row[$col]) ?></td>
            <td class="good"><?= htmlspecialchars($repaired) ?></td>
        </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
</table>
<?php endforeach; ?>

<?php if (!$anyAffected): ?>
    <p class="none">No double-encoded rows detected in any table. Data looks correct already.</p>
<?php else: ?>
    <a class="btn" href="?secret=<?= htmlspecialchars($SECRET) ?>&run=1">▶ Run Repair (all tables)</a>
    <p style="color:#888;font-size:12px;margin-top:8px">⚠ Run only ONCE. Delete this file afterwards.</p>
<?php endif; ?>
</body>
</html>