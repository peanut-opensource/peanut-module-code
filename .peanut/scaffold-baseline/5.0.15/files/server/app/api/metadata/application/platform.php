<?php

declare(strict_types=1);

/**
 * Platform 宿主 API 合同。字段来自 Controller、Validate 与实际查询／应用服务投影；
 * 生命周期工具返回可扩展部分使用递归有类型值，不用无约束 object 伪装完整合同。
 */
$h = require __DIR__ . '/common.php';
extract($h, EXTR_SKIP);

$nullableString = ['type' => 'string', 'nullable' => true];
$nullableInteger = ['type' => 'integer', 'nullable' => true];
$dynamicMap = ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue')];
$nullableDynamicMap = ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'), 'nullable' => true];
$stringList = ['type' => 'array', 'items' => ['type' => 'string']];
$positiveId = ['type' => 'integer', 'minimum' => 1];
$dateTime = ['type' => 'string', 'format' => 'date-time'];

$schemas = [
    'PlatformAuthentication' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['state', 'access_token', 'token_type', 'expires_in', 'context'],
        'properties' => [
            'state' => ['type' => 'string', 'enum' => ['authenticated']],
            'access_token' => ['type' => 'string', 'minLength' => 1],
            'token_type' => ['type' => 'string', 'enum' => ['Bearer']],
            'expires_in' => ['type' => 'integer', 'minimum' => 1],
            'context' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['audience', 'account_id', 'platform_operator_id'],
                'properties' => [
                    'audience' => ['type' => 'string', 'enum' => ['platform']],
                    'account_id' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
                    'platform_operator_id' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
                ],
            ],
        ],
    ],
    'PlatformLoginRequest' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['email', 'password'],
        'properties' => [
            'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
            'password' => ['type' => 'string', 'minLength' => 1],
        ],
    ],
    'PlatformSessionInfo' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['audience', 'account_id', 'platform_operator_id', 'permissions', 'navigation'],
        'properties' => [
            'audience' => ['type' => 'string', 'enum' => ['platform']],
            'account_id' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
            'platform_operator_id' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
            'permissions' => $stringList,
            'navigation' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^/platform/']],
        ],
    ],
    'PlatformTenant' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['id', 'code', 'name', 'display_name', 'status', 'locale', 'timezone', 'security_revision', 'authorization_revision', 'revision', 'activated_at', 'suspended_at', 'closed_at', 'created_at', 'updated_at'],
        'properties' => [
            'id' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
            'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'display_name' => ['type' => 'string'],
            'status' => ['type' => 'string'], 'locale' => ['type' => 'string'], 'timezone' => ['type' => 'string'],
            'security_revision' => ['type' => 'string', 'pattern' => '^[0-9]+$'],
            'authorization_revision' => ['type' => 'string', 'pattern' => '^[0-9]+$'],
            'revision' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
            'activated_at' => $nullableString, 'suspended_at' => $nullableString, 'closed_at' => $nullableString,
            'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'],
        ],
    ],
    'PlatformTenantTransitionRequest' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['tenant_id', 'expected_revision', 'change_reason'],
        'properties' => [
            'tenant_id' => $positiveId, 'expected_revision' => $positiveId,
            'change_reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
        ],
    ],
    'PlatformCapabilities' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['audience', 'scope', 'permission_catalog', 'tenant_business_access', 'operations'],
        'properties' => [
            'audience' => ['type' => 'string', 'enum' => ['platform']],
            'scope' => ['type' => 'string', 'enum' => ['application-instance']],
            'permission_catalog' => ['type' => 'string', 'enum' => ['platform.*']],
            'tenant_business_access' => ['type' => 'boolean', 'enum' => [false]],
            'operations' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['platform.tenant.create', 'platform.tenant.lifecycle', 'platform.tenant.provision-owner', 'platform.tenant.license.manage']]],
        ],
    ],
    'PlatformOperator' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['id', 'account_id', 'display_name', 'status', 'security_revision', 'created_at', 'updated_at', 'email', 'role_keys'],
        'properties' => [
            'id' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
            'account_id' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
            'display_name' => ['type' => 'string'], 'status' => ['type' => 'string'],
            'security_revision' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
            'suspended_at' => $nullableString, 'closed_at' => $nullableString,
            'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'],
            'account_display_name' => ['type' => 'string'], 'account_status' => ['type' => 'string'],
            'email' => $nullableString, 'role_keys' => $stringList,
        ],
    ],
    'PlatformRole' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['id', 'key', 'name', 'description', 'is_builtin', 'status', 'revision', 'created_at', 'updated_at', 'permission_keys'],
        'properties' => [
            'id' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]], 'key' => ['type' => 'string'],
            'name' => ['type' => 'string'], 'description' => $nullableString,
            'is_builtin' => ['oneOf' => [['type' => 'integer'], ['type' => 'boolean']]], 'status' => ['type' => 'string'],
            'revision' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]], 'archived_at' => $nullableString,
            'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'],
            'permission_count' => ['type' => 'integer', 'minimum' => 0], 'permission_keys' => $stringList,
        ],
    ],
    'PlatformPermission' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['id', 'key', 'module_key', 'type', 'name', 'description', 'risk_level', 'status', 'manifest_version', 'created_at', 'updated_at', 'retired_at'],
        'properties' => [
            'id' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]], 'key' => ['type' => 'string'],
            'module_key' => ['type' => 'string'], 'type' => ['type' => 'string'], 'name' => ['type' => 'string'],
            'description' => $nullableString, 'risk_level' => ['type' => 'string'], 'status' => ['type' => 'string'],
            'manifest_version' => ['type' => 'string'], 'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'], 'retired_at' => $nullableString,
        ],
    ],
    'PlatformAuditEvent' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['id', 'event_type', 'action', 'outcome', 'reason_code', 'operator_id', 'account_id', 'target_type', 'target_id', 'request_id', 'operation_id', 'ip_address', 'user_agent_hash', 'before_json', 'after_json', 'metadata_json', 'occurred_at'],
        'properties' => [
            'id' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]], 'event_type' => ['type' => 'string'],
            'action' => ['type' => 'string'], 'outcome' => ['type' => 'string'], 'reason_code' => $nullableString,
            'operator_id' => $nullableInteger, 'account_id' => $nullableInteger, 'target_type' => $nullableString,
            'target_id' => $nullableString, 'request_id' => ['type' => 'string'], 'operation_id' => $nullableString,
            'ip_address' => $nullableString, 'user_agent_hash' => $nullableString,
            'before_json' => $nullableDynamicMap,
            'after_json' => $nullableDynamicMap,
            'metadata_json' => $nullableDynamicMap, 'occurred_at' => ['type' => 'string'],
        ],
    ],
    'PlatformTenantModuleState' => [
        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['id', 'tenant_id', 'module_key', 'status', 'source', 'config_revision', 'effective_at', 'expires_at', 'enabled_at', 'disabled_at', 'disabled_reason', 'created_at', 'updated_at', 'installed_version', 'installation_status'],
        'properties' => [
            'id' => $nullableInteger, 'tenant_id' => $positiveId, 'module_key' => ['type' => 'string'],
            'status' => ['type' => 'string'], 'source' => ['type' => 'string'], 'config_revision' => ['type' => 'integer', 'minimum' => 0],
            'effective_at' => $nullableString, 'expires_at' => $nullableString, 'enabled_at' => $nullableString,
            'disabled_at' => $nullableString, 'disabled_reason' => $nullableString, 'created_at' => $nullableString,
            'updated_at' => $nullableString, 'installed_version' => ['type' => 'string'], 'installation_status' => ['type' => 'string'],
        ],
    ],
    'PlatformTenantOwner' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['member_id', 'tenant_id', 'account_id', 'display_name', 'member_status', 'security_revision', 'authorization_revision', 'joined_at', 'created_at', 'updated_at', 'account_display_name', 'account_status', 'email', 'role_id', 'role_key'],
        'properties' => [
            'member_id' => $positiveId, 'tenant_id' => $positiveId, 'account_id' => $positiveId,
            'display_name' => ['type' => 'string'], 'member_status' => ['type' => 'string'],
            'security_revision' => ['type' => 'integer'], 'authorization_revision' => ['type' => 'integer'],
            'joined_at' => $nullableString, 'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'],
            'account_display_name' => ['type' => 'string'], 'account_status' => ['type' => 'string'], 'email' => $nullableString,
            'role_id' => $positiveId, 'role_key' => ['type' => 'string', 'enum' => ['core.tenant-owner']],
        ],
    ],
    'PlatformEntryBinding' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['id', 'tenant_id', 'tenant_code', 'tenant_name', 'host', 'client_key', 'status'],
        'properties' => [
            'id' => $positiveId, 'tenant_id' => $positiveId, 'tenant_code' => ['type' => 'string'], 'tenant_name' => ['type' => 'string'],
            'host' => ['type' => 'string', 'maxLength' => 253], 'client_key' => ['type' => 'string', 'enum' => ['admin-web', 'member-api']],
            'status' => ['type' => 'string'], 'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'],
        ],
    ],
    'PlatformEntryBindingDisabled' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'tenant_id', 'status'],
        'properties' => ['id' => $positiveId, 'tenant_id' => $positiveId, 'status' => ['type' => 'string', 'enum' => ['disabled']]],
    ],
    'PlatformOwnerInvitation' => [
        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['id', 'tenant_id', 'email', 'display_name', 'status', 'delivery_status', 'generation', 'expires_at'],
        'properties' => [
            'id' => $positiveId, 'tenant_id' => $positiveId, 'email' => ['type' => 'string', 'format' => 'email'],
            'display_name' => ['type' => 'string'], 'status' => ['type' => 'string'], 'delivery_status' => ['type' => 'string'],
            'delivery_provider' => $nullableString, 'delivery_attempts' => ['type' => 'integer', 'minimum' => 0],
            'delivery_error_code' => $nullableString, 'generation' => ['type' => 'integer', 'minimum' => 1],
            'expires_at' => ['type' => 'string'], 'accepted_at' => $nullableString, 'revoked_at' => $nullableString,
            'accepted_account_id' => $nullableInteger, 'accepted_member_id' => $nullableInteger,
            'invited_by_operator_id' => $nullableInteger, 'revoked_by_operator_id' => $nullableInteger,
            'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'],
            'tenant_code' => ['type' => 'string'], 'tenant_name' => ['type' => 'string'], 'tenant_status' => ['type' => 'string'],
            'accept_token' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{43}$'],
        ],
    ],
    'PlatformInvitationRevoked' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'tenant_id', 'status'],
        'properties' => ['id' => $positiveId, 'tenant_id' => $positiveId, 'status' => ['type' => 'string', 'enum' => ['revoked']]],
    ],
    'PlatformModuleDescriptor' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['module_key', 'name', 'version', 'manifest_digest', 'package_key', 'package_version', 'dependencies', 'package_modules', 'lifecycle_protected', 'status', 'tenant_enabled_count', 'blockers', 'dependents', 'tenant'],
        'properties' => [
            'module_key' => ['type' => 'string'], 'name' => ['type' => 'string'], 'version' => ['type' => 'string'],
            'manifest_digest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], 'package_key' => ['type' => 'string'],
            'package_version' => ['type' => 'string'],
            'dependencies' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['module_key', 'version'], 'properties' => ['module_key' => ['type' => 'string'], 'version' => ['type' => 'string']]]],
            'package_modules' => $stringList, 'lifecycle_protected' => ['type' => 'boolean'], 'status' => ['type' => 'string'],
            'tenant_enabled_count' => ['type' => 'integer', 'minimum' => 0], 'blockers' => $stringList, 'dependents' => $stringList,
            'tenant' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['enableable','required','requires'], 'properties' => ['enableable' => ['type' => 'boolean'], 'required' => ['type' => 'boolean'], 'requires' => $stringList]],
        ],
    ],
    'PlatformModuleLifecycleResult' => [
        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['operation'],
        'properties' => [
            'operation' => ['type' => 'string'], 'package_key' => ['type' => 'string'], 'status' => ['type' => 'string'],
            'catalog_revision' => ['type' => 'string'], 'affected_modules' => ['type' => 'array', 'items' => $ref('ApplicationDynamicValue')],
            'modules' => ['type' => 'array', 'items' => $ref('ApplicationDynamicValue')],
            'plan' => $dynamicMap, 'plan_digest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
        ],
    ],
    'PlatformDeveloperCatalog' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['schema_version', 'generated_at', 'read_only', 'sources', 'status', 'summary', 'modules'],
        'properties' => [
            'schema_version' => ['type' => 'integer', 'enum' => [1]], 'generated_at' => $dateTime,
            'read_only' => ['type' => 'boolean', 'enum' => [true]], 'sources' => $dynamicMap,
            'status' => $dynamicMap,
            'summary' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['modules', 'discovered', 'registered', 'routes', 'generated_api_operations', 'complete_api_operations', 'partial_api_operations', 'undocumented_routes'],
                'properties' => array_fill_keys(['modules', 'discovered', 'registered', 'routes', 'generated_api_operations', 'complete_api_operations', 'partial_api_operations', 'undocumented_routes'], ['type' => 'integer', 'minimum' => 0]),
            ],
            'modules' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'), 'required' => ['key', 'name', 'description', 'source', 'evidence', 'routes', 'generated_api'], 'properties' => ['key' => ['type' => 'string'], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'version' => $nullableString, 'source' => $dynamicMap, 'evidence' => $dynamicMap, 'routes' => ['type' => 'array', 'items' => $dynamicMap], 'generated_api' => ['type' => 'array', 'items' => $dynamicMap]]]],
        ],
    ],
    'PlatformOpsMaintenance' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['maintenance_key', 'state', 'reason_key', 'starts_at', 'ends_at', 'revision'],
        'properties' => ['maintenance_key' => ['type' => 'string', 'pattern' => '^maintenance_'], 'state' => ['type' => 'string', 'enum' => ['scheduled', 'active', 'closed']], 'reason_key' => ['type' => 'string'], 'starts_at' => $dateTime, 'ends_at' => $dateTime, 'revision' => ['type' => 'integer', 'minimum' => 1]],
    ],
    'NullablePlatformOpsMaintenance' => [
        'type' => 'object', 'nullable' => true, 'additionalProperties' => false,
        'required' => ['maintenance_key', 'state', 'reason_key', 'starts_at', 'ends_at', 'revision'],
        'properties' => ['maintenance_key' => ['type' => 'string', 'pattern' => '^maintenance_'], 'state' => ['type' => 'string', 'enum' => ['scheduled', 'active', 'closed']], 'reason_key' => ['type' => 'string'], 'starts_at' => $dateTime, 'ends_at' => $dateTime, 'revision' => ['type' => 'integer', 'minimum' => 1]],
    ],
    'PlatformOpsTask' => [
        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['task_key', 'task_type', 'status', 'attempt_count', 'revision', 'last_error_code', 'created_at', 'updated_at', 'completed_at'],
        'properties' => [
            'task_key' => ['type' => 'string', 'pattern' => '^job_[a-f0-9]{32}$'], 'task_type' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'succeeded', 'dead', 'cancelled']],
            'attempt_count' => ['type' => 'integer', 'minimum' => 0], 'max_attempts' => ['type' => 'integer', 'minimum' => 1],
            'revision' => ['type' => 'integer', 'minimum' => 1], 'last_error_code' => $nullableString,
            'request_key' => ['type' => 'string'], 'provider_key' => ['type' => 'string'], 'target_key' => ['type' => 'string'],
            'backup_reference_key' => $nullableString, 'available_at' => $dateTime,
            'created_at' => $dateTime, 'updated_at' => $dateTime, 'completed_at' => $nullableString,
            'current_step' => ['type' => 'string'],
            'recovery_pointer' => $nullableDynamicMap,
        ],
    ],
    'PlatformOpsStatus' => [
        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['health', 'version', 'migrations', 'upgrade'],
        'properties' => [
            'health' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['status', 'checks'],
                'properties' => [
                    'status' => ['type' => 'string'],
                    'checks' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['key', 'status', 'critical', 'latency_ms'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['up', 'down']],
                            'critical' => ['type' => 'boolean'],
                            'latency_ms' => ['type' => 'number', 'minimum' => 0],
                        ],
                    ]],
                ],
            ],
            'version' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['commit', 'tree', 'release_key', 'built_at'], 'properties' => ['commit' => $nullableString, 'tree' => $nullableString, 'release_key' => $nullableString, 'built_at' => $dateTime]],
            'migrations' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['applied', 'target', 'pending', 'inventory_digest', 'drift'], 'properties' => ['applied' => ['type' => 'integer'], 'target' => ['type' => 'integer'], 'pending' => ['type' => 'integer', 'minimum' => 0], 'inventory_digest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], 'drift' => ['type' => 'boolean']]],
            'upgrade' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['state', 'code', 'source_commit', 'target_commit', 'repository_clean', 'backup_verified', 'source_evidence_matches'], 'properties' => ['state' => ['type' => 'string'], 'code' => ['type' => 'string'], 'source_commit' => $nullableString, 'target_commit' => $nullableString, 'repository_clean' => ['type' => 'boolean', 'nullable' => true], 'backup_verified' => ['type' => 'boolean'], 'source_evidence_matches' => ['type' => 'boolean']]],
        ],
    ],
    'PlatformOpsLogPage' => [
        'type' => 'object', 'additionalProperties' => false,
        'description' => '最近 24 小时的平台审计脱敏摘要；最多读取 100 个聚合组后按最低级别过滤，不提供完整搜索或游标分页。',
        'required' => ['items', 'next_cursor'],
        'properties' => [
            'items' => ['type' => 'array', 'maxItems' => 100, 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['event_key', 'severity', 'component_key', 'message', 'occurred_at', 'request_id', 'occurrences'],
                'properties' => [
                    'event_key' => ['type' => 'string'],
                    'severity' => ['type' => 'string', 'enum' => ['info', 'warning', 'error', 'critical']],
                    'component_key' => ['type' => 'string', 'enum' => ['platform.audit']],
                    'message' => ['type' => 'string', 'description' => '受控消息目录中的通用说明，不含原始日志消息、身份或载荷。'],
                    'occurred_at' => $dateTime,
                    'request_id' => $nullableString,
                    'occurrences' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
                ],
            ]],
            'next_cursor' => ['type' => 'string', 'nullable' => true, 'description' => '下一页游标；null 表示有界摘要已读完。'],
        ],
    ],
    'PlatformOpsSnapshot' => [
        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
        'minProperties' => 1,
        'properties' => [
            'provider' => $dynamicMap, 'latest_verified' => $nullableDynamicMap,
            'latest_restore_verified' => $nullableDynamicMap,
            'backup_contract_status' => ['type' => 'string', 'enum' => ['missing', 'current', 'unsupported_schema']],
            'restore_contract_status' => ['type' => 'string', 'enum' => ['missing', 'current', 'unsupported_schema']],
            'new_paired_backup_required' => ['type' => 'boolean'],
            'source_identity_known' => ['type' => 'boolean'],
            'page' => ['type' => 'integer', 'minimum' => 1], 'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'total' => ['type' => 'integer', 'minimum' => 0],
            'tasks' => ['type' => 'array', 'items' => $ref('PlatformOpsTask')],
            'items' => ['type' => 'array', 'items' => $ref('ApplicationDynamicValue')],
            'status' => ['type' => 'string'], 'ready' => ['type' => 'boolean'],
        ],
    ],
];

