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
 * Flags raw think\facade\Db::table()/name() calls in tenant/business layers
 * unless targeting known platform-global tables or explicitly annotated.
 *
 * @implements Rule<StaticCall>
 */
final class TenantRawQueryRule implements Rule
{
    private const PLATFORM_GLOBAL_TABLES = [
        'information_schema.',
        'migrations',
        'module_installation',
        'ops_task',
        'permission',
        'platform_',
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
        if ($method !== 'table' && $method !== 'name') {
            return [];
        }

        $file = str_replace('\\', '/', $scope->getFile());
        if (!str_contains($file, '/app/modules/')
            && !str_contains($file, '/app/adminapi/')
            && !str_contains($file, '/app/api/')) {
            return [];
        }

        $firstArg = $node->args[0]->value ?? null;
        if ($firstArg instanceof String_) {
            $table = strtolower($firstArg->value);
            foreach (self::PLATFORM_GLOBAL_TABLES as $prefix) {
                if (str_starts_with($table, $prefix) || $table === $prefix) {
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
