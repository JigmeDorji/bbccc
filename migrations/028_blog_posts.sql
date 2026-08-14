-- Migration 028: standalone Blog — admin-authored posts/updates shown
-- publicly, separate from the Events system.

CREATE TABLE IF NOT EXISTS `blog_posts` (
    `id`            INT           NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(255)  NOT NULL,
    `excerpt`       TEXT          DEFAULT NULL,
    `content`       LONGTEXT      DEFAULT NULL,
    `image_path`    VARCHAR(255)  NOT NULL DEFAULT '',
    `status`        ENUM('draft','published') NOT NULL DEFAULT 'draft',
    `published_at`  DATETIME      DEFAULT NULL,
    `author_name`   VARCHAR(150)  NOT NULL DEFAULT '',
    `created_by`    VARCHAR(100)  NOT NULL DEFAULT '',
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_blog_posts_status_published` (`status`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