$pageData = static fn(string $item): array => [
    'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize'],
    'properties' => ['lists' => ['type' => 'array', 'items' => $ref($item)], 'count' => ['type' => 'integer', 'minimum' => 0], 'pageNo' => ['type' => 'integer', 'minimum' => 1], 'pageSize' => ['type' => 'integer', 'minimum' => 1]],
];
$responses = static fn(string $schema): array => ['200' => $success($ref($schema)), '401' => $error, '403' => $error, '422' => $error];
$pageResponses = static fn(string $schema): array => ['200' => $success($pageData($schema)), '401' => $error, '403' => $error, '422' => $error];
$pageParams = [$parameterRef('PlatformPage'), $parameterRef('PlatformPageSize')];
$paths = [];
$add = static function (array &$paths, string $method, string $pathName, array $contract): void {
    $paths[$pathName][strtolower($method)] = $contract;
};

// Platform 会话和只读控制面。
$add($paths, 'POST', '/platformapi/session/login', $operation(
    'platformSessionLogin',
    'PlatformSession',
    ['200' => $success($ref('PlatformAuthentication')), '401' => $error, '403' => $error, '422' => $error],
    requestBody: $jsonBody($ref('PlatformLoginRequest')),
    errors: ['PLATFORM_AUTHENTICATION_REJECTED'],
));
$add($paths, 'POST', '/platformapi/session/refresh', $operation(
    'platformSessionRefresh',
    'PlatformSession',
    ['200' => $success($ref('PlatformAuthentication')), '401' => $error, '403' => $error],
    errors: ['PLATFORM_REFRESH_CREDENTIAL_INVALID'],
));
$add($paths, 'POST', '/platformapi/session/logout', $operation(
    'platformSessionLogout',
    'PlatformSession',
    ['200' => $emptySuccess, '401' => $error, '403' => $error],
));
$add($paths, 'GET', '/platformapi/session/info', $operation('getPlatformSessionInfo', 'PlatformSession', $responses('PlatformSessionInfo')));
$add($paths, 'GET', '/platformapi/tenants/capabilities', $operation('getPlatformTenantCapabilities', 'PlatformTenants', $responses('PlatformCapabilities')));

