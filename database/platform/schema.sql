-- user_platform schema (idempotent; `./loongs uuims:install` runs every statement).
-- {{prefix}} is replaced with the connection's table prefix (default lgs_ from DB_PREFIX / ORM).

CREATE TABLE IF NOT EXISTS `{{prefix}}uuims_depts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `ancestors` VARCHAR(512) NOT NULL DEFAULT '0' COMMENT 'ancestor ids, comma separated, e.g. 0,1,2',
  `name` VARCHAR(64) NOT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `leader` VARCHAR(64) NULL,
  `phone` VARCHAR(32) NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1 enabled, 0 disabled',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='departments';

CREATE TABLE IF NOT EXISTS `{{prefix}}uuims_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `password` VARCHAR(255) NOT NULL COMMENT 'password_hash argon2id',
  `nickname` VARCHAR(64) NOT NULL DEFAULT '',
  `mobile` VARCHAR(32) NULL,
  `email` VARCHAR(128) NULL,
  `dept_id` INT UNSIGNED NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1 enabled, 0 disabled',
  `is_super` TINYINT NOT NULL DEFAULT 0 COMMENT 'super uuims: bypasses RBAC, cannot be deleted',
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `password_changed_at` DATETIME NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_dept` (`dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='platform administrators';

CREATE TABLE IF NOT EXISTS `{{prefix}}uuims_roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(64) NOT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `remark` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='roles';

CREATE TABLE IF NOT EXISTS `{{prefix}}uuims_user_role` (
  `user_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `role_id`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='uuims <-> role';

CREATE TABLE IF NOT EXISTS `{{prefix}}uuims_role_permission` (
  `role_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(128) NOT NULL COMMENT 'permission code of a menu/button in apps/*/menu.json, e.g. system:uuims:create',
  PRIMARY KEY (`role_id`, `code`),
  KEY `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='role -> permission code (menus live in apps/*/menu.json)';

CREATE TABLE IF NOT EXISTS `{{prefix}}uuims_user_wechat` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `appid` VARCHAR(64) NOT NULL COMMENT 'mini program AppID',
  `openid` VARCHAR(128) NOT NULL,
  `unionid` VARCHAR(128) NULL,
  `created_at` DATETIME NULL COMMENT 'bound at (after a password login in the mini program)',
  `last_login_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_openid` (`appid`, `openid`),
  UNIQUE KEY `uk_user_app` (`user_id`, `appid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='uuims <-> WeChat mini program openid';

CREATE TABLE IF NOT EXISTS `{{prefix}}uuims_role_data_scope` (
  `role_id` INT UNSIGNED NOT NULL,
  `scope` ENUM('all','custom','dept_and_child','dept','self') NOT NULL DEFAULT 'self',
  `dept_ids` JSON NULL COMMENT 'custom scope: department ids',
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='row-level data scope per role';

CREATE TABLE IF NOT EXISTS `{{prefix}}uuims_resource_fields` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource` VARCHAR(64) NOT NULL,
  `field` VARCHAR(64) NOT NULL,
  `label` VARCHAR(64) NOT NULL,
  `default_perm` ENUM('hidden','masked','readonly','editable') NOT NULL DEFAULT 'editable' COMMENT 'used when a role has no rule',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_field` (`resource`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='controllable fields';

CREATE TABLE IF NOT EXISTS `{{prefix}}uuims_role_field` (
  `role_id` INT UNSIGNED NOT NULL,
  `field_id` INT UNSIGNED NOT NULL,
  `perm` ENUM('hidden','masked','readonly','editable') NOT NULL,
  PRIMARY KEY (`role_id`, `field_id`),
  KEY `idx_field` (`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='role field permission';

-- P5 站内消息 (in-app messages, one row per recipient; no push yet). Sent by App\Uuims\Services\MessageService.
CREATE TABLE IF NOT EXISTS `{{prefix}}uuims_user_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'recipient (users.id)',
  `sender_id` INT UNSIGNED NULL COMMENT 'sender uuims id, NULL = system',
  `title` VARCHAR(128) NOT NULL,
  `content` TEXT NULL,
  `level` VARCHAR(16) NOT NULL DEFAULT 'info' COMMENT 'info | success | warning | error',
  `link` VARCHAR(255) NULL COMMENT 'optional in-app target, e.g. /p/uuims.sample.tenant-detail?id=1001',
  `read_at` DATETIME NULL,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`, `read_at`),
  KEY `idx_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='in-app messages per administrator';
