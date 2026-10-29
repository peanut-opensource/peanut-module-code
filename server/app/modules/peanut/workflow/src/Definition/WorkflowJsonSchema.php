<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Definition;

/** JSON Schema object maps have distinct wire semantics from list/literal values. */
final class WorkflowJsonSchema
{
    public static function project(array $schema): array
    {
        if (array_key_exists('properties', $schema)) {
            $properties = [];
            foreach ($schema['properties'] as $key => $property) {
                $properties[$key] = self::project($property);
            }
            $schema['properties'] = (object) $properties;
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::project($schema['items']);
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $list) {
            if (isset($schema[$list])) {
                $schema[$list] = array_map(self::project(...), $schema[$list]);
            }
        }
        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            $schema['additionalProperties'] = self::project($schema['additionalProperties']);
        }
        return $schema;
    }
}