$add($paths, 'GET', '/platformapi/tenants', $operation('listPlatformTenants', 'PlatformTenants', $pageResponses('PlatformTenant'), $pageParams));
$add($paths, 'GET', '/platformapi/tenants/detail', $operation(
    'getPlatformTenant',
    'PlatformTenants',
    $responses('PlatformTenant'),
    [$query('id', $positiveId, true)],
));
$tenantTransitionBody = $jsonBody($ref('PlatformTenantTransitionRequest'));
foreach ([
    'activate' => ['activatePlatformTenant', 'TENANT_ACTIVATION_REJECTED'],
    'suspend' => ['suspendPlatformTenant', 'TENANT_SUSPENSION_REJECTED'],
    'close' => ['closePlatformTenant', 'TENANT_CLOSURE_REJECTED'],
] as $route => [$operationId, $errorCode]) {
    $add($paths, 'POST', '/platformapi/tenants/' . $route, $operation(
        $operationId,
        'PlatformTenants',
        $responses('PlatformTenant'),
        requestBody: $tenantTransitionBody,
        errors: [$errorCode],
    ));
}

$add($paths, 'GET', '/platformapi/operators', $operation('listPlatformOperators', 'PlatformAccess', $pageResponses('PlatformOperator'), $pageParams));
$add($paths, 'GET', '/platformapi/roles', $operation('listPlatformRoles', 'PlatformAccess', $pageResponses('PlatformRole'), $pageParams));
$add($paths, 'GET', '/platformapi/permissions', $operation('listPlatformPermissions', 'PlatformAccess', $pageResponses('PlatformPermission'), $pageParams));
$add($paths, 'GET', '/platformapi/audit', $operation('listPlatformAuditEvents', 'PlatformAudit', $pageResponses('PlatformAuditEvent'), $pageParams));
$add($paths, 'GET', '/platformapi/tenants/modules', $operation(
    'listPlatformTenantModules',
    'PlatformTenants',
    $pageResponses('PlatformTenantModuleState'),
    [...$pageParams, $query('tenant_id', $positiveId, true)],
));
$add($paths, 'GET', '/platformapi/tenants/owner', $operation(
    'getPlatformTenantOwner',
    'PlatformTenants',
    $responses('PlatformTenantOwner'),
    [$query('tenant_id', $positiveId, true)],
));

