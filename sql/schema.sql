-- Dramatool database schema (phase 3)
-- Target database: dramatool
-- MySQL 5.7 compatible (no CTE / window functions / utf8mb4_0900_ai_ci)
--
-- 多租户说明：
--   隔离表（works / work_revisions / work_assets / likes / comments /
--   favorites / reports / announcements）均含 `site` 字段（VARCHAR(32)），
--   用于支持同一数据库承载多个分支部署，每条数据归属一个站点。
--   共享表（users / sessions / password_resets / email_verifications /
--   register_attempts）不带 site，账号在所有站点通用。
--   每个部署在 config/local.php 中配置 `app.site`，例如 'main' / 'asha'。
--
-- Usage:
--   mysql -u root -p dramatool < sql/schema.sql
--   or from project root: php tools/migrate.php

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- users（共享表：账号在所有站点通用，不加 site）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`         VARCHAR(190)    NOT NULL COMMENT 'login email, unique',
  `password_hash` VARCHAR(255)    NOT NULL COMMENT 'bcrypt hash from password_hash()',
  `nickname`      VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'display name',
  `avatar`        VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'avatar relative path',
  `avatar_license` VARCHAR(20)    NOT NULL DEFAULT 'original' COMMENT 'avatar license: original / cc-by / cc-by-sa / cc0',
  `bio`           VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'short bio',
  `role`          VARCHAR(20)     NOT NULL DEFAULT 'user' COMMENT 'role: user / admin',
  `status`        TINYINT         NOT NULL DEFAULT 1 COMMENT 'status: 1 active / 0 disabled',
  `email_verified_at` DATETIME    NULL DEFAULT NULL COMMENT 'email verified time, NULL = unverified',
  `login_fail`    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'consecutive login failures',
  `locked_until`  DATETIME        NULL DEFAULT NULL COMMENT 'lock expiry time',
  `last_login_at` DATETIME        NULL DEFAULT NULL COMMENT 'last login time',
  `last_login_ip` VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'last login ip',
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role_status` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='users';

