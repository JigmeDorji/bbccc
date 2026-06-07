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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS class_teacher_assignment_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    previous_teacher_ids VARCHAR(500) NULL,
    new_teacher_ids VARCHAR(500) NULL,
    changed_by_user_id VARCHAR(80) NULL,
    changed_by_username VARCHAR(190) NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cta_audit_class_time (class_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO class_teacher_assignments (class_id, teacher_id, is_primary)
SELECT c.id, c.teacher_id, 1
FROM classes c
WHERE c.teacher_id IS NOT NULL;