// Platform operator 与角色写入；请求字段严格对应 PlatformAccessValidate scenes。
$operatorRequests = [
    'create' => [
        'operationId' => 'createPlatformOperator', 'schema' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['email', 'display_name'],
            'properties' => ['email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255], 'display_name' => ['type' => 'string', 'maxLength' => 120], 'initial_password' => ['type' => 'string', 'description' => 'Effective UTF-8 byte bounds from getPasswordPolicy.']],
        ],
    ],
    'update' => [
        'operationId' => 'updatePlatformOperator', 'schema' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['operator_id', 'expected_revision', 'display_name', 'change_reason'],
            'properties' => ['operator_id' => $positiveId, 'expected_revision' => $positiveId, 'display_name' => ['type' => 'string', 'maxLength' => 120], 'change_reason' => ['type' => 'string', 'maxLength' => 500]],
        ],
    ],
    'roles/replace' => [
        'operationId' => 'replacePlatformOperatorRoles', 'schema' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['operator_id', 'role_ids', 'expected_revision', 'change_reason'],
            'properties' => ['operator_id' => $positiveId, 'role_ids' => ['type' => 'array', 'maxItems' => 100, 'items' => $positiveId], 'expected_revision' => $positiveId, 'change_reason' => ['type' => 'string', 'maxLength' => 500]],
        ],
    ],
];
foreach ($operatorRequests as $route => $definition) {
    $add($paths, 'POST', '/platformapi/operators/' . $route, $operation(
        $definition['operationId'],
        'PlatformAccess',
        $responses('PlatformOperator'),
        requestBody: $jsonBody($definition['schema']),
    ));
}
$operatorTransition = [
    'type' => 'object', 'additionalProperties' => false, 'required' => ['operator_id', 'expected_revision', 'change_reason'],
    'properties' => ['operator_id' => $positiveId, 'expected_revision' => $positiveId, 'change_reason' => ['type' => 'string', 'maxLength' => 500]],
];
foreach (['activate', 'suspend', 'close'] as $route) {
    $add($paths, 'POST', '/platformapi/operators/' . $route, $operation(
        $route . 'PlatformOperator',
        'PlatformAccess',
        $responses('PlatformOperator'),
        requestBody: $jsonBody($operatorTransition),
    ));
}
$roleRequests = [
    'create' => [
        'operationId' => 'createPlatformRole', 'schema' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'name'],
            'properties' => ['key' => ['type' => 'string', 'pattern' => '^platform(?:\.[a-z][a-z0-9-]*)+$', 'maxLength' => 96], 'name' => ['type' => 'string', 'maxLength' => 120], 'description' => ['type' => 'string', 'maxLength' => 500]],
        ],
    ],
    'update' => [
        'operationId' => 'updatePlatformRole', 'schema' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['role_id', 'expected_revision', 'name', 'change_reason'],
            'properties' => ['role_id' => $positiveId, 'expected_revision' => $positiveId, 'name' => ['type' => 'string', 'maxLength' => 120], 'description' => ['type' => 'string', 'maxLength' => 500], 'change_reason' => ['type' => 'string', 'maxLength' => 500]],
        ],
    ],
    'archive' => [
        'operationId' => 'archivePlatformRole', 'schema' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['role_id', 'expected_revision', 'change_reason'],
            'properties' => ['role_id' => $positiveId, 'expected_revision' => $positiveId, 'change_reason' => ['type' => 'string', 'maxLength' => 500]],
        ],
    ],
    'permissions/replace' => [
        'operationId' => 'replacePlatformRolePermissions', 'schema' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['role_id', 'permission_keys', 'expected_revision', 'change_reason'],
            'properties' => ['role_id' => $positiveId, 'permission_keys' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string']], 'expected_revision' => $positiveId, 'change_reason' => ['type' => 'string', 'maxLength' => 500]],
        ],
    ],
];
foreach ($roleRequests as $route => $definition) {
    $add($paths, 'POST', '/platformapi/roles/' . $route, $operation(
        $definition['operationId'],
        'PlatformAccess',
        $responses('PlatformRole'),
        requestBody: $jsonBody($definition['schema']),
    ));
}

