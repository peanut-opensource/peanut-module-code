<?php

declare(strict_types=1);

namespace PeanutAdmin\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags raw think\facade\Db::table()/name()/query()/execute() calls in tenant/module layers
 * unless targeting explicitly allowlisted platform-global tables.
 *
 * @implements Rule<StaticCall>
 */
final class TenantRawQueryRule implements Rule
{
    private const EXACT_GLOBAL_TABLES = [
        'information_schema.columns',
        'information_schema.tables',
        'migrations',
        'module_installation',
        // Instance-wide tables defined by the Ops installation migration.
        'ops_backup_evidence',
        'ops_maintenance_window',
        'ops_module_execution',
        'ops_module_request',
        'ops_restore_evidence',
        'ops_task',
        'ops_upgrade_execution',
        'ops_upgrade_step',
        'permission',
        'platform_audit_event',
        'platform_operator',
        'platform_operator_role',
        'platform_role',
        'platform_role_permission',
    ];

    private const GUARDED_METHODS = [
        'table',
        'name',
        'query',
        'execute',
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return [];
        }

        $class = ltrim($node->class->toString(), '\\');
        if ($class !== 'think\facade\Db' && $class !== 'think\Db') {
            return [];
        }

        $method = $node->name->toString();
        if (!in_array($method, self::GUARDED_METHODS, true)) {
            return [];
        }

        $file = str_replace('\\', '/', $scope->getFile());
        if (!str_contains($file, '/app/modules/')
            && !str_contains($file, '/app/adminapi/')
            && !str_contains($file, '/app/api/')) {
            return [];
        }

        if ($method === 'table' || $method === 'name') {
            $firstArg = $node->args[0]->value ?? null;
            if ($firstArg instanceof String_) {
                $table = strtolower($firstArg->value);
                if (in_array($table, self::EXACT_GLOBAL_TABLES, true)) {
                    return [];
                }
            }
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Raw %s::%s() call in tenant/module scope. Use TenantModel, DeclarativeQueryBuilder, or a tenant-scoped repository.',
                $class,
                $method,
            ))
                ->identifier('peanut.rawDbQuery')
                ->build(),
        ];
    }
}
