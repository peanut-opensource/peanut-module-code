CREATE TABLE `pa_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(30) NOT NULL DEFAULT '',
  `name` varchar(60) NOT NULL DEFAULT '',
  `value` text,
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_name` (`type`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='实例级配置（仅 Platform/部署拥有）';

CREATE TABLE `pa_customer_service_setting` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `qr_file_id` int unsigned DEFAULT NULL,
  `wechat` varchar(100) NOT NULL DEFAULT '',
  `phone` varchar(50) NOT NULL DEFAULT '',
  `service_time` varchar(100) NOT NULL DEFAULT '',
  `create_time` int unsigned DEFAULT NULL,
  `update_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_service_setting_tenant` (`tenant_id`),
  KEY `idx_customer_service_setting_tenant_qr` (`tenant_id`,`qr_file_id`),
  CONSTRAINT `fk_customer_service_setting_qr_file` FOREIGN KEY (`tenant_id`, `qr_file_id`) REFERENCES `pa_file` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_customer_service_setting_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Tenant客服联系方式';

CREATE TABLE `pa_decorate_page` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` tinyint unsigned NOT NULL COMMENT '1首页 2个人中心 3客服 4PC首页 5系统风格',
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_decorate_page_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_decorate_page_tenant_type` (`tenant_id`,`type`),
  CONSTRAINT `fk_decorate_page_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='装修页面';

CREATE TABLE `pa_decorate_tabbar` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `position` tinyint unsigned NOT NULL,
  `name` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `link` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '{}',
  `is_show` tinyint unsigned NOT NULL DEFAULT '1',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `selected` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '选中图标：local 相对 URI 或云/CDN 绝对 URL',
  `unselected` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '未选图标：local 相对 URI 或云/CDN 绝对 URL',
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_decorate_tabbar_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_decorate_tabbar_tenant_position` (`tenant_id`,`position`),
  CONSTRAINT `fk_decorate_tabbar_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='装修 Tabbar';

CREATE TABLE `pa_decorate_tabbar_setting` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `style` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `create_time` int unsigned DEFAULT NULL,
  `update_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_decorate_tabbar_setting_tenant` (`tenant_id`),
  CONSTRAINT `fk_decorate_tabbar_setting_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_decorate_tabbar_setting_style` CHECK ((json_valid(`style`) and (json_type(`style`) = _utf8mb4'OBJECT')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tenant 装修 Tabbar 样式';

CREATE TABLE `pa_generator_column` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `table_id` int unsigned NOT NULL DEFAULT '0',
  `column_name` varchar(64) NOT NULL DEFAULT '',
  `column_comment` varchar(300) NOT NULL DEFAULT '',
  `column_type` varchar(100) NOT NULL DEFAULT '',
  `php_type` varchar(20) NOT NULL DEFAULT 'string',
  `is_required` tinyint unsigned NOT NULL DEFAULT '0',
  `is_pk` tinyint unsigned NOT NULL DEFAULT '0',
  `is_insert` tinyint unsigned NOT NULL DEFAULT '0',
  `is_update` tinyint unsigned NOT NULL DEFAULT '0',
  `is_lists` tinyint unsigned NOT NULL DEFAULT '0',
  `is_query` tinyint unsigned NOT NULL DEFAULT '0',
  `query_type` varchar(20) NOT NULL DEFAULT '=',
  `view_type` varchar(20) NOT NULL DEFAULT 'input',
  `dict_type` varchar(100) NOT NULL DEFAULT '',
  `sort` smallint unsigned NOT NULL DEFAULT '0',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_table_column` (`table_id`,`column_name`),
  KEY `idx_table_sort` (`table_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='安全代码生成器字段配置';

CREATE TABLE `pa_generator_download` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned NOT NULL DEFAULT '0' COMMENT '令牌所有者管理员ID',
  `token_hash` char(64) NOT NULL DEFAULT '' COMMENT '下载令牌SHA-256',
  `archive_path` varchar(255) NOT NULL DEFAULT '' COMMENT '生成根目录内相对路径',
  `download_name` varchar(120) NOT NULL DEFAULT '',
  `expire_time` int unsigned NOT NULL DEFAULT '0',
  `used_time` int unsigned NOT NULL DEFAULT '0',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_owner_expire` (`admin_id`,`expire_time`,`used_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='代码生成器一次性下载令牌';

CREATE TABLE `pa_generator_table` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned NOT NULL DEFAULT '0' COMMENT '配置所有者管理员ID',
  `table_name` varchar(64) NOT NULL DEFAULT '' COMMENT '数据库物理表名',
  `table_comment` varchar(300) NOT NULL DEFAULT '' COMMENT '表说明',
  `module_name` varchar(32) NOT NULL DEFAULT 'generated' COMMENT '生成模块名',
  `entity_name` varchar(64) NOT NULL DEFAULT '' COMMENT '生成实体类名',
  `template_type` varchar(10) NOT NULL DEFAULT 'crud' COMMENT '模板类型 crud/tree',
  `data_owner` varchar(20) DEFAULT NULL COMMENT 'Explicit generated data owner',
  `target_edition` varchar(20) DEFAULT NULL COMMENT 'Explicit generated deployment edition',
  `author` varchar(100) NOT NULL DEFAULT '' COMMENT '作者',
  `tree_config` json DEFAULT NULL COMMENT '树结构字段配置',
  `relations` json DEFAULT NULL COMMENT '模型关系配置',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_table` (`admin_id`,`table_name`),
  KEY `idx_owner_update` (`admin_id`,`update_time`),
  CONSTRAINT `chk_generator_data_owner` CHECK (((`data_owner` is null) or (`data_owner` in (_utf8mb4'tenant-orm',_utf8mb4'platform',_utf8mb4'instance',_utf8mb4'shared')))),
  CONSTRAINT `chk_generator_target_edition` CHECK (((`target_edition` is null) or (`target_edition` in (_utf8mb4'standalone',_utf8mb4'multi-tenant'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='安全代码生成器表配置';

CREATE TABLE `pa_hot_search` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL DEFAULT '' COMMENT '搜索词',
  `sort` smallint unsigned NOT NULL DEFAULT '0' COMMENT '排序（倒序）',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hot_search_tenant_id` (`tenant_id`,`id`),
  KEY `idx_hot_search_tenant_sort` (`tenant_id`,`sort`,`id`),
  CONSTRAINT `fk_hot_search_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='热门搜索词';

CREATE TABLE `pa_operation_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned NOT NULL DEFAULT '0',
  `username` varchar(50) NOT NULL DEFAULT '',
  `ip` varchar(50) NOT NULL DEFAULT '',
  `uri` varchar(200) NOT NULL DEFAULT '',
  `method` varchar(10) NOT NULL DEFAULT '',
  `request_id` varchar(128) NOT NULL DEFAULT '',
  `params` text,
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operation_log_tenant_id` (`tenant_id`,`id`),
  KEY `idx_operation_log_tenant_created` (`tenant_id`,`create_time`,`id`),
  CONSTRAINT `fk_operation_log_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='操作日志';

CREATE TABLE `pa_plugin_installation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plugin_key` varchar(96) NOT NULL,
  `installed_version` varchar(32) NOT NULL,
  `source` varchar(255) NOT NULL,
  `artifact_sha256` char(64) NOT NULL,
  `lock_digest` char(64) NOT NULL,
  `composer_identity_json` json NOT NULL,
  `npm_identity_json` json NOT NULL,
  `frontend_identity_json` json NOT NULL,
  `status` varchar(24) NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `installed_at` datetime(3) DEFAULT NULL,
  `activated_at` datetime(3) DEFAULT NULL,
  `upgraded_at` datetime(3) DEFAULT NULL,
  `uninstalled_at` datetime(3) DEFAULT NULL,
  `last_error_code` varchar(96) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin_installation_key` (`plugin_key`),
  KEY `idx_plugin_installation_status` (`status`,`plugin_key`),
  CONSTRAINT `chk_plugin_installation_status` CHECK ((`status` in (_utf8mb4'installing',_utf8mb4'active',_utf8mb4'upgrading',_utf8mb4'maintenance',_utf8mb4'failed',_utf8mb4'uninstalled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_plugin_module` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plugin_key` varchar(96) NOT NULL,
  `module_key` varchar(96) NOT NULL,
  `module_version` varchar(32) NOT NULL,
  `manifest_digest` char(64) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin_module_key` (`module_key`),
  KEY `idx_plugin_module_plugin` (`plugin_key`,`module_key`),
  CONSTRAINT `fk_plugin_module_plugin` FOREIGN KEY (`plugin_key`) REFERENCES `pa_plugin_installation` (`plugin_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_provider_qualification_evidence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `evidence_key` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `scope_reference` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `evidence_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `outcome` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `config_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status_code` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `observed_at` datetime(3) NOT NULL,
  `expires_at` datetime(3) NOT NULL,
  `recorded_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_qualification_evidence_key` (`evidence_key`),
  KEY `idx_provider_qualification_subject_observed` (`provider_key`,`scope_type`,`tenant_id`,`observed_at`),
  KEY `idx_provider_qualification_expiry` (`expires_at`,`id`),
  KEY `fk_provider_qualification_tenant` (`tenant_id`),
  CONSTRAINT `fk_provider_qualification_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_provider_qualification_digest` CHECK (regexp_like(`config_digest`,_utf8mb4'^[0-9a-f]{64}$')),
  CONSTRAINT `chk_provider_qualification_expiry_order` CHECK ((`expires_at` > `observed_at`)),
  CONSTRAINT `chk_provider_qualification_outcome` CHECK ((`outcome` in (_utf8mb4'passed',_utf8mb4'failed'))),
  CONSTRAINT `chk_provider_qualification_scope` CHECK ((((`scope_type` = _utf8mb4'tenant') and (`tenant_id` is not null)) or ((`scope_type` = _utf8mb4'instance') and (`tenant_id` is null)))),
  CONSTRAINT `chk_provider_qualification_type` CHECK ((`evidence_type` in (_utf8mb4'connectivity',_utf8mb4'callback',_utf8mb4'production')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Append-only safe Provider qualification evidence';

CREATE TABLE `pa_tenant_idempotency_record` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_member_id` bigint unsigned NOT NULL,
  `operation_key` varchar(160) NOT NULL,
  `idempotency_key_hash` char(64) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `status` varchar(16) NOT NULL,
  `response_status` smallint unsigned DEFAULT NULL,
  `response_body_json` json DEFAULT NULL,
  `resource_type` varchar(160) DEFAULT NULL,
  `resource_id` varchar(128) DEFAULT NULL,
  `expires_at` datetime(3) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`tenant_member_id`,`operation_key`,`idempotency_key_hash`),
  KEY `idx_tenant_idempotency_expiry` (`expires_at`,`status`,`id`),
  CONSTRAINT `fk_tenant_idempotency_member` FOREIGN KEY (`tenant_id`, `tenant_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_idempotency_status` CHECK ((`status` in (_utf8mb4'processing',_utf8mb4'completed',_utf8mb4'failed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_transaction_setting` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `cancel_unpaid_orders` tinyint unsigned NOT NULL DEFAULT '1',
  `cancel_unpaid_orders_times` int unsigned NOT NULL DEFAULT '30',
  `verification_orders` tinyint unsigned NOT NULL DEFAULT '1',
  `verification_orders_times` int unsigned NOT NULL DEFAULT '24',
  `create_time` int unsigned DEFAULT NULL,
  `update_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transaction_setting_tenant` (`tenant_id`),
  CONSTRAINT `fk_transaction_setting_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_transaction_setting_cancel_mode` CHECK ((`cancel_unpaid_orders` in (0,1))),
  CONSTRAINT `chk_transaction_setting_cancel_time` CHECK ((`cancel_unpaid_orders_times` > 0)),
  CONSTRAINT `chk_transaction_setting_verify_mode` CHECK ((`verification_orders` in (0,1))),
  CONSTRAINT `chk_transaction_setting_verify_time` CHECK ((`verification_orders_times` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Tenant交易运营政策';