// Tenant owner 邀请、入口域名与 TenantModule。
$invitationIssue = static fn(bool $withTenant): array => [
    'type' => 'object', 'additionalProperties' => false,
    'required' => $withTenant ? ['tenant_id', 'owner_email', 'owner_display_name'] : ['tenant_code', 'tenant_name', 'owner_email', 'owner_display_name'],
    'properties' => ($withTenant ? ['tenant_id' => $positiveId] : ['tenant_code' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$', 'maxLength' => 64], 'tenant_name' => ['type' => 'string', 'maxLength' => 160]]) + [
        'owner_email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
        'owner_display_name' => ['type' => 'string', 'maxLength' => 120],
        'expires_in_hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 720, 'default' => 72],
    ],
];
$add($paths, 'POST', '/platformapi/tenants/provision', $operation('provisionPlatformTenant', 'PlatformTenantInvitations', $responses('PlatformOwnerInvitation'), requestBody: $jsonBody($invitationIssue(false))));
$add($paths, 'POST', '/platformapi/tenants/invitations', $operation('invitePlatformTenantOwner', 'PlatformTenantInvitations', $responses('PlatformOwnerInvitation'), requestBody: $jsonBody($invitationIssue(true))));
$add($paths, 'GET', '/platformapi/tenants/invitations', $operation('listPlatformTenantInvitations', 'PlatformTenantInvitations', $pageResponses('PlatformOwnerInvitation'), [...$pageParams, $query('tenant_id', $positiveId, true)]));
$add($paths, 'POST', '/platformapi/tenants/invitations/resend', $operation(
    'resendPlatformTenantInvitation',
    'PlatformTenantInvitations',
    $responses('PlatformOwnerInvitation'),
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['invitation_id'], 'properties' => ['invitation_id' => $positiveId, 'expires_in_hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 720, 'default' => 72]]]),
));
$add($paths, 'POST', '/platformapi/tenants/invitations/revoke', $operation(
    'revokePlatformTenantInvitation',
    'PlatformTenantInvitations',
    $responses('PlatformInvitationRevoked'),
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['invitation_id'], 'properties' => ['invitation_id' => $positiveId]]),
));

