<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Definition;

use PeanutAdmin\Modules\Workflow\Application\WorkflowException;
use Opis\JsonSchema\Validator;

/** Bounded business input mapping; no executable expressions or runtime class lookup. */
final class WorkflowActionInput
{
    public static function validate(array $mapping, ?array $fields = null): void
    {
        if (count($mapping) > 100 || ($mapping !== [] && array_is_list($mapping))) {
            throw WorkflowException::definitionInvalid('Action input must be a parameter map.');
        }
        foreach ($mapping as $parameter => $binding) {
            if (!is_string($parameter) || strlen($parameter) > 160 || !is_array($binding)) {
                throw WorkflowException::definitionInvalid('Invalid action parameter.');
            }
            $source = $binding['source'] ?? null;
            WorkflowGraph::keys($binding, $source === 'literal' ? ['source', 'value'] : ['source', 'field']);
            if ($source === 'literal') {
                self::literal($binding['value']);
            } elseif ($source === 'instance') {
                if (!in_array($binding['field'], ['subject_key', 'subject_revision_key', 'instance_key', 'cycle_no'], true)) {
                    throw WorkflowException::definitionInvalid('Unsupported instance binding.');
                }
            } elseif ($source === 'node_input') {
                if (!is_string($binding['field']) || strlen($binding['field']) > 160 || ($fields !== null && !isset($fields[$binding['field']]))) {
                    throw WorkflowException::definitionInvalid('Unregistered input binding.');
                }
            } else {
                throw WorkflowException::definitionInvalid('Unsupported input binding source.');
            }
        }
    }

    public static function resolve(array $mapping, array $instance, array $nodeInput, array $fields, array $schema): array
    {
        self::validate($mapping, $fields);
        $values = [];
        foreach ($mapping as $key => $binding) {
            $values[$key] = match ($binding['source']) {
                'literal' => $binding['value'],
                'instance' => $binding['field'] === 'cycle_no' ? (int) $instance['cycle_no'] : $instance[$binding['field']],
                'node_input' => array_key_exists($binding['field'], $nodeInput) ? $nodeInput[$binding['field']] : throw WorkflowException::blocked('Missing fixed node input.'),
            };
        }
        try {
            $document = json_decode(WorkflowGraph::json((object) $values), false, 128, JSON_THROW_ON_ERROR);
            $registered = json_decode(WorkflowGraph::json(WorkflowJsonSchema::project($schema)), false, 128, JSON_THROW_ON_ERROR);
            if (!(new Validator())->validate($document, $registered)->isValid()) {
                throw WorkflowException::blocked('Business action input does not match its registered schema.');
            }
        } catch (WorkflowException $e) {
            throw $e;
        } catch (\Throwable) {
            throw WorkflowException::unsupported('action_input_schema');
        }
        return $values;
    }

    private static function literal(mixed $value, int $depth = 0): void
    {
        if ($depth > 16 || !(is_array($value) || is_string($value) || is_int($value) || is_bool($value) || $value === null)) {
            throw WorkflowException::definitionInvalid('Literal input must use bounded exact JSON values.');
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::literal($child, $depth + 1);
            }
        }
    }
}
