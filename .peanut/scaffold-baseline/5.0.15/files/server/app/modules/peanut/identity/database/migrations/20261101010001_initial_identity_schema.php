<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialIdentitySchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_account' => <<<'SQL'
CREATE TABLE `pa_account` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `display_name` varchar(120) NOT NULL,
  `avatar_uri` varchar(512) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `security_revision` bigint unsigned NOT NULL DEFAULT '1',
  `locked_until` datetime(3) DEFAULT NULL,
  `last_login_at` datetime(3) DEFAULT NULL,
  `closed_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_status` (`status`,`id`),
  CONSTRAINT `chk_account_closed` CHECK ((((`status` = _utf8mb4'closed') and (`closed_at` is not null)) or (`status` <> _utf8mb4'closed'))),
  CONSTRAINT `chk_account_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'locked',_utf8mb4'disabled',_utf8mb4'closed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_auth_security_event' => <<<'SQL'
CREATE TABLE `pa_auth_security_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `audience` varchar(16) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `outcome` varchar(16) NOT NULL,
  `reason_code` varchar(96) DEFAULT NULL,
  `account_id` bigint unsigned DEFAULT NULL,
  `credential_id` bigint unsigned DEFAULT NULL,
  `session_key` char(26) DEFAULT NULL,
  `identifier_hmac` char(64) DEFAULT NULL,
  `request_id` varchar(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `metadata_json` json DEFAULT NULL,
  `occurred_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_auth_event_account` (`account_id`,`occurred_at`),
  KEY `idx_auth_event_identifier` (`identifier_hmac`,`occurred_at`),
  KEY `idx_auth_event_ip` (`ip_address`,`occurred_at`),
  KEY `idx_auth_event_request` (`request_id`),
  KEY `idx_auth_event_time` (`occurred_at`,`id`),
  CONSTRAINT `chk_auth_event_audience` CHECK ((`audience` in (_utf8mb4'tenant',_utf8mb4'platform'))),
  CONSTRAINT `chk_auth_event_outcome` CHECK ((`outcome` in (_utf8mb4'success',_utf8mb4'denied',_utf8mb4'error')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_data_condition_definition' => <<<'SQL'
CREATE TABLE `pa_data_condition_definition` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(160) NOT NULL,
  `module_key` varchar(96) NOT NULL,
  `category` varchar(32) NOT NULL,
  `target_mode` varchar(32) NOT NULL,
  `config_schema_json` json DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `manifest_version` varchar(32) NOT NULL,
  `manifest_digest` char(64) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_condition_key` (`key`),
  CONSTRAINT `chk_data_condition_category` CHECK ((`category` in (_utf8mb4'tenant',_utf8mb4'self',_utf8mb4'department',_utf8mb4'selected',_utf8mb4'relation'))),
  CONSTRAINT `chk_data_condition_definition_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'retired'))),
  CONSTRAINT `chk_data_condition_target_mode` CHECK ((`target_mode` in (_utf8mb4'none',_utf8mb4'department',_utf8mb4'resource')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_module_installation' => <<<'SQL'
CREATE TABLE `pa_module_installation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(96) NOT NULL,
  `installed_version` varchar(32) NOT NULL,
  `manifest_schema_version` int unsigned NOT NULL,
  `manifest_digest` char(64) NOT NULL,
  `status` varchar(24) NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `installed_at` datetime(3) DEFAULT NULL,
  `activated_at` datetime(3) DEFAULT NULL,
  `upgraded_at` datetime(3) DEFAULT NULL,
  `last_error_code` varchar(96) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_installation_key` (`module_key`),
  KEY `idx_module_installation_status` (`status`,`module_key`),
  CONSTRAINT `chk_module_installation_status` CHECK ((`status` in (_utf8mb4'installing',_utf8mb4'active',_utf8mb4'upgrading',_utf8mb4'maintenance',_utf8mb4'failed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_permission' => <<<'SQL'
CREATE TABLE `pa_permission` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(160) NOT NULL,
  `module_key` varchar(96) NOT NULL,
  `type` varchar(32) NOT NULL,
  `name` varchar(160) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `risk_level` varchar(16) NOT NULL DEFAULT 'normal',
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `manifest_version` varchar(32) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `retired_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_permission_key` (`key`),
  KEY `idx_permission_module` (`module_key`,`status`,`type`),
  CONSTRAINT `chk_permission_retired` CHECK ((((`status` = _utf8mb4'retired') and (`retired_at` is not null)) or (`status` <> _utf8mb4'retired'))),
  CONSTRAINT `chk_permission_risk` CHECK ((`risk_level` in (_utf8mb4'normal',_utf8mb4'sensitive',_utf8mb4'critical'))),
  CONSTRAINT `chk_permission_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'retired'))),
  CONSTRAINT `chk_permission_type` CHECK ((`type` in (_utf8mb4'menu',_utf8mb4'action',_utf8mb4'api')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_platform_audit_event' => <<<'SQL'
CREATE TABLE `pa_platform_audit_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(96) NOT NULL,
  `action` varchar(160) NOT NULL,
  `outcome` varchar(16) NOT NULL,
  `reason_code` varchar(96) DEFAULT NULL,
  `operator_id` bigint unsigned DEFAULT NULL,
  `account_id` bigint unsigned DEFAULT NULL,
  `target_type` varchar(96) DEFAULT NULL,
  `target_id` varchar(128) DEFAULT NULL,
  `request_id` varchar(64) NOT NULL,
  `operation_id` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `before_json` json DEFAULT NULL,
  `after_json` json DEFAULT NULL,
  `metadata_json` json DEFAULT NULL,
  `occurred_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_platform_audit_time` (`occurred_at`,`id`),
  KEY `idx_platform_audit_operator` (`operator_id`,`occurred_at`),
  KEY `idx_platform_audit_target` (`target_type`,`target_id`,`occurred_at`),
  KEY `idx_platform_audit_request` (`request_id`),
  CONSTRAINT `chk_platform_audit_outcome` CHECK ((`outcome` in (_utf8mb4'success',_utf8mb4'denied',_utf8mb4'error')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_platform_role' => <<<'SQL'
CREATE TABLE `pa_platform_role` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(96) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `is_builtin` tinyint unsigned NOT NULL DEFAULT '0',
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `archived_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_role_key` (`key`),
  KEY `idx_platform_role_status` (`status`,`id`),
  CONSTRAINT `chk_platform_role_builtin` CHECK ((`is_builtin` in (0,1))),
  CONSTRAINT `chk_platform_role_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'disabled',_utf8mb4'archived')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_protected_resource' => <<<'SQL'
CREATE TABLE `pa_protected_resource` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(160) NOT NULL,
  `module_key` varchar(96) NOT NULL,
  `name` varchar(160) NOT NULL,
  `ownership` varchar(32) NOT NULL,
  `provider_key` varchar(160) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `manifest_version` varchar(32) NOT NULL,
  `manifest_digest` char(64) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `retired_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_protected_resource_key` (`key`),
  KEY `idx_protected_resource_module` (`module_key`,`status`),
  CONSTRAINT `chk_protected_resource_ownership` CHECK ((`ownership` in (_utf8mb4'tenant_owned',_utf8mb4'business_target_owned',_utf8mb4'shared_master',_utf8mb4'global_reference',_utf8mb4'platform_internal'))),
  CONSTRAINT `chk_protected_resource_retired` CHECK ((((`status` = _utf8mb4'retired') and (`retired_at` is not null)) or (`status` <> _utf8mb4'retired'))),
  CONSTRAINT `chk_protected_resource_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'retired')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_system_menu' => <<<'SQL'
CREATE TABLE `pa_system_menu` (
  `menu_key` varchar(160) NOT NULL,
  `parent_key` varchar(160) DEFAULT NULL,
  `module_key` varchar(96) NOT NULL DEFAULT 'application',
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pid` int unsigned NOT NULL DEFAULT '0',
  `type` char(1) NOT NULL DEFAULT 'C' COMMENT 'M目录 C菜单 A按钮',
  `name` varchar(50) NOT NULL DEFAULT '',
  `icon` varchar(100) NOT NULL DEFAULT '',
  `sort` smallint NOT NULL DEFAULT '0',
  `perms` varchar(100) NOT NULL DEFAULT '' COMMENT '权限标识',
  `paths` varchar(200) NOT NULL DEFAULT '' COMMENT '路由路径',
  `component` varchar(200) NOT NULL DEFAULT '' COMMENT '前端组件',
  `is_cache` tinyint(1) NOT NULL DEFAULT '0',
  `is_show` tinyint(1) NOT NULL DEFAULT '1',
  `is_disable` tinyint(1) NOT NULL DEFAULT '0',
  `upstream_defaults_json` json DEFAULT NULL,
  `menu_conflict_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_menu_key` (`menu_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='系统菜单';
SQL,
        'pa_target_type' => <<<'SQL'
CREATE TABLE `pa_target_type` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(160) NOT NULL,
  `module_key` varchar(96) NOT NULL,
  `name` varchar(160) NOT NULL,
  `resolver_key` varchar(160) NOT NULL,
  `catalog_provider_key` varchar(160) NOT NULL,
  `id_format` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `manifest_version` varchar(32) NOT NULL,
  `manifest_digest` char(64) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_target_type_key` (`key`),
  KEY `idx_target_type_module` (`module_key`,`status`),
  CONSTRAINT `chk_target_type_id_format` CHECK ((`id_format` in (_utf8mb4'decimal',_utf8mb4'uuid',_utf8mb4'ulid',_utf8mb4'string'))),
  CONSTRAINT `chk_target_type_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'retired')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_tenant' => <<<'SQL'
CREATE TABLE `pa_tenant` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name` varchar(160) NOT NULL,
  `display_name` varchar(160) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'provisioning',
  `locale` varchar(16) NOT NULL DEFAULT 'zh-CN',
  `timezone` varchar(64) NOT NULL DEFAULT 'Asia/Shanghai',
  `security_revision` bigint unsigned NOT NULL DEFAULT '1',
  `authorization_revision` bigint unsigned NOT NULL DEFAULT '1',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `activated_at` datetime(3) DEFAULT NULL,
  `suspended_at` datetime(3) DEFAULT NULL,
  `closed_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`code`),
  KEY `idx_tenant_status` (`status`,`id`),
  CONSTRAINT `chk_tenant_closed` CHECK ((((`status` = _utf8mb4'closed') and (`closed_at` is not null)) or (`status` <> _utf8mb4'closed'))),
  CONSTRAINT `chk_tenant_status` CHECK ((`status` in (_utf8mb4'provisioning',_utf8mb4'active',_utf8mb4'suspended',_utf8mb4'closed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_tenant_audit_event' => <<<'SQL'
CREATE TABLE `pa_tenant_audit_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `event_type` varchar(96) NOT NULL,
  `action` varchar(160) NOT NULL,
  `outcome` varchar(16) NOT NULL,
  `reason_code` varchar(96) DEFAULT NULL,
  `actor_tenant_id` bigint unsigned DEFAULT NULL,
  `actor_tenant_member_id` bigint unsigned DEFAULT NULL,
  `actor_account_id` bigint unsigned DEFAULT NULL,
  `actor_platform_operator_id` bigint unsigned DEFAULT NULL,
  `actor_type` varchar(32) NOT NULL,
  `target_resource_type` varchar(160) DEFAULT NULL,
  `target_resource_id` varchar(128) DEFAULT NULL,
  `boundary_target_type` varchar(160) DEFAULT NULL,
  `boundary_target_id` varchar(128) DEFAULT NULL,
  `target_count` int unsigned NOT NULL DEFAULT '0',
  `target_set_digest` char(64) DEFAULT NULL,
  `authorization_basis_json` json DEFAULT NULL,
  `request_id` varchar(64) NOT NULL,
  `operation_id` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `before_json` json DEFAULT NULL,
  `after_json` json DEFAULT NULL,
  `metadata_json` json DEFAULT NULL,
  `occurred_at` datetime(3) NOT NULL,
  `revocation_fact_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `revocation_fact_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_identity_revocation_fact` (`revocation_fact_key`),
  KEY `idx_tenant_audit_time` (`tenant_id`,`occurred_at`,`id`),
  KEY `idx_tenant_audit_member` (`tenant_id`,`actor_tenant_member_id`,`occurred_at`),
  KEY `idx_tenant_audit_actor_tenant` (`actor_tenant_id`,`occurred_at`,`id`),
  KEY `idx_tenant_audit_target` (`tenant_id`,`target_resource_type`,`target_resource_id`,`occurred_at`),
  KEY `idx_tenant_audit_boundary_target` (`tenant_id`,`boundary_target_type`,`boundary_target_id`,`occurred_at`),
  KEY `idx_tenant_audit_request` (`request_id`),
  KEY `idx_identity_revocation_scan` (`tenant_id`,`event_type`,`id`),
  CONSTRAINT `chk_tenant_audit_actor` CHECK ((((`actor_type` = _utf8mb4'member') and (`actor_tenant_id` = `tenant_id`) and (`actor_tenant_member_id` is not null) and (`actor_account_id` is not null) and (`actor_platform_operator_id` is null)) or ((`actor_type` = _utf8mb4'tenant_system') and (`actor_tenant_id` = `tenant_id`) and (`actor_tenant_member_id` is null) and (`actor_account_id` is null) and (`actor_platform_operator_id` is null)) or ((`actor_type` = _utf8mb4'platform_operator') and (`actor_tenant_id` is null) and (`actor_tenant_member_id` is null) and (`actor_account_id` is not null) and (`actor_platform_operator_id` is not null)))),
  CONSTRAINT `chk_tenant_audit_actor_type` CHECK ((`actor_type` in (_utf8mb4'member',_utf8mb4'tenant_system',_utf8mb4'platform_operator'))),
  CONSTRAINT `chk_tenant_audit_outcome` CHECK ((`outcome` in (_utf8mb4'success',_utf8mb4'denied',_utf8mb4'error')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_credential' => <<<'SQL'
CREATE TABLE `pa_credential` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `kind` varchar(32) NOT NULL,
  `identifier_type` varchar(32) NOT NULL,
  `identifier_normalized` varchar(255) NOT NULL,
  `secret_hash` varchar(255) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `failed_attempts` int unsigned NOT NULL DEFAULT '0',
  `locked_until` datetime(3) DEFAULT NULL,
  `verified_at` datetime(3) NOT NULL,
  `last_used_at` datetime(3) DEFAULT NULL,
  `secret_changed_at` datetime(3) NOT NULL,
  `expires_at` datetime(3) DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `revoked_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_credential_identifier` (`identifier_type`,`identifier_normalized`),
  KEY `idx_credential_account` (`account_id`,`status`),
  CONSTRAINT `fk_credential_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_credential_identifier_type` CHECK ((`identifier_type` = _utf8mb4'email')),
  CONSTRAINT `chk_credential_kind` CHECK ((`kind` = _utf8mb4'email_password')),
  CONSTRAINT `chk_credential_revoked` CHECK ((((`status` = _utf8mb4'revoked') and (`revoked_at` is not null)) or (`status` <> _utf8mb4'revoked'))),
  CONSTRAINT `chk_credential_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'locked',_utf8mb4'revoked')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_department' => <<<'SQL'
CREATE TABLE `pa_department` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(120) NOT NULL,
  `leader` varchar(50) NOT NULL DEFAULT '',
  `leader_member_id` bigint unsigned DEFAULT NULL,
  `mobile` varchar(20) NOT NULL DEFAULT '',
  `sort_order` int NOT NULL DEFAULT '0',
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `archived_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_department_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_department_code` (`tenant_id`,`code`),
  KEY `idx_department_parent` (`tenant_id`,`parent_id`,`status`,`sort_order`),
  KEY `idx_department_leader_member` (`tenant_id`,`leader_member_id`),
  CONSTRAINT `fk_department_parent` FOREIGN KEY (`tenant_id`, `parent_id`) REFERENCES `pa_department` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_department_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_department_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'disabled',_utf8mb4'archived')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_jobs' => <<<'SQL'
CREATE TABLE `pa_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '岗位名称',
  `active_name` varchar(50) GENERATED ALWAYS AS (if((`delete_time` is null),`name`,NULL)) STORED,
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '岗位编码',
  `active_code` varchar(64) GENERATED ALWAYS AS (if((`delete_time` is null),`code`,NULL)) STORED,
  `sort` smallint NOT NULL DEFAULT '0' COMMENT '排序',
  `is_disable` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否禁用：0启用 1禁用',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0停用 1正常',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `remark` varchar(200) NOT NULL DEFAULT '' COMMENT '备注',
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_jobs_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_jobs_tenant_active_name` (`tenant_id`,`active_name`),
  UNIQUE KEY `uk_jobs_tenant_active_code` (`tenant_id`,`active_code`),
  KEY `idx_code` (`code`),
  KEY `idx_jobs_tenant_code` (`tenant_id`,`code`),
  CONSTRAINT `fk_jobs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='岗位';
SQL,
        'pa_login_challenge' => <<<'SQL'
CREATE TABLE `pa_login_challenge` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `challenge_key` char(26) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `purpose` varchar(32) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `source_session_key` char(26) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `expires_at` datetime(3) NOT NULL,
  `used_at` datetime(3) DEFAULT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `client_key` varchar(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_login_challenge_key` (`challenge_key`),
  UNIQUE KEY `uk_login_challenge_token` (`token_hash`),
  KEY `idx_login_challenge_account` (`account_id`,`status`,`expires_at`),
  CONSTRAINT `fk_login_challenge_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_login_challenge_client` CHECK (regexp_like(`client_key`,_utf8mb4'^[a-z][a-z0-9-]{0,63}$',_utf8mb4'c')),
  CONSTRAINT `chk_login_challenge_purpose` CHECK ((`purpose` in (_utf8mb4'tenant_login',_utf8mb4'tenant_switch'))),
  CONSTRAINT `chk_login_challenge_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'used',_utf8mb4'revoked',_utf8mb4'expired')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_menu_definition' => <<<'SQL'
CREATE TABLE `pa_menu_definition` (
  `is_show` tinyint NOT NULL DEFAULT '1',
  `is_cache` tinyint NOT NULL DEFAULT '0',
  `is_disable` tinyint NOT NULL DEFAULT '0',
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(160) NOT NULL,
  `module_key` varchar(96) NOT NULL,
  `scope` varchar(16) NOT NULL,
  `parent_key` varchar(160) DEFAULT NULL,
  `type` varchar(16) NOT NULL,
  `name` varchar(160) NOT NULL,
  `route_name` varchar(160) DEFAULT NULL,
  `route_path` varchar(255) DEFAULT NULL,
  `component_key` varchar(160) DEFAULT NULL,
  `icon` varchar(64) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `required_permission_id` bigint unsigned DEFAULT NULL,
  `client_keys_json` json NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `manifest_digest` char(64) NOT NULL,
  `upstream_defaults_json` json DEFAULT NULL,
  `menu_conflict_json` json DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_definition_key` (`key`),
  UNIQUE KEY `uk_menu_route_name` (`scope`,`route_name`),
  KEY `idx_menu_module` (`module_key`,`scope`,`status`,`sort_order`),
  KEY `fk_menu_permission` (`required_permission_id`),
  CONSTRAINT `fk_menu_permission` FOREIGN KEY (`required_permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_menu_page` CHECK (((`type` <> _utf8mb4'page') or ((`route_name` is not null) and (`component_key` is not null) and (`required_permission_id` is not null)))),
  CONSTRAINT `chk_menu_scope` CHECK ((`scope` in (_utf8mb4'platform',_utf8mb4'tenant'))),
  CONSTRAINT `chk_menu_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'retired'))),
  CONSTRAINT `chk_menu_type` CHECK ((`type` in (_utf8mb4'group',_utf8mb4'page',_utf8mb4'link')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_platform_operator' => <<<'SQL'
CREATE TABLE `pa_platform_operator` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `display_name` varchar(120) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `security_revision` bigint unsigned NOT NULL DEFAULT '1',
  `suspended_at` datetime(3) DEFAULT NULL,
  `closed_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_operator_account` (`account_id`),
  KEY `idx_platform_operator_status` (`status`,`id`),
  CONSTRAINT `fk_platform_operator_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_platform_operator_closed` CHECK ((((`status` = _utf8mb4'closed') and (`closed_at` is not null)) or (`status` <> _utf8mb4'closed'))),
  CONSTRAINT `chk_platform_operator_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'suspended',_utf8mb4'closed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_platform_role_permission' => <<<'SQL'
CREATE TABLE `pa_platform_role_permission` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `platform_role_id` bigint unsigned NOT NULL,
  `permission_id` bigint unsigned NOT NULL,
  `granted_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_role_permission` (`platform_role_id`,`permission_id`),
  KEY `fk_platform_role_permission_permission` (`permission_id`),
  CONSTRAINT `fk_platform_role_permission_permission` FOREIGN KEY (`permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_role_permission_role` FOREIGN KEY (`platform_role_id`) REFERENCES `pa_platform_role` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_resource_operation' => <<<'SQL'
CREATE TABLE `pa_resource_operation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `protected_resource_id` bigint unsigned NOT NULL,
  `operation` varchar(64) NOT NULL,
  `access_mode` varchar(32) NOT NULL,
  `target_cardinality` varchar(32) NOT NULL,
  `permission_match` varchar(8) NOT NULL DEFAULT 'all',
  `audit_level` varchar(32) NOT NULL DEFAULT 'deny_and_write',
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `manifest_digest` char(64) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation` (`protected_resource_id`,`operation`),
  CONSTRAINT `fk_resource_operation_resource` FOREIGN KEY (`protected_resource_id`) REFERENCES `pa_protected_resource` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_resource_operation_access` CHECK ((`access_mode` in (_utf8mb4'tenant_wide',_utf8mb4'rule_filtered',_utf8mb4'explicit_targets',_utf8mb4'global_reference_read',_utf8mb4'system_internal'))),
  CONSTRAINT `chk_resource_operation_audit` CHECK ((`audit_level` in (_utf8mb4'deny',_utf8mb4'write',_utf8mb4'deny_and_write',_utf8mb4'all'))),
  CONSTRAINT `chk_resource_operation_cardinality` CHECK ((`target_cardinality` in (_utf8mb4'none',_utf8mb4'one_required',_utf8mb4'many_readable',_utf8mb4'aggregate_read',_utf8mb4'policy_publish',_utf8mb4'bulk_write'))),
  CONSTRAINT `chk_resource_operation_permission_match` CHECK ((`permission_match` in (_utf8mb4'all',_utf8mb4'any'))),
  CONSTRAINT `chk_resource_operation_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'retired')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_role' => <<<'SQL'
CREATE TABLE `pa_role` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `key` varchar(96) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `is_builtin` tinyint unsigned NOT NULL DEFAULT '0',
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `authorization_revision` bigint unsigned NOT NULL DEFAULT '1',
  `archived_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_role_key` (`tenant_id`,`key`),
  KEY `idx_role_status` (`tenant_id`,`status`,`id`),
  CONSTRAINT `fk_role_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_role_builtin` CHECK ((`is_builtin` in (0,1))),
  CONSTRAINT `chk_role_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'disabled',_utf8mb4'archived')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_tenant_entry_binding' => <<<'SQL'
CREATE TABLE `pa_tenant_entry_binding` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `host` varchar(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `client_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_entry_binding` (`host`,`client_key`),
  KEY `idx_tenant_entry_lookup` (`host`,`client_key`,`status`,`tenant_id`),
  KEY `fk_tenant_entry_binding_tenant` (`tenant_id`),
  CONSTRAINT `fk_tenant_entry_binding_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_entry_binding_client` CHECK (regexp_like(`client_key`,_utf8mb4'^[a-z][a-z0-9-]{0,63}$',_utf8mb4'c')),
  CONSTRAINT `chk_tenant_entry_binding_host` CHECK (((`host` = lower(`host`)) and (char_length(`host`) between 1 and 253))),
  CONSTRAINT `chk_tenant_entry_binding_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'disabled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_tenant_module' => <<<'SQL'
CREATE TABLE `pa_tenant_module` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `module_key` varchar(96) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'disabled',
  `source` varchar(32) NOT NULL DEFAULT 'manual',
  `config_json` json DEFAULT NULL,
  `config_revision` bigint unsigned NOT NULL DEFAULT '1',
  `authorization_revision` bigint unsigned NOT NULL DEFAULT '1',
  `effective_at` datetime(3) DEFAULT NULL,
  `expires_at` datetime(3) DEFAULT NULL,
  `enabled_at` datetime(3) DEFAULT NULL,
  `disabled_at` datetime(3) DEFAULT NULL,
  `disabled_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_module` (`tenant_id`,`module_key`),
  KEY `idx_tenant_module_status` (`tenant_id`,`status`,`module_key`),
  CONSTRAINT `fk_tenant_module_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_module_dates` CHECK (((`expires_at` is null) or (`effective_at` is null) or (`expires_at` > `effective_at`))),
  CONSTRAINT `chk_tenant_module_source` CHECK ((`source` in (_utf8mb4'manual',_utf8mb4'product_profile',_utf8mb4'license'))),
  CONSTRAINT `chk_tenant_module_status` CHECK ((`status` in (_utf8mb4'disabled',_utf8mb4'enabled',_utf8mb4'expired')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_platform_operator_role' => <<<'SQL'
CREATE TABLE `pa_platform_operator_role` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `platform_operator_id` bigint unsigned NOT NULL,
  `platform_role_id` bigint unsigned NOT NULL,
  `assigned_by_operator_id` bigint unsigned DEFAULT NULL,
  `assigned_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_operator_role` (`platform_operator_id`,`platform_role_id`),
  KEY `fk_platform_operator_role_role` (`platform_role_id`),
  KEY `fk_platform_operator_role_assigner` (`assigned_by_operator_id`),
  CONSTRAINT `fk_platform_operator_role_assigner` FOREIGN KEY (`assigned_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_operator_role_operator` FOREIGN KEY (`platform_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_operator_role_role` FOREIGN KEY (`platform_role_id`) REFERENCES `pa_platform_role` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_platform_session' => <<<'SQL'
CREATE TABLE `pa_platform_session` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_key` char(26) NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `platform_operator_id` bigint unsigned NOT NULL,
  `client_key` varchar(64) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `account_security_revision` bigint unsigned NOT NULL,
  `operator_security_revision` bigint unsigned NOT NULL,
  `issued_at` datetime(3) NOT NULL,
  `last_seen_at` datetime(3) NOT NULL,
  `idle_expires_at` datetime(3) NOT NULL,
  `absolute_expires_at` datetime(3) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  `revoke_reason` varchar(64) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_session_key` (`session_key`),
  KEY `idx_platform_session_operator` (`platform_operator_id`,`status`,`absolute_expires_at`),
  KEY `idx_platform_session_account` (`account_id`,`status`,`absolute_expires_at`),
  CONSTRAINT `fk_platform_session_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_session_operator` FOREIGN KEY (`platform_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_platform_session_client` CHECK ((`client_key` = _utf8mb4'platform-web')),
  CONSTRAINT `chk_platform_session_expiry` CHECK ((`idle_expires_at` <= `absolute_expires_at`)),
  CONSTRAINT `chk_platform_session_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'revoked',_utf8mb4'expired')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_resource_operation_condition' => <<<'SQL'
CREATE TABLE `pa_resource_operation_condition` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `resource_operation_id` bigint unsigned NOT NULL,
  `condition_definition_id` bigint unsigned NOT NULL,
  `selector_resource_key` varchar(160) DEFAULT NULL,
  `selector_resource_key_norm` varchar(160) GENERATED ALWAYS AS (coalesce(`selector_resource_key`,_utf8mb4'')) STORED,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation_condition` (`resource_operation_id`,`condition_definition_id`,`selector_resource_key_norm`),
  KEY `fk_operation_condition_definition` (`condition_definition_id`),
  CONSTRAINT `fk_operation_condition_definition` FOREIGN KEY (`condition_definition_id`) REFERENCES `pa_data_condition_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_condition_operation` FOREIGN KEY (`resource_operation_id`) REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_operation_condition_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'retired')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_resource_operation_permission' => <<<'SQL'
CREATE TABLE `pa_resource_operation_permission` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `resource_operation_id` bigint unsigned NOT NULL,
  `permission_id` bigint unsigned NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation_permission` (`resource_operation_id`,`permission_id`),
  KEY `fk_operation_permission_permission` (`permission_id`),
  CONSTRAINT `fk_operation_permission_operation` FOREIGN KEY (`resource_operation_id`) REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_permission_permission` FOREIGN KEY (`permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_resource_operation_target_type' => <<<'SQL'
CREATE TABLE `pa_resource_operation_target_type` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `resource_operation_id` bigint unsigned NOT NULL,
  `target_type_id` bigint unsigned NOT NULL,
  `target_role` varchar(64) NOT NULL DEFAULT 'primary',
  `input_mode` varchar(16) NOT NULL DEFAULT 'explicit',
  `policy_selection_permission_id` bigint unsigned DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation_target_type` (`resource_operation_id`,`target_role`,`target_type_id`),
  KEY `fk_operation_target_type` (`target_type_id`),
  KEY `fk_operation_target_selection_permission` (`policy_selection_permission_id`),
  CONSTRAINT `fk_operation_target_operation` FOREIGN KEY (`resource_operation_id`) REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_target_selection_permission` FOREIGN KEY (`policy_selection_permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_target_type` FOREIGN KEY (`target_type_id`) REFERENCES `pa_target_type` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_operation_target_input` CHECK ((`input_mode` in (_utf8mb4'explicit',_utf8mb4'derived',_utf8mb4'either'))),
  CONSTRAINT `chk_operation_target_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'retired')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_tenant_member' => <<<'SQL'
CREATE TABLE `pa_tenant_member` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `member_no` varchar(64) DEFAULT NULL,
  `display_name` varchar(120) DEFAULT NULL,
  `member_type` varchar(32) NOT NULL DEFAULT 'internal',
  `primary_department_id` bigint unsigned DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `security_revision` bigint unsigned NOT NULL DEFAULT '1',
  `authorization_revision` bigint unsigned NOT NULL DEFAULT '1',
  `joined_at` datetime(3) DEFAULT NULL,
  `suspended_at` datetime(3) DEFAULT NULL,
  `left_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_member_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_tenant_member_account` (`tenant_id`,`account_id`),
  UNIQUE KEY `uk_tenant_member_no` (`tenant_id`,`member_no`),
  KEY `idx_member_department` (`tenant_id`,`primary_department_id`,`status`),
  KEY `fk_tenant_member_account` (`account_id`),
  CONSTRAINT `fk_tenant_member_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_member_department` FOREIGN KEY (`tenant_id`, `primary_department_id`) REFERENCES `pa_department` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_member_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_member_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'active',_utf8mb4'suspended',_utf8mb4'left'))),
  CONSTRAINT `chk_tenant_member_type` CHECK ((`member_type` in (_utf8mb4'internal',_utf8mb4'external')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_data_permission_policy' => <<<'SQL'
CREATE TABLE `pa_data_permission_policy` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  `protected_resource_id` bigint unsigned NOT NULL,
  `resource_operation_id` bigint unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `valid_from` datetime(3) DEFAULT NULL,
  `valid_until` datetime(3) DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `reason` varchar(300) DEFAULT NULL,
  `created_by_member_id` bigint unsigned NOT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `archived_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_policy_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_data_policy` (`tenant_id`,`role_id`,`resource_operation_id`),
  KEY `idx_data_policy_active` (`tenant_id`,`role_id`,`status`,`valid_until`),
  KEY `fk_data_policy_resource` (`protected_resource_id`),
  KEY `fk_data_policy_operation` (`resource_operation_id`),
  KEY `fk_data_policy_creator` (`tenant_id`,`created_by_member_id`),
  KEY `fk_data_policy_updater` (`tenant_id`,`updated_by_member_id`),
  CONSTRAINT `fk_data_policy_creator` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_operation` FOREIGN KEY (`resource_operation_id`) REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_resource` FOREIGN KEY (`protected_resource_id`) REFERENCES `pa_protected_resource` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_role` FOREIGN KEY (`tenant_id`, `role_id`) REFERENCES `pa_role` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_updater` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_policy_archived` CHECK ((((`status` = _utf8mb4'archived') and (`archived_at` is not null)) or (`status` <> _utf8mb4'archived'))),
  CONSTRAINT `chk_data_policy_dates` CHECK (((`valid_until` is null) or (`valid_from` is null) or (`valid_until` > `valid_from`))),
  CONSTRAINT `chk_data_policy_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'disabled',_utf8mb4'archived')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_data_permission_target_set' => <<<'SQL'
CREATE TABLE `pa_data_permission_target_set` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `target_mode` varchar(32) NOT NULL,
  `target_resource_key` varchar(160) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_by_member_id` bigint unsigned NOT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `archived_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_target_set_tenant_id` (`tenant_id`,`id`),
  KEY `idx_data_target_set_status` (`tenant_id`,`target_resource_key`,`status`,`id`),
  KEY `fk_data_target_set_creator` (`tenant_id`,`created_by_member_id`),
  KEY `fk_data_target_set_updater` (`tenant_id`,`updated_by_member_id`),
  CONSTRAINT `fk_data_target_set_creator` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_set_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_set_updater` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_target_set_archived` CHECK ((((`status` = _utf8mb4'archived') and (`archived_at` is not null)) or (`status` <> _utf8mb4'archived'))),
  CONSTRAINT `chk_data_target_set_mode` CHECK ((`target_mode` in (_utf8mb4'department',_utf8mb4'resource'))),
  CONSTRAINT `chk_data_target_set_resource` CHECK ((((`target_mode` = _utf8mb4'department') and (`target_resource_key` = _utf8mb4'core.department')) or ((`target_mode` = _utf8mb4'resource') and (`target_resource_key` <> _utf8mb4'core.department')))),
  CONSTRAINT `chk_data_target_set_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'disabled',_utf8mb4'archived')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_member_role' => <<<'SQL'
CREATE TABLE `pa_member_role` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_member_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  `assigned_by_member_id` bigint unsigned DEFAULT NULL,
  `assigned_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_role` (`tenant_id`,`tenant_member_id`,`role_id`),
  KEY `fk_member_role_role` (`tenant_id`,`role_id`),
  KEY `fk_member_role_assigner` (`tenant_id`,`assigned_by_member_id`),
  CONSTRAINT `fk_member_role_assigner` FOREIGN KEY (`tenant_id`, `assigned_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_role_member` FOREIGN KEY (`tenant_id`, `tenant_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_role_role` FOREIGN KEY (`tenant_id`, `role_id`) REFERENCES `pa_role` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_member_job' => <<<'SQL'
CREATE TABLE `pa_member_job` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_member_id` bigint unsigned NOT NULL,
  `job_id` int unsigned NOT NULL,
  `assigned_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_job` (`tenant_id`,`tenant_member_id`,`job_id`),
  KEY `idx_member_job_job` (`tenant_id`,`job_id`),
  CONSTRAINT `fk_member_job_job` FOREIGN KEY (`tenant_id`, `job_id`) REFERENCES `pa_jobs` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_job_member` FOREIGN KEY (`tenant_id`, `tenant_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_platform_session_token' => <<<'SQL'
CREATE TABLE `pa_platform_session_token` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `token_type` varchar(16) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `parent_token_id` bigint unsigned DEFAULT NULL,
  `replaced_by_token_id` bigint unsigned DEFAULT NULL,
  `expires_at` datetime(3) NOT NULL,
  `used_at` datetime(3) DEFAULT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_session_token_hash` (`token_hash`),
  KEY `idx_platform_session_token_active` (`session_id`,`token_type`,`status`,`expires_at`),
  KEY `fk_platform_token_parent` (`parent_token_id`),
  KEY `fk_platform_token_replacement` (`replaced_by_token_id`),
  CONSTRAINT `fk_platform_token_parent` FOREIGN KEY (`parent_token_id`) REFERENCES `pa_platform_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_token_replacement` FOREIGN KEY (`replaced_by_token_id`) REFERENCES `pa_platform_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_token_session` FOREIGN KEY (`session_id`) REFERENCES `pa_platform_session` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_platform_token_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'used',_utf8mb4'revoked',_utf8mb4'expired'))),
  CONSTRAINT `chk_platform_token_type` CHECK ((`token_type` in (_utf8mb4'access',_utf8mb4'refresh')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_role_permission' => <<<'SQL'
CREATE TABLE `pa_role_permission` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  `permission_id` bigint unsigned NOT NULL,
  `granted_by_member_id` bigint unsigned DEFAULT NULL,
  `granted_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_permission` (`tenant_id`,`role_id`,`permission_id`),
  KEY `fk_role_permission_permission` (`permission_id`),
  KEY `fk_role_permission_granter` (`tenant_id`,`granted_by_member_id`),
  CONSTRAINT `fk_role_permission_granter` FOREIGN KEY (`tenant_id`, `granted_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_role_permission_permission` FOREIGN KEY (`permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_role_permission_role` FOREIGN KEY (`tenant_id`, `role_id`) REFERENCES `pa_role` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_source_read_grant' => <<<'SQL'
CREATE TABLE `pa_source_read_grant` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_tenant_id` bigint unsigned NOT NULL,
  `recipient_tenant_id` bigint unsigned NOT NULL,
  `capability` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `effect` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `object_id` bigint unsigned DEFAULT NULL,
  `object_key` bigint unsigned GENERATED ALWAYS AS (coalesce(`object_id`,0)) STORED,
  `fields_json` json NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `valid_from` datetime(3) NOT NULL,
  `valid_until` datetime(3) DEFAULT NULL,
  `granted_by_member_id` bigint unsigned NOT NULL,
  `revoked_by_member_id` bigint unsigned DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source_read_grant_identity` (`source_tenant_id`,`recipient_tenant_id`,`capability`,`action`,`effect`,`object_key`),
  KEY `idx_source_read_grant_recipient` (`recipient_tenant_id`,`capability`,`action`,`status`,`source_tenant_id`),
  KEY `fk_source_read_grant_creator` (`source_tenant_id`,`granted_by_member_id`),
  KEY `fk_source_read_grant_revoker` (`source_tenant_id`,`revoked_by_member_id`),
  CONSTRAINT `fk_source_read_grant_creator` FOREIGN KEY (`source_tenant_id`, `granted_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_source_read_grant_recipient_tenant` FOREIGN KEY (`recipient_tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_source_read_grant_revoker` FOREIGN KEY (`source_tenant_id`, `revoked_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_source_read_grant_source_tenant` FOREIGN KEY (`source_tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_source_read_grant_action` CHECK (regexp_like(`action`,_utf8mb4'^[a-z][a-z0-9.-]{1,63}$')),
  CONSTRAINT `chk_source_read_grant_capability` CHECK (regexp_like(`capability`,_utf8mb4'^[a-z][a-z0-9.-]{2,159}$')),
  CONSTRAINT `chk_source_read_grant_effect` CHECK ((`effect` in (_utf8mb4'allow',_utf8mb4'deny'))),
  CONSTRAINT `chk_source_read_grant_fields` CHECK ((((`effect` = _utf8mb4'allow') and (json_length(`fields_json`) > 0)) or ((`effect` = _utf8mb4'deny') and (json_length(`fields_json`) = 0)))),
  CONSTRAINT `chk_source_read_grant_revoked` CHECK ((((`status` = _utf8mb4'revoked') and (`revoked_by_member_id` is not null) and (`revoked_at` is not null)) or ((`status` = _utf8mb4'active') and (`revoked_by_member_id` is null) and (`revoked_at` is null)))),
  CONSTRAINT `chk_source_read_grant_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'revoked'))),
  CONSTRAINT `chk_source_read_grant_tenants` CHECK ((`source_tenant_id` <> `recipient_tenant_id`)),
  CONSTRAINT `chk_source_read_grant_window` CHECK (((`valid_until` is null) or (`valid_until` > `valid_from`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_tenant_owner_invitation' => <<<'SQL'
CREATE TABLE `pa_tenant_owner_invitation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `email_normalized` varchar(255) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `delivery_status` varchar(32) NOT NULL DEFAULT 'pending_delivery',
  `delivery_provider` varchar(64) DEFAULT NULL,
  `delivery_message_id` varchar(190) DEFAULT NULL,
  `delivery_attempts` int unsigned NOT NULL DEFAULT '0',
  `delivery_error_code` varchar(96) DEFAULT NULL,
  `last_delivery_at` datetime(3) DEFAULT NULL,
  `generation` int unsigned NOT NULL DEFAULT '1',
  `expires_at` datetime(3) NOT NULL,
  `accepted_at` datetime(3) DEFAULT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  `accepted_account_id` bigint unsigned DEFAULT NULL,
  `accepted_member_id` bigint unsigned DEFAULT NULL,
  `invited_by_operator_id` bigint unsigned NOT NULL,
  `revoked_by_operator_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `pending_tenant_id` bigint unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'pending') then `tenant_id` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_invitation_token_hash` (`token_hash`),
  UNIQUE KEY `uk_owner_invitation_pending_tenant` (`pending_tenant_id`),
  KEY `idx_owner_invitation_tenant_time` (`tenant_id`,`created_at`,`id`),
  KEY `idx_owner_invitation_status_expiry` (`status`,`expires_at`,`id`),
  KEY `fk_owner_invitation_account` (`accepted_account_id`),
  KEY `fk_owner_invitation_member` (`tenant_id`,`accepted_member_id`),
  KEY `fk_owner_invitation_inviter` (`invited_by_operator_id`),
  KEY `fk_owner_invitation_revoker` (`revoked_by_operator_id`),
  CONSTRAINT `fk_owner_invitation_account` FOREIGN KEY (`accepted_account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_inviter` FOREIGN KEY (`invited_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_member` FOREIGN KEY (`tenant_id`, `accepted_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_revoker` FOREIGN KEY (`revoked_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_owner_invitation_accepted` CHECK ((((`status` = _utf8mb4'accepted') and (`accepted_at` is not null) and (`accepted_account_id` is not null) and (`accepted_member_id` is not null)) or ((`status` <> _utf8mb4'accepted') and (`accepted_at` is null) and (`accepted_account_id` is null) and (`accepted_member_id` is null)))),
  CONSTRAINT `chk_owner_invitation_delivery_status` CHECK ((`delivery_status` in (_utf8mb4'pending_delivery',_utf8mb4'sent',_utf8mb4'failed'))),
  CONSTRAINT `chk_owner_invitation_revoked` CHECK ((((`status` = _utf8mb4'revoked') and (`revoked_at` is not null) and (`revoked_by_operator_id` is not null)) or ((`status` <> _utf8mb4'revoked') and (`revoked_at` is null) and (`revoked_by_operator_id` is null)))),
  CONSTRAINT `chk_owner_invitation_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'accepted',_utf8mb4'revoked',_utf8mb4'expired')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_tenant_session' => <<<'SQL'
CREATE TABLE `pa_tenant_session` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_key` char(26) NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `tenant_member_id` bigint unsigned NOT NULL,
  `client_key` varchar(64) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `account_security_revision` bigint unsigned NOT NULL,
  `tenant_security_revision` bigint unsigned NOT NULL,
  `member_security_revision` bigint unsigned NOT NULL,
  `issued_at` datetime(3) NOT NULL,
  `last_seen_at` datetime(3) NOT NULL,
  `idle_expires_at` datetime(3) NOT NULL,
  `absolute_expires_at` datetime(3) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  `revoke_reason` varchar(64) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_session_key` (`session_key`),
  KEY `idx_tenant_session_member` (`tenant_id`,`tenant_member_id`,`status`,`absolute_expires_at`),
  KEY `idx_tenant_session_account` (`account_id`,`status`,`absolute_expires_at`),
  CONSTRAINT `fk_tenant_session_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_session_member` FOREIGN KEY (`tenant_id`, `tenant_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_session_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_session_client` CHECK (((char_length(`client_key`) between 1 and 64) and regexp_like(`client_key`,_utf8mb4'^[a-z]',_utf8mb4'c') and (not(regexp_like(`client_key`,_utf8mb4'[^a-z0-9-]',_utf8mb4'c'))))),
  CONSTRAINT `chk_tenant_session_expiry` CHECK ((`idle_expires_at` <= `absolute_expires_at`)),
  CONSTRAINT `chk_tenant_session_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'revoked',_utf8mb4'expired')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_data_permission_group' => <<<'SQL'
CREATE TABLE `pa_data_permission_group` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `data_permission_policy_id` bigint unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `match_mode` varchar(8) NOT NULL DEFAULT 'all',
  `sort_order` int NOT NULL DEFAULT '0',
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_group_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_data_group_name` (`tenant_id`,`data_permission_policy_id`,`name`),
  CONSTRAINT `fk_data_group_policy` FOREIGN KEY (`tenant_id`, `data_permission_policy_id`) REFERENCES `pa_data_permission_policy` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_group_match` CHECK ((`match_mode` = _utf8mb4'all')),
  CONSTRAINT `chk_data_group_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'disabled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_data_permission_target' => <<<'SQL'
CREATE TABLE `pa_data_permission_target` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `target_set_id` bigint unsigned NOT NULL,
  `target_id` varchar(128) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `added_by_member_id` bigint unsigned NOT NULL,
  `removed_by_member_id` bigint unsigned DEFAULT NULL,
  `added_at` datetime(3) NOT NULL,
  `removed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_target` (`tenant_id`,`target_set_id`,`target_id`),
  KEY `idx_data_target_active` (`tenant_id`,`target_set_id`,`status`,`target_id`),
  KEY `fk_data_target_adder` (`tenant_id`,`added_by_member_id`),
  KEY `fk_data_target_remover` (`tenant_id`,`removed_by_member_id`),
  CONSTRAINT `fk_data_target_adder` FOREIGN KEY (`tenant_id`, `added_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_remover` FOREIGN KEY (`tenant_id`, `removed_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_set` FOREIGN KEY (`tenant_id`, `target_set_id`) REFERENCES `pa_data_permission_target_set` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_target_removed` CHECK ((((`status` = _utf8mb4'removed') and (`removed_at` is not null) and (`removed_by_member_id` is not null)) or (`status` <> _utf8mb4'removed'))),
  CONSTRAINT `chk_data_target_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'removed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_tenant_session_token' => <<<'SQL'
CREATE TABLE `pa_tenant_session_token` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `token_type` varchar(16) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `parent_token_id` bigint unsigned DEFAULT NULL,
  `replaced_by_token_id` bigint unsigned DEFAULT NULL,
  `expires_at` datetime(3) NOT NULL,
  `used_at` datetime(3) DEFAULT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_session_token_hash` (`token_hash`),
  KEY `idx_tenant_session_token_active` (`session_id`,`token_type`,`status`,`expires_at`),
  KEY `fk_tenant_token_parent` (`parent_token_id`),
  KEY `fk_tenant_token_replacement` (`replaced_by_token_id`),
  CONSTRAINT `fk_tenant_token_parent` FOREIGN KEY (`parent_token_id`) REFERENCES `pa_tenant_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_token_replacement` FOREIGN KEY (`replaced_by_token_id`) REFERENCES `pa_tenant_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_token_session` FOREIGN KEY (`session_id`) REFERENCES `pa_tenant_session` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_token_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'used',_utf8mb4'revoked',_utf8mb4'expired'))),
  CONSTRAINT `chk_tenant_token_type` CHECK ((`token_type` in (_utf8mb4'access',_utf8mb4'refresh')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_data_permission_condition' => <<<'SQL'
CREATE TABLE `pa_data_permission_condition` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `data_permission_group_id` bigint unsigned NOT NULL,
  `condition_definition_id` bigint unsigned NOT NULL,
  `target_set_id` bigint unsigned DEFAULT NULL,
  `target_set_key` bigint unsigned GENERATED ALWAYS AS (coalesce(`target_set_id`,0)) STORED,
  `config_json` json DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_condition` (`tenant_id`,`data_permission_group_id`,`condition_definition_id`,`target_set_key`),
  KEY `fk_data_condition_definition` (`condition_definition_id`),
  KEY `fk_data_condition_target_set` (`tenant_id`,`target_set_id`),
  CONSTRAINT `fk_data_condition_definition` FOREIGN KEY (`condition_definition_id`) REFERENCES `pa_data_condition_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_condition_group` FOREIGN KEY (`tenant_id`, `data_permission_group_id`) REFERENCES `pa_data_permission_group` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_condition_target_set` FOREIGN KEY (`tenant_id`, `target_set_id`) REFERENCES `pa_data_permission_target_set` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_permission_condition_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'disabled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialIdentitySchema requires the native PDO adapter.');
        }
        $pdo = $adapter->getConnection();
        $pending = [];
        foreach (self::CREATE_SQL as $table => $definition) {
            if (!$adapter->hasTable($table)) {
                $pending[] = $definition;
                continue;
            }
            $statement = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
            $existing = $statement->fetch(PDO::FETCH_NUM);
            $statement->closeCursor();
            if (!is_array($existing) || !is_string($existing[1] ?? null)
                || $this->schemaDefinition($existing[1]) !== $this->schemaDefinition($definition)) {
                throw new RuntimeException('INITIAL_SCHEMA_MISMATCH: ' . $table);
            }
        }
        // Check existing definitions before creating missing tables in foreign-key order.
        foreach ($pending as $definition) {
            $this->execute($definition);
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Module data is retained. This initial schema migration cannot be rolled back.');
    }

    private function schemaDefinition(string $definition): string
    {
        // AUTO_INCREMENT is live row state rather than a schema difference.
        return (string) preg_replace('/ AUTO_INCREMENT=\d+(?= |$)/', '', rtrim(trim($definition), ';'));
    }
}
