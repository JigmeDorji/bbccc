<?php

function bbcc_userid_max_length(PDO $pdo): int {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $stmt = $pdo->query("
        SELECT CHARACTER_MAXIMUM_LENGTH AS max_len
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'user'
          AND COLUMN_NAME = 'userid'
        LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $len = (int)($row['max_len'] ?? 50);
    if ($len <= 0) {
        $len = 50;
    }

    $cached = $len;
    return $cached;
}

function bbcc_generate_userid(PDO $pdo, string $prefix = 'U'): string {
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix) ?? 'U');
    if ($prefix === '') {
        $prefix = 'U';
    }

    $maxLen = bbcc_userid_max_length($pdo);
    $base = $prefix . strtoupper(bin2hex(random_bytes(8))); // e.g. U6FA...

    if (strlen($base) > $maxLen) {
        $base = substr($base, 0, $maxLen);
    }
    if (strlen($base) < 4) {
        throw new RuntimeException("`user.userid` column is too short to generate a safe ID.");
    }

    $check = $pdo->prepare("SELECT 1 FROM `user` WHERE userid = :uid LIMIT 1");
    for ($i = 0; $i < 8; $i++) {
        $suffix = strtoupper(substr(bin2hex(random_bytes(8)), 0, max(1, $maxLen - strlen($prefix))));
        $candidate = $prefix . $suffix;
        if (strlen($candidate) > $maxLen) {
            $candidate = substr($candidate, 0, $maxLen);
        }
        $check->execute([':uid' => $candidate]);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
    }

    return $base;
}
