<?php
require_once __DIR__ . '/include/config.php';
require_once __DIR__ . '/include/pcm_helpers.php';

$pdo = pcm_pdo();
$rows = $pdo->query("SELECT parent_email, pin_plain FROM tmp_parent_pins")->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare("UPDATE parents SET pin_hash = :h WHERE LOWER(email) = LOWER(:e)");

$ok = 0; $miss = 0; $skip = 0;
foreach ($rows as $r) {
    $email = trim((string)$r['parent_email']);
    $pin   = trim((string)$r['pin_plain']);

    if ($email === '' || !preg_match('/^\d{4,}$/', $pin)) { $skip++; continue; }

    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $upd->execute([':h' => $hash, ':e' => $email]);

    if ($upd->rowCount() > 0) $ok++; else $miss++;
}
echo "Updated: $ok, Not matched: $miss, Skipped invalid: $skip\n";