$add($paths, 'GET', '/platformapi/tenant-entry-bindings', $operation(
    'listPlatformTenantEntryBindings',
    'PlatformTenantBindings',
    ['200' => $success(['type' => 'array', 'items' => $ref('PlatformEntryBinding')]), '401' => $error, '403' => $error, '422' => $error],
    [$query('tenant_id', $positiveId)],
));
$add($paths, 'POST', '/platformapi/tenant-entry-bindings/enable', $operation(
    'enablePlatformTenantEntryBinding',
    'PlatformTenantBindings',
    $responses('PlatformEntryBinding'),
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['tenant_id', 'host', 'client_key', 'change_reason'], 'properties' => ['tenant_id' => $positiveId, 'host' => ['type' => 'string', 'maxLength' => 253], 'client_key' => ['type' => 'string', 'enum' => ['admin-web', 'member-api']], 'change_reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500]]]),
    errors: ['TENANT_ENTRY_CLIENT_INVALID', 'TENANT_ENTRY_INPUT_INVALID', 'TENANT_ENTRY_TENANT_UNAVAILABLE', 'TENANT_ENTRY_BINDING_CONFLICT'],
));
$add($paths, 'POST', '/platformapi/tenant-entry-bindings/disable', $operation(
    'disablePlatformTenantEntryBinding',
    'PlatformTenantBindings',
    $responses('PlatformEntryBindingDisabled'),
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['binding_id', 'change_reason'], 'properties' => ['binding_id' => $positiveId, 'change_reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500]]]),
    errors: ['TENANT_ENTRY_INPUT_INVALID', 'TENANT_ENTRY_BINDING_NOT_FOUND'],
));

// 实例级 Module 工具；安装是 multipart tar，不是 JSON。
$add($paths, 'GET', '/platformapi/instance-tools/modules', $operation(
    'listInstanceModules',
    'PlatformModules',
    $pageResponses('PlatformModuleDescriptor'),
    [...$pageParams, $query('module_key', ['type' => 'string', 'maxLength' => 96])],
));
$add($paths, 'POST', '/platformapi/instance-tools/modules/install', $operation(
    'installInstanceModule',
    'PlatformModules',
    $responses('PlatformModuleLifecycleResult'),
    requestBody: $multipartBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['package', 'expected_sha256'], 'properties' => ['package' => ['type' => 'string', 'format' => 'binary'], 'expected_sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], 'signature_key_id' => ['type' => 'string']]]),
    errors: ['MODULE_PACKAGE_REQUEST_INVALID'],
));
$add($paths, 'POST', '/platformapi/instance-tools/modules/update', $operation(
    'updateInstanceModule',
    'PlatformModules',
    $responses('PlatformOpsTask'),
    [$header('Idempotency-Key', ['type' => 'string', 'minLength' => 8, 'maxLength' => 200, 'pattern' => '^[!-~]+$'], true)],
    requestBody: $multipartBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['package', 'expected_sha256'], 'properties' => ['package' => ['type' => 'string', 'format' => 'binary'], 'expected_sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], 'signature_key_id' => ['type' => 'string']]]),
    errors: ['MODULE_PACKAGE_REQUEST_INVALID', 'OPS_MODULE_REQUEST_INVALID'],
));
$add($paths, 'GET', '/platformapi/instance-tools/modules/tasks/{task_key}', $operation(
    'getInstanceModuleTask',
    'PlatformModules',
    $responses('PlatformOpsTask'),
    [$path('task_key', ['type' => 'string', 'pattern' => '^job_[a-f0-9]{32}$'])],
    errors: ['OPS_TASK_NOT_FOUND'],
));
$add($paths, 'POST', '/platformapi/instance-tools/modules/create', $operation(
    'createInstanceModule',
    'PlatformModules',
    $responses('PlatformModuleLifecycleResult'),
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['module_key'], 'properties' => ['module_key' => ['type' => 'string', 'maxLength' => 96], 'vendor' => ['type' => 'string'], 'client' => ['type' => 'string', 'enum' => ['none', 'admin-web'], 'default' => 'none']]]),
    errors: ['MODULE_CREATE_REQUEST_INVALID', 'MODULE_CREATE_CLIENT_INVALID'],
));
$add($paths, 'POST', '/platformapi/instance-tools/modules/disable', $operation(
    'disableInstanceModule',
    'PlatformModules',
    $responses('PlatformModuleLifecycleResult'),
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['module_key', 'change_reason'], 'properties' => ['module_key' => ['type' => 'string', 'maxLength' => 96], 'change_reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 500]]]),
));
$add($paths, 'POST', '/platformapi/instance-tools/modules/sync', $operation(
    'syncInstanceModuleCatalog',
    'PlatformModules',
    $responses('PlatformModuleLifecycleResult'),
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'properties' => ['module_key' => ['type' => 'string', 'maxLength' => 96]]]),
));
$add($paths, 'POST', '/platformapi/instance-tools/modules/uninstall', $operation(
    'uninstallInstanceModule',
    'PlatformModules',
    $responses('PlatformModuleLifecycleResult'),
    [$header('Idempotency-Key', ['type' => 'string', 'minLength' => 8, 'maxLength' => 200, 'pattern' => '^[!-~]+$'], false)],
    requestBody: $jsonBody([
        'oneOf' => [
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['module_key', 'purge', 'preview'], 'properties' => ['module_key' => ['type' => 'string', 'maxLength' => 96], 'purge' => ['type' => 'boolean'], 'preview' => ['type' => 'boolean', 'enum' => [true]]]],
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['module_key', 'purge', 'preview', 'change_reason', 'confirm_plan', 'confirm_plan_digest', 'confirm_package_key'], 'properties' => ['module_key' => ['type' => 'string', 'maxLength' => 96], 'purge' => ['type' => 'boolean'], 'preview' => ['type' => 'boolean', 'enum' => [false]], 'change_reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 500], 'confirm_plan' => $dynamicMap, 'confirm_plan_digest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], 'confirm_package_key' => ['type' => 'string']]],
        ],
    ]),
    errors: ['MODULE_UNINSTALL_PLAN_CHANGED', 'MODULE_UNINSTALL_REQUEST_INVALID'],
    description: 'preview=true 返回确认计划；preview=false 必须提供 Idempotency-Key，提交 Ops 模块卸载任务并返回任务状态。',
));
$add($paths, 'GET', '/platformapi/developer-center/catalog', $operation(
    'getPlatformDeveloperCatalog',
    'DeveloperCenter',
    $responses('PlatformDeveloperCatalog'),
    [$query('module_key', ['type' => 'string', 'maxLength' => 96])],
));

