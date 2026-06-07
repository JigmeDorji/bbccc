<?php

function bbcc_schema_cache_has(string $key): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    return !empty($_SESSION['bbcc_schema_ready'][$key]);
}

function bbcc_schema_cache_set(string $key): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (!isset($_SESSION['bbcc_schema_ready']) || !is_array($_SESSION['bbcc_schema_ready'])) {
        $_SESSION['bbcc_schema_ready'] = [];
    }
    $_SESSION['bbcc_schema_ready'][$key] = 1;
}

function bbcc_table_exists(PDO $pdo, string $tableName): bool {
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName ?? '');
    if ($tableName === '') {
        return false;
    }

    $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tableName));
    return $stmt ? (bool)$stmt->fetchColumn() : false;
}

function bbcc_class_teacher_assignments_is_empty(PDO $pdo): bool {
    $stmt = $pdo->query("SELECT 1 FROM class_teacher_assignments LIMIT 1");
    if (!$stmt) {
        return true;
    }
    return $stmt->fetchColumn() === false;
}

function bbcc_ensure_class_teacher_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    if (bbcc_schema_cache_has('class_teacher_schema')) {
        $done = true;
        return;
    }

    $hasAssignments = bbcc_table_exists($pdo, 'class_teacher_assignments');
    $hasAudit = bbcc_table_exists($pdo, 'class_teacher_assignment_audit');
    $createdAssignments = false;

    if (!$hasAssignments) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS class_teacher_assignments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                class_id INT NOT NULL,
                teacher_id INT NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                assigned_by_user_id VARCHAR(80) NULL,
                assigned_by_username VARCHAR(190) NULL,
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_by_user_id VARCHAR(80) NULL,
                updated_by_username VARCHAR(190) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_class_teacher (class_id, teacher_id),
                KEY idx_cta_class (class_id),
                KEY idx_cta_teacher (teacher_id),
                KEY idx_cta_primary (class_id, is_primary)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $hasAssignments = true;
        $createdAssignments = true;
    }

    if (!$hasAudit) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS class_teacher_assignment_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                class_id INT NOT NULL,
                previous_teacher_ids VARCHAR(500) NULL,
                new_teacher_ids VARCHAR(500) NULL,
                changed_by_user_id VARCHAR(80) NULL,
                changed_by_username VARCHAR(190) NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cta_audit_class_time (class_id, changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    if (!$hasAssignments) {
        $done = true;
        return;
    }

    // Run expensive bootstrap only when assignments table is newly created or empty.
    $shouldBootstrapAssignments = $createdAssignments || bbcc_class_teacher_assignments_is_empty($pdo);
    if (!$shouldBootstrapAssignments) {
        bbcc_schema_cache_set('class_teacher_schema');
        $done = true;
        return;
    }

    // Backfill from legacy classes.teacher_id once when table is empty/new.
    $pdo->exec("
        INSERT IGNORE INTO class_teacher_assignments
            (class_id, teacher_id, is_primary)
        SELECT c.id, c.teacher_id, 1
        FROM classes c
        WHERE c.teacher_id IS NOT NULL
    ");

    // Ensure every class with assignments has one primary teacher.
    $pdo->exec("
        UPDATE class_teacher_assignments cta
        INNER JOIN (
            SELECT t.class_id, MIN(t.id) AS min_id
            FROM class_teacher_assignments t
            LEFT JOIN (
                SELECT class_id
                FROM class_teacher_assignments
                WHERE is_primary = 1
                GROUP BY class_id
            ) p ON p.class_id = t.class_id
            WHERE p.class_id IS NULL
            GROUP BY t.class_id
        ) fix ON fix.min_id = cta.id
        SET cta.is_primary = 1
    ");

    // Keep legacy column aligned with primary assignment.
    $pdo->exec("
        UPDATE classes c
        LEFT JOIN (
            SELECT x.class_id, x.teacher_id
            FROM class_teacher_assignments x
            INNER JOIN (
                SELECT class_id, MIN(id) AS min_id
                FROM class_teacher_assignments
                WHERE is_primary = 1
                GROUP BY class_id
            ) p ON p.min_id = x.id
        ) y ON y.class_id = c.id
        SET c.teacher_id = y.teacher_id
        WHERE (c.teacher_id <> y.teacher_id)
           OR (c.teacher_id IS NULL AND y.teacher_id IS NOT NULL)
           OR (c.teacher_id IS NOT NULL AND y.teacher_id IS NULL)
    ");

    bbcc_schema_cache_set('class_teacher_schema');
    $done = true;
}

function bbcc_normalize_teacher_ids(array $teacherIds): array {
    $normalized = [];
    $seen = [];
    foreach ($teacherIds as $teacherId) {
        $id = (int)$teacherId;
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $normalized[] = $id;
    }
    return $normalized;
}

function bbcc_class_teacher_ids(PDO $pdo, int $classId): array {
    bbcc_ensure_class_teacher_schema($pdo);
    if ($classId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT teacher_id
        FROM class_teacher_assignments
        WHERE class_id = :class_id
        ORDER BY is_primary DESC, id ASC
    ");
    $stmt->execute([':class_id' => $classId]);
    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'teacher_id'));
}

function bbcc_class_primary_teacher_id(PDO $pdo, int $classId): ?int {
    $ids = bbcc_class_teacher_ids($pdo, $classId);
    return empty($ids) ? null : (int)$ids[0];
}

function bbcc_teacher_classes(PDO $pdo, int $teacherId, bool $onlyActive = true): array {
    bbcc_ensure_class_teacher_schema($pdo);
    if ($teacherId <= 0) {
        return [];
    }
    $sql = "
        SELECT DISTINCT c.id, c.class_name
        FROM classes c
        INNER JOIN class_teacher_assignments cta ON cta.class_id = c.id
        WHERE cta.teacher_id = :teacher_id
    ";
    if ($onlyActive) {
        $sql .= " AND c.active = 1";
    }
    $sql .= " ORDER BY c.class_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':teacher_id' => $teacherId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bbcc_teacher_class_ids(PDO $pdo, int $teacherId, bool $onlyActive = true): array {
    $classes = bbcc_teacher_classes($pdo, $teacherId, $onlyActive);
    return array_map('intval', array_column($classes, 'id'));
}

function bbcc_log_class_teacher_change(
    PDO $pdo,
    int $classId,
    array $previousTeacherIds,
    array $newTeacherIds,
    ?string $changedByUserId,
    ?string $changedByUsername
): void {
    if ($classId <= 0) {
        return;
    }
    $previousTeacherIds = bbcc_normalize_teacher_ids($previousTeacherIds);
    $newTeacherIds = bbcc_normalize_teacher_ids($newTeacherIds);
    if ($previousTeacherIds === $newTeacherIds) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO class_teacher_assignment_audit
            (class_id, previous_teacher_ids, new_teacher_ids, changed_by_user_id, changed_by_username)
        VALUES
            (:class_id, :previous_ids, :new_ids, :changed_by_user_id, :changed_by_username)
    ");
    $stmt->execute([
        ':class_id' => $classId,
        ':previous_ids' => empty($previousTeacherIds) ? null : implode(',', $previousTeacherIds),
        ':new_ids' => empty($newTeacherIds) ? null : implode(',', $newTeacherIds),
        ':changed_by_user_id' => $changedByUserId !== '' ? $changedByUserId : null,
        ':changed_by_username' => $changedByUsername !== '' ? $changedByUsername : null,
    ]);
}

function bbcc_set_class_teachers(
    PDO $pdo,
    int $classId,
    array $teacherIds,
    ?string $changedByUserId = null,
    ?string $changedByUsername = null
): void {
    bbcc_ensure_class_teacher_schema($pdo);
    if ($classId <= 0) {
        throw new InvalidArgumentException('Invalid class id.');
    }

    $teacherIds = bbcc_normalize_teacher_ids($teacherIds);
    $previousTeacherIds = bbcc_class_teacher_ids($pdo, $classId);
    $primaryTeacherId = empty($teacherIds) ? null : (int)$teacherIds[0];

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $delete = $pdo->prepare("DELETE FROM class_teacher_assignments WHERE class_id = :class_id");
        $delete->execute([':class_id' => $classId]);

        if (!empty($teacherIds)) {
            $insert = $pdo->prepare("
                INSERT INTO class_teacher_assignments
                    (class_id, teacher_id, is_primary, assigned_by_user_id, assigned_by_username, updated_by_user_id, updated_by_username)
                VALUES
                    (:class_id, :teacher_id, :is_primary, :assigned_by_user_id, :assigned_by_username, :updated_by_user_id, :updated_by_username)
            ");
            foreach ($teacherIds as $index => $teacherId) {
                $insert->execute([
                    ':class_id' => $classId,
                    ':teacher_id' => $teacherId,
                    ':is_primary' => $index === 0 ? 1 : 0,
                    ':assigned_by_user_id' => $changedByUserId !== '' ? $changedByUserId : null,
                    ':assigned_by_username' => $changedByUsername !== '' ? $changedByUsername : null,
                    ':updated_by_user_id' => $changedByUserId !== '' ? $changedByUserId : null,
                    ':updated_by_username' => $changedByUsername !== '' ? $changedByUsername : null,
                ]);
            }
        }

        $syncLegacy = $pdo->prepare("UPDATE classes SET teacher_id = :teacher_id WHERE id = :class_id");
        $syncLegacy->execute([
            ':teacher_id' => $primaryTeacherId,
            ':class_id' => $classId,
        ]);

        bbcc_log_class_teacher_change(
            $pdo,
            $classId,
            $previousTeacherIds,
            $teacherIds,
            $changedByUserId,
            $changedByUsername
        );
        if (function_exists('bbcc_audit_log') && $previousTeacherIds !== $teacherIds) {
            bbcc_audit_log('class_teacher_assignment_updated', 'class_teacher_assignments', [
                'class_id' => $classId,
                'previous_teacher_ids' => implode(',', $previousTeacherIds),
                'new_teacher_ids' => implode(',', $teacherIds),
                'changed_by' => (string)$changedByUsername,
            ], 'success');
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
