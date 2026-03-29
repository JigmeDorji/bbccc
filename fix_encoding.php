<?php
/**
 * fix_encoding.php — One-time repair script for double-encoded Dzongkha text
 *
 * HOW IT HAPPENED:
 *   Events were saved via a MySQL connection without charset=utf8mb4.
 *   MySQL treated incoming UTF-8 bytes as latin1 characters, then converted
 *   them to utf8mb4 storage — doubling the encoding.
 *   e.g. Dzongkha U+0F56 (UTF-8: E0 BD 96) was stored as the three latin1
 *   characters à ½ – (UTF-8: C3 A0 C2 BD E2 80 93).
 *
 * HOW THIS SCRIPT FIXES IT:
 *   CONVERT(BINARY CONVERT(col USING latin1) USING utf8mb4)
 *   → reads the utf8mb4-stored bytes back as raw binary via latin1,
 *     then re-interprets those bytes as real utf8mb4, reversing the double-encode.
 *
 * HOW TO USE:
 *   1. Upload this file to your live server.
 *   2. Open  https://yourdomain.com/fix_encoding.php?secret=bbcc_fix_2026
 *   3. Verify the "After" values look correct (proper Dzongkha characters).
 *   4. Click "Run Repair" or add &run=1 to the URL.
 *   5. DELETE this file from the server immediately after.
 *
 * WARNING: Run ONCE only. Running it a second time will mangle the data again.
 */

require_once __DIR__ . '/include/config.php';

// Simple secret gate — change this if you like
$SECRET = 'bbcc_fix_2026';
if (($_GET['secret'] ?? '') !== $SECRET) {
    http_response_code(403);
    exit('403 Forbidden — supply ?secret=bbcc_fix_2026');
}

$doRepair = isset($_GET['run']) && $_GET['run'] === '1';

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

    // Columns that may contain Dzongkha text
    $columns = ['title', 'description', 'location', 'sponsors', 'contacts'];

    // Preview: show current vs repaired values for any row that looks double-encoded
    // (double-encoded UTF-8 stored as utf8mb4 always contains sequences like C3 A0)
    $stmt = $pdo->query("SELECT * FROM events ORDER BY id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $affected = [];
    foreach ($rows as $row) {
        $needsFix = false;
        foreach ($columns as $col) {
            if (!empty($row[$col]) && preg_match('/\xC3[\x80-\xBF]/', $row[$col])) {
                $needsFix = true;
                break;
            }
        }
        if ($needsFix) {
            $affected[] = $row;
        }
    }

    if ($doRepair) {
        // Run the repair UPDATE for each affected row
        $fixed = 0;
        foreach ($affected as $row) {
            $setParts = [];
            $params   = [':id' => $row['id']];
            foreach ($columns as $col) {
                if (!empty($row[$col]) && preg_match('/\xC3[\x80-\xBF]/', $row[$col])) {
                    $setParts[] = "`$col` = CONVERT(BINARY CONVERT(`$col` USING latin1) USING utf8mb4)";
                }
            }
            if ($setParts) {
                $sql = "UPDATE events SET " . implode(', ', $setParts) . " WHERE id = :id";
                $pdo->prepare($sql)->execute($params);
                $fixed++;
            }
        }

        echo "<h2 style='color:green'>✔ Repair complete — $fixed row(s) updated.</h2>";
        echo "<p>You may now <strong>delete this file</strong> from the server.</p>";
        echo "<p><a href='events'>View Events page</a></p>";
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
        body { font-family: sans-serif; padding: 24px; max-width: 1100px; margin: 0 auto; }
        h1 { color: #7b1717; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; vertical-align: top; }
        th { background: #f0f0f0; }
        .bad  { color: #c00; }
        .good { color: #070; }
        .btn  { display: inline-block; margin-top: 20px; padding: 12px 28px; background: #7b1717;
                color: #fff; text-decoration: none; border-radius: 6px; font-size: 15px; }
        .none { color: #888; font-style: italic; }
    </style>
</head>
<body>
<h1>Dzongkha Encoding Repair — Preview</h1>

<?php if (empty($affected)): ?>
    <p class="none">No double-encoded rows detected. The data looks correct already, or all rows are ASCII.</p>
<?php else: ?>
    <p>Found <strong><?= count($affected) ?></strong> row(s) with double-encoded text.
       Review the "After (repaired)" column below, then click <strong>Run Repair</strong> if it looks correct.</p>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Column</th>
                <th>Before (garbled)</th>
                <th>After (repaired)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($affected as $row):
            foreach ($columns as $col):
                if (empty($row[$col]) || !preg_match('/\xC3[\x80-\xBF]/', $row[$col])) continue;
                // Simulate the repair in PHP: treat UTF-8 bytes as latin1, then re-read as UTF-8
                $repaired = mb_convert_encoding($row[$col], 'UTF-8', 'Windows-1252');
                ?>
                <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td><code><?= htmlspecialchars($col) ?></code></td>
                    <td class="bad"><?= htmlspecialchars($row[$col]) ?></td>
                    <td class="good"><?= htmlspecialchars($repaired) ?></td>
                </tr>
                <?php
            endforeach;
        endforeach; ?>
        </tbody>
    </table>

    <a class="btn" href="?secret=<?= htmlspecialchars($SECRET) ?>&run=1">▶ Run Repair</a>
    <p style="color:#888;font-size:12px;margin-top:8px">⚠ Run only ONCE. Delete this file afterwards.</p>
<?php endif; ?>
</body>
</html>