// Ops V1 的 root 合同由本文件完全替换：成功仍为 HTTP 200/business code envelope。
$opsOk = static fn(string $schema): array => ['200' => $success($ref($schema)), '401' => $error, '403' => $error, '409' => $error, '422' => $error];
$overrides = [];
$setOverride = static function (array &$overrides, string $method, string $pathName, array $contract): void {
    $overrides[$pathName][strtolower($method)] = $contract;
};
$add($paths, 'GET', '/platformapi/v1/ops/status', $operation('getPlatformOpsStatus', 'PlatformOps', $opsOk('PlatformOpsStatus')));
$add($paths, 'GET', '/platformapi/v1/ops/logs', $operation(
    'listPlatformOpsLogs',
    'PlatformOps',
    ['200' => $success($ref('PlatformOpsLogPage')), '400' => $error, '401' => $error, '403' => $error, '503' => $error],
    [
        $query('source', ['type' => 'string', 'enum' => ['platform.audit'], 'default' => 'platform.audit']),
        $query('severity', ['type' => 'string', 'enum' => ['info', 'warning', 'error', 'critical'], 'default' => 'info']),
        $query('page_size', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]),
        $query('cursor', ['type' => 'string', 'maxLength' => 128, 'description' => '使用响应的 next_cursor；摘要变化后游标失效，需重新从第一页读取。']),
    ],
    errors: ['OPS_REQUEST_INVALID', 'OPS_LOGS_UNAVAILABLE'],
));
$add($paths, 'GET', '/platformapi/v1/ops/diagnostics', $operation(
    'downloadPlatformDiagnostics',
    'PlatformOps',
    [
        '200' => [
            'description' => '有界诊断 JSON 附件',
            'headers' => [
                'Content-Disposition' => ['schema' => ['type' => 'string']],
                'X-Diagnostic-SHA256' => ['required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']],
                'X-Request-Id' => ['required' => true, 'schema' => ['type' => 'string']],
            ],
            'content' => ['application/json' => ['schema' => $dynamicMap]],
        ],
        '401' => $error, '403' => $error, '422' => $error,
    ],
    [$query('window_minutes', ['type' => 'integer', 'enum' => [60, 360, 1440], 'default' => 60])],
    errors: ['OPS_DIAGNOSTIC_WINDOW_INVALID'],
));

