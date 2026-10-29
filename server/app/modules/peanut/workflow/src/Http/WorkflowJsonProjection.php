<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Http;

/** HTTP schema boundary only; persisted/canonical graph bytes remain unchanged. */
final class WorkflowJsonProjection
{
    public static function graph(array $graph): array
    {
        foreach ($graph['nodes'] as &$node) {
            if (isset($node['config']['input'])) {
                $node['config']['input'] = (object) $node['config']['input'];
            }
            $node['config'] = (object) $node['config'];
        }
        return $graph;
    }

    public static function schema(array $schema): array
    {
        return \PeanutAdmin\Modules\Workflow\Definition\WorkflowJsonSchema::project($schema);
    }

    public static function definition(array $definition): array
    {
        $definition['graph'] = self::graph($definition['graph']);
        return $definition;
    }

    public static function scenario(array $scenario): array
    {
        $scenario['fields'] = (object) $scenario['fields'];
        foreach ($scenario['actions'] as &$action) {
            $action['input_schema'] = self::schema($action['input_schema']);
        }
        return $scenario;
    }
}