-- ---------------------------------------------------------------------------
-- works（隔离表）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `works` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site`          VARCHAR(32)     NOT NULL DEFAULT '' COMMENT 'tenant site id, e.g. main / asha',
  `user_id`       BIGINT UNSIGNED NOT NULL COMMENT 'owner user id',
  `title`         VARCHAR(120)    NOT NULL DEFAULT 'untitled' COMMENT 'work title',
  `description`   VARCHAR(500)    NOT NULL DEFAULT '' COMMENT 'work description',
  `cover`         VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'cover image relative path',
  `data`          LONGTEXT        NULL COMMENT 'full work JSON (manifest + scenes)',
  `scene_count`   INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'scene count (denormalized)',
  `word_count`    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'word count (denormalized)',
  `status`        TINYINT         NOT NULL DEFAULT 1 COMMENT 'status: 1 active / 0 soft deleted',
  `is_public`     TINYINT         NOT NULL DEFAULT 0 COMMENT 'public: 1 yes / 0 no',
  `short_code`    CHAR(8)         NULL DEFAULT NULL COMMENT 'share short code, generated on publish',
  `tags`          VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'comma separated tags',
  `play_count`    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'play count (denormalized)',
  `like_count`    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'like count (denormalized)',
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_works_site_short_code` (`site`, `short_code`),
  KEY `idx_works_site_user_status` (`site`, `user_id`, `status`),
  KEY `idx_works_site_updated` (`site`, `updated_at`),
  KEY `idx_works_site_public_updated` (`site`, `is_public`, `status`, `updated_at`),
  KEY `idx_works_site_public_play` (`site`, `is_public`, `status`, `play_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='works';

-- ---------------------------------------------------------------------------
-- work_revisions（隔离表）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `work_revisions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site`       VARCHAR(32)     NOT NULL DEFAULT '' COMMENT 'tenant site id',
  `work_id`    BIGINT UNSIGNED NOT NULL COMMENT 'work id',
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT 'operator user id',
  `data`       LONGTEXT        NULL COMMENT 'full JSON snapshot of this revision',
  `remark`     VARCHAR(120)    NOT NULL DEFAULT '' COMMENT 'revision remark',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_revisions_site_work` (`site`, `work_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='work revisions';

-- ---------------------------------------------------------------------------
-- likes（隔离表）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `likes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site`       VARCHAR(32)     NOT NULL DEFAULT '' COMMENT 'tenant site id',
  `work_id`    BIGINT UNSIGNED NOT NULL COMMENT 'liked work id',
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT 'user who liked',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_likes_site_work_user` (`site`, `work_id`, `user_id`),
  KEY `idx_likes_site_user` (`site`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='work likes';

-- ---------------------------------------------------------------------------
-- comments（隔离表）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `comments` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site`       VARCHAR(32)     NOT NULL DEFAULT '' COMMENT 'tenant site id',
  `work_id`    BIGINT UNSIGNED NOT NULL COMMENT 'commented work id',
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT 'comment author user id',
  `parent_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'parent comment id, 0 = top level',
  `content`    VARCHAR(500)    NOT NULL DEFAULT '' COMMENT 'comment content',
  `status`     TINYINT         NOT NULL DEFAULT 1 COMMENT 'status: 1 visible / 0 hidden',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comments_site_work` (`site`, `work_id`, `status`, `created_at`),
  KEY `idx_comments_site_parent` (`site`, `parent_id`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='work comments';

-- ---------------------------------------------------------------------------
-- favorites（隔离表）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `favorites` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site`       VARCHAR(32)     NOT NULL DEFAULT '' COMMENT 'tenant site id',
  `work_id`    BIGINT UNSIGNED NOT NULL COMMENT 'favorited work id',
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT 'user who favorited',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_favorites_site_work_user` (`site`, `work_id`, `user_id`),
  KEY `idx_favorites_site_user` (`site`, `user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='work favorites';

-- ---------------------------------------------------------------------------
-- reports（隔离表：内容举报）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reports` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site`        VARCHAR(32)     NOT NULL DEFAULT '' COMMENT 'tenant site id',
  `target_type` VARCHAR(10)     NOT NULL COMMENT 'target type: work / comment',
  `target_id`   BIGINT UNSIGNED NOT NULL COMMENT 'reported work id or comment id',
  `user_id`     BIGINT UNSIGNED NOT NULL COMMENT 'reporter user id',
  `reason`      VARCHAR(20)     NOT NULL COMMENT 'reason key: porn / violence / spam / infringement / other',
  `detail`      VARCHAR(500)    NOT NULL DEFAULT '' COMMENT 'extra detail from reporter',
  `status`      TINYINT         NOT NULL DEFAULT 0 COMMENT 'status: 0 pending / 1 resolved / 2 rejected',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reports_site_target_user` (`site`, `target_type`, `target_id`, `user_id`),
  KEY `idx_reports_site_status` (`site`, `status`, `created_at`),
  KEY `idx_reports_site_target` (`site`, `target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='content reports';

-- ---------------------------------------------------------------------------
-- announcements（隔离表：站点公告，各分支独立管理）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site`       VARCHAR(32)     NOT NULL DEFAULT '' COMMENT 'tenant site id',
  `title`      VARCHAR(120)    NOT NULL COMMENT 'announcement title',
  `content`    TEXT            NOT NULL COMMENT 'announcement body (plain text)',
  `status`     TINYINT         NOT NULL DEFAULT 1 COMMENT 'status: 1 published / 0 draft',
  `pinned`     TINYINT         NOT NULL DEFAULT 0 COMMENT 'pinned: 1 yes / 0 no',
  `admin_id`   BIGINT UNSIGNED NOT NULL COMMENT 'publisher admin user id',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_announcements_site_status` (`site`, `status`, `pinned`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='site announcements';

-- ---------------------------------------------------------------------------
-- work_assets（隔离表：用户上传素材）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `work_assets` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site`        VARCHAR(32)     NOT NULL DEFAULT '' COMMENT 'tenant site id',
  `user_id`     BIGINT UNSIGNED NOT NULL COMMENT 'owner user id',
  `type`        VARCHAR(10)     NOT NULL COMMENT 'asset type: bg / sprite / bgm / sfx',
  `name`        VARCHAR(120)    NOT NULL DEFAULT '' COMMENT 'display name (original filename)',
  `path`        VARCHAR(255)    NOT NULL COMMENT 'relative path under storage/uploads',
  `thumb`       VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'relative path of generated thumbnail (empty = use path)',
  `mime`        VARCHAR(80)     NOT NULL DEFAULT '' COMMENT 'detected mime type',
  `size`        INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'file size in bytes',
  `width`       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'image width',
  `height`      INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'image height',
  `duration`    DECIMAL(8,1)    NOT NULL DEFAULT 0.0 COMMENT 'audio duration in seconds',
  `hash`        CHAR(64)        NOT NULL COMMENT 'sha256 of file content, for dedup',
  `visibility`  TINYINT         NOT NULL DEFAULT 0 COMMENT 'visibility: 1 public / 0 private',
  `license`     VARCHAR(20)     NOT NULL DEFAULT 'original' COMMENT 'license: original / cc-by / cc-by-sa / cc0',
  `status`      TINYINT         NOT NULL DEFAULT 1 COMMENT 'status: 1 active / 0 deleted',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assets_site_user_hash` (`site`, `user_id`, `hash`),
  KEY `idx_assets_site_user_type` (`site`, `user_id`, `type`, `status`),
  KEY `idx_assets_site_public` (`site`, `visibility`, `status`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='user uploaded assets';

-- ---------------------------------------------------------------------------
-- register_attempts（共享表：注册限频，账号通用）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `register_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`      VARCHAR(10)     NOT NULL COMMENT 'dimension: ip / email',
  `key_hash`   CHAR(64)        NOT NULL COMMENT 'sha256 of ip or email',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_register_attempts_lookup` (`scope`, `key_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='registration rate limit attempts';

-- ---------------------------------------------------------------------------
-- sessions（共享表：remember-me token，跨站点统一登录态）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL COMMENT 'owner user id',
  `token_hash`  CHAR(64)        NOT NULL COMMENT 'sha256 of remember-me token',
  `user_agent`  VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'client user agent',
  `ip`          VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'login ip',
  `expires_at`  DATETIME        NOT NULL COMMENT 'expiry time',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sessions_token` (`token_hash`),
  KEY `idx_sessions_user` (`user_id`),
  KEY `idx_sessions_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='login sessions';

-- ---------------------------------------------------------------------------
-- password_resets（共享表）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL COMMENT 'requester user id',
  `token_hash`  CHAR(64)        NOT NULL COMMENT 'sha256 of reset token sent by email',
  `ip`          VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'request ip (also used for ip throttle)',
  `user_agent`  VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'request user agent',
  `expires_at`  DATETIME        NOT NULL COMMENT 'expiry time',
  `used_at`     DATETIME        NULL DEFAULT NULL COMMENT 'consumed time, NULL = pending',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_password_resets_token` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`, `created_at`),
  KEY `idx_password_resets_ip` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='password reset tokens';

-- ---------------------------------------------------------------------------
-- email_verifications（共享表）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL COMMENT 'target user id',
  `email`       VARCHAR(190)    NOT NULL COMMENT 'email being verified (snapshot at issue time)',
  `token_hash`  CHAR(64)        NOT NULL COMMENT 'sha256 of verification token sent by email',
  `ip`          VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'request ip (also used for ip throttle)',
  `user_agent`  VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'request user agent',
  `expires_at`  DATETIME        NOT NULL COMMENT 'expiry time',
  `used_at`     DATETIME        NULL DEFAULT NULL COMMENT 'consumed time, NULL = pending',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email_verifications_token` (`token_hash`),
  KEY `idx_email_verifications_user` (`user_id`, `created_at`),
  KEY `idx_email_verifications_ip` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='email verification tokens';