foreach ([
    '/platformapi/v1/ops/upgrade-readiness' => ['getPlatformUpgradeReadiness', 'PlatformOpsSnapshot'],
    '/platformapi/v1/ops/providers' => ['getPlatformOpsProviders', 'PlatformOpsSnapshot'],
    '/platformapi/v1/ops/maintenance' => ['getPlatformMaintenance', 'NullablePlatformOpsMaintenance'],
    '/platformapi/v1/ops/modules' => ['listPlatformModuleOperations', 'PlatformOpsSnapshot'],
    '/platformapi/v1/ops/upgrades' => ['listPlatformUpgrades', 'PlatformOpsSnapshot'],
    '/platformapi/v1/ops/backups' => ['listPlatformBackups', 'PlatformOpsSnapshot'],
] as $pathName => [$operationId, $schema]) {
    $pagination = in_array($pathName, ['/platformapi/v1/ops/providers', '/platformapi/v1/ops/backups'], true)
        ? [$query('page', ['type' => 'integer', 'minimum' => 1, 'maximum' => 999999, 'default' => 1]),
            $query('page_size', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20])] : [];
    $responses = $opsOk($schema);
    if ($pagination !== []) { $responses['400'] = $error; }
    $setOverride($overrides, 'GET', $pathName, $operation($operationId, 'PlatformOps', $responses, $pagination));
}
$setOverride($overrides, 'GET', '/platformapi/v1/ops/tasks/{task_key}', $operation(
    'getPlatformOpsTask',
    'PlatformOps',
    $opsOk('PlatformOpsTask'),
    [$path('task_key', ['type' => 'string', 'minLength' => 1])],
));
$setOverride($overrides, 'PUT', '/platformapi/v1/ops/maintenance', $operation(
    'schedulePlatformMaintenance',
    'PlatformOps',
    $opsOk('PlatformOpsMaintenance'),
    [$parameterRef('IfMatchRevision'), $parameterRef('IdempotencyKey')],
    $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['reason_key', 'starts_at', 'ends_at'], 'properties' => ['reason_key' => ['type' => 'string'], 'starts_at' => $dateTime, 'ends_at' => $dateTime]]),
));
$setOverride($overrides, 'POST', '/platformapi/v1/ops/maintenance/{maintenance_key}/close', $operation(
    'closePlatformMaintenance',
    'PlatformOps',
    $opsOk('PlatformOpsMaintenance'),
    [$path('maintenance_key', ['type' => 'string', 'minLength' => 1]), $parameterRef('IfMatchRevision'), $parameterRef('IdempotencyKey')],
    $jsonBody(['type' => 'object', 'additionalProperties' => false, 'maxProperties' => 0]),
));
$opsTasks = [
    'backup' => ['submitPlatformBackup', ['type' => 'object', 'additionalProperties' => false, 'required' => ['provider_key'], 'properties' => ['provider_key' => ['type' => 'string']]]],
    'restore' => ['submitPlatformRestore', ['type' => 'object', 'additionalProperties' => false, 'required' => ['provider_key', 'backup_reference_key', 'target_key'], 'properties' => ['provider_key' => ['type' => 'string'], 'backup_reference_key' => ['type' => 'string'], 'target_key' => ['type' => 'string']]]],
    'upgrade' => ['submitPlatformUpgrade', ['type' => 'object', 'additionalProperties' => false, 'maxProperties' => 0]],
    'module' => ['submitPlatformModuleOperation', ['type' => 'object', 'additionalProperties' => false, 'required' => ['request_key'], 'properties' => ['request_key' => ['type' => 'string']]]],
];
foreach ($opsTasks as $route => [$operationId, $schema]) {
    $setOverride($overrides, 'POST', '/platformapi/v1/ops/tasks/' . $route, $operation(
        $operationId,
        'PlatformOps',
        $opsOk('PlatformOpsTask'),
        [$parameterRef('IdempotencyKey')],
        $jsonBody($schema),
        errors: $route === 'restore' ? ['OPS_RESTORE_UNSUPPORTED_SCHEMA'] : [],
    ));
}

$planProperties = [
    'id' => $positiveId, 'code' => ['type' => 'string'], 'name' => ['type' => 'string'],
    'status' => ['type' => 'string', 'enum' => ['active','inactive']], 'revision' => $positiveId,
    'module_keys' => $stringList, 'permission_keys' => $stringList,
];
$schemas['TenantPlan'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => array_keys($planProperties), 'properties' => $planProperties];
$licenseProperties = [
    'id' => $positiveId, 'tenant_id' => $positiveId, 'plan_id' => $positiveId,
    'plan_code' => ['type' => 'string'], 'plan_name' => ['type' => 'string'],
    'status' => ['type' => 'string', 'enum' => ['active','suspended']],
    'effective_at' => $dateTime, 'expires_at' => $dateTime + ['nullable' => true],
    'revision' => $positiveId, 'effective' => ['type' => 'boolean'],
    'state' => ['type' => 'string', 'enum' => ['active','suspended','upcoming','expired']],
];
$schemas['TenantLicense'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => array_keys($licenseProperties), 'properties' => $licenseProperties];
$schemas['TenantLicenseData'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['tenant_id','license'], 'properties' => ['tenant_id' => $positiveId,
        'license' => ['allOf' => [$ref('TenantLicense')], 'nullable' => true]]];
$planWrite = ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
    'module_keys' => $stringList, 'permission_keys' => $stringList,
    'change_reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500]];
$schemas['TenantPlanCreate'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['code', ...array_keys($planWrite)], 'properties' => ['code' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$'], ...$planWrite]];
$schemas['TenantPlanUpdate'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['id','expected_revision','status', ...array_keys($planWrite)],
    'properties' => ['id' => $positiveId, 'expected_revision' => $positiveId, 'status' => $planProperties['status'], ...$planWrite]];
$licenseWrite = ['tenant_id' => $positiveId, 'plan_id' => $positiveId, 'status' => $licenseProperties['status'],
    'effective_at' => $dateTime, 'expires_at' => $dateTime + ['nullable' => true],
    'expected_revision' => ['type' => 'integer', 'minimum' => 0], 'change_reason' => $planWrite['change_reason']];
$schemas['TenantLicenseWrite'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => array_keys($licenseWrite), 'properties' => $licenseWrite];
$add($paths, 'GET', '/platformapi/plans', $operation('listTenantPlans', 'TenantPlans', [
    '200' => $success(['type' => 'object', 'additionalProperties' => false, 'required' => ['lists','count'],
        'properties' => ['lists' => ['type' => 'array', 'items' => $ref('TenantPlan')], 'count' => ['type' => 'integer', 'minimum' => 0]]]),
    '401' => $error, '403' => $error, '422' => $error,
], [$query('page', ['type' => 'integer', 'minimum' => 1, 'default' => 1]), $query('page_size', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20])]));
$add($paths, 'POST', '/platformapi/plans', $operation('createTenantPlan', 'TenantPlans', ['200' => $success($ref('TenantPlan')), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error], requestBody: $jsonBody($ref('TenantPlanCreate'))));
$add($paths, 'PUT', '/platformapi/plans', $operation('updateTenantPlan', 'TenantPlans', ['200' => $success($ref('TenantPlan')), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error], requestBody: $jsonBody($ref('TenantPlanUpdate'))));
$add($paths, 'GET', '/platformapi/tenants/license', $operation('getTenantLicense', 'TenantPlans', ['200' => $success($ref('TenantLicenseData')), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error], [$query('tenant_id', $positiveId, true)]));
$add($paths, 'PUT', '/platformapi/tenants/license', $operation('updateTenantLicense', 'TenantPlans', ['200' => $success($ref('TenantLicenseData')), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error], requestBody: $jsonBody($ref('TenantLicenseWrite'))));

return ['paths' => $paths, 'overrides' => $overrides, 'components' => ['schemas' => $schemas]];
