<?php

function fetch_parent_record(PDO $pdo) {
    $userId = $_SESSION['userid'] ?? null;
    $username = $_SESSION['username'] ?? null;

    $stmt = $pdo->prepare(
        "SELECT * FROM parents WHERE (user_id = :user_id AND :user_id IS NOT NULL) OR (username = :username) ORDER BY id ASC LIMIT 1"
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':username' => $username
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}
