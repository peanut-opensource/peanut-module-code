<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Definition;

use PeanutAdmin\Modules\Workflow\Application\WorkflowException;

final class WorkflowCondition
{
    public static function validate(mixed $c, int $depth = 0, int &$leaves = 0): void
    {
        if (!is_array($c) || $depth > 3) {
            throw WorkflowException::definitionInvalid('Condition depth.');
        }
        if (isset($c['all']) || isset($c['any'])) {
            $key = isset($c['all']) ? 'all' : 'any';
            WorkflowGraph::keys($c, [$key]);
            if (!is_array($c[$key]) || !array_is_list($c[$key]) || !$c[$key]) {
                throw WorkflowException::definitionInvalid('Empty condition group.');
            }
            foreach ($c[$key] as $child) {
                self::validate($child, $depth + 1, $leaves);
            }
        } else {
            WorkflowGraph::keys($c, ['field', 'operator', 'value']);
            if (++$leaves > 50 || !is_string($c['field']) || strlen($c['field']) > 160 || !in_array($c['operator'], ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'is_null', 'is_not_null'], true)) {
                throw WorkflowException::definitionInvalid('Condition leaf.');
            }
        }
    }

    public static function evaluate(array $c, array $input, array $fields): bool
    {
        self::validate($c);
        if (isset($c['all']) || isset($c['any'])) {
            $all = isset($c['all']);
            $results = array_map(static fn(array $child): bool => self::evaluate($child, $input, $fields), $c[$all ? 'all' : 'any']);
            return $all ? !in_array(false, $results, true) : in_array(true, $results, true);
        }
        if (!isset($fields[$c['field']]) || !array_key_exists($c['field'], $input)) {
            throw WorkflowException::blocked('Missing registered field: ' . $c['field']);
        }
        $v = $input[$c['field']];
        $spec = $fields[$c['field']];
        $op = $c['operator'];
        self::registered($c, $fields);
        if ($v === null && !($spec['nullable'] ?? false)) {
            throw WorkflowException::blocked('Nonnullable field.');
        }
        if ($op === 'is_null' || $op === 'is_not_null') {
            if ($v !== null) {
                self::compare($v, $v, $spec);
            }
            return $op === 'is_null' ? $v === null : $v !== null;
        }
        if ($v === null) {
            throw WorkflowException::blocked('Null requires an explicit null operator.');
        }
        $choices = in_array($op, ['in', 'not_in'], true) ? $c['value'] : [$c['value']];
        if (!is_array($choices) || !array_is_list($choices) || !$choices || count($choices) > 100) {
            throw WorkflowException::blocked('Invalid comparison list.');
        }
        $comparisons = array_map(static fn(mixed $b): int => self::compare($v, $b, $spec), $choices);
        $n = $comparisons[0] ?? 0;
        return match ($op) {
            'eq' => $n === 0, 'ne' => $n !== 0, 'gt' => $n > 0, 'gte' => $n >= 0, 'lt' => $n < 0, 'lte' => $n <= 0,
            'in' => in_array(0, $comparisons, true), 'not_in' => !in_array(0, $comparisons, true),
            default => throw WorkflowException::blocked('Invalid operator.'),
        };
    }

    /** Publish-time validation against the current trusted field registry. */
    public static function registered(array $c, array $fields): void
    {
        self::validate($c);
        if (isset($c['all']) || isset($c['any'])) {
            foreach ($c[isset($c['all']) ? 'all' : 'any'] as $child) {
                self::registered($child, $fields);
            }
            return;
        }
        $spec = $fields[$c['field']] ?? throw WorkflowException::blocked('Unregistered condition field.');
        $op = $c['operator'];
        if (in_array($op, ['is_null', 'is_not_null'], true)) {
            if ($c['value'] !== null || !($spec['nullable'] ?? false)) {
                throw WorkflowException::blocked('Null operators require a nullable field and null operand.');
            }
            return;
        }
        if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true) && !in_array($spec['type'] ?? null, ['integer', 'decimal', 'datetime'], true)) {
            throw WorkflowException::blocked('Ordered comparison requires a numeric or datetime field.');
        }
        $values = in_array($op, ['in', 'not_in'], true) ? $c['value'] : [$c['value']];
        if (!is_array($values) || !array_is_list($values) || !$values || count($values) > 100) {
            throw WorkflowException::blocked('Invalid comparison list.');
        }
        foreach ($values as $value) {
            self::compare($value, $value, $spec);
        }
    }

    private static function compare(mixed $a, mixed $b, array $spec): int
    {
        $type = $spec['type'] ?? '';
        $valid = match ($type) {
            'boolean' => is_bool($a) && is_bool($b), 'integer' => is_int($a) && is_int($b),
            'string', 'enum', 'datetime' => is_string($a) && is_string($b) && strlen($a) <= 2000 && strlen($b) <= 2000,
            'decimal' => is_string($a) && is_string($b) && preg_match('/^-?\d+(?:\.\d+)?$/D', $a) === 1 && preg_match('/^-?\d+(?:\.\d+)?$/D', $b) === 1 && strlen($a) <= 100 && strlen($b) <= 100,
            default => false,
        };
        if (!$valid || ($type === 'enum' && (!in_array($a, $spec['values'] ?? [], true) || !in_array($b, $spec['values'] ?? [], true)))) {
            throw WorkflowException::blocked('Condition type mismatch.');
        }
        if ($type === 'datetime' && (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/D', $a) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/D', $b) !== 1)) {
            throw WorkflowException::blocked('Datetime must be UTC.');
        }
        if ($type === 'datetime') {
            $normalize = static function (string $value): string {
                $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.v\Z' : '!Y-m-d\TH:i:s\Z';
                $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
                if ($date === false || \DateTimeImmutable::getLastErrors() !== false) {
                    throw WorkflowException::blocked('Invalid UTC datetime.');
                }
                return $date->format('Y-m-d\TH:i:s.u\Z');
            };
            return strcmp($normalize($a), $normalize($b)) <=> 0;
        }
        if ($type !== 'decimal') {
            return is_string($a) ? strcmp($a, $b) <=> 0 : $a <=> $b;
        }
        [$ai, $af] = array_pad(explode('.', ltrim($a, '-'), 2), 2, '');
        [$bi, $bf] = array_pad(explode('.', ltrim($b, '-'), 2), 2, '');
        $ai = ltrim($ai, '0') ?: '0';
        $bi = ltrim($bi, '0') ?: '0';
        $an = str_starts_with($a, '-') && ($ai !== '0' || trim($af, '0') !== '');
        $bn = str_starts_with($b, '-') && ($bi !== '0' || trim($bf, '0') !== '');
        if ($an !== $bn) {
            return $an ? -1 : 1;
        }
        $scale = max(strlen($af), strlen($bf));
        $n = (strlen($ai) <=> strlen($bi)) ?: ((strcmp($ai, $bi) <=> 0) ?: (strcmp(str_pad($af, $scale, '0'), str_pad($bf, $scale, '0')) <=> 0));
        return $an ? -$n : $n;
    }
}
