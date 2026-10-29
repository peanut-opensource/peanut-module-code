<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Definition;

use PeanutAdmin\Modules\Workflow\Application\WorkflowException;

/** Sole graph protocol; layout does not participate in the execution digest. */
final readonly class WorkflowGraph
{
    public const CAPABILITIES = ['workflow.approval', 'workflow.human_task', 'workflow.direct', 'workflow.claim', 'workflow.all_approve', 'workflow.any_approve', 'workflow.exclusive_gateway', 'workflow.service_task', 'workflow.return', 'workflow.notification'];
    private function __construct(public array $data, public string $canonicalJson, public string $sha256) {}

    public static function fromArray(array $graph, bool $publish = true): self
    {
        if (strlen(self::json($graph)) > 65535) { throw WorkflowException::definitionInvalid('Graph exceeds capacity.'); }
        self::keys($graph, ['contract_version', 'scenario_contract_version', 'subject_resource_key', 'subject_read_operation', 'subject_start_operation', 'start_permission_keys', 'required_capabilities', 'nodes', 'transitions']);
        if ($graph['contract_version'] !== 2) {
            throw WorkflowException::unsupported('contract_version', '/graph/contract_version');
        }
        if (!is_int($graph['scenario_contract_version']) || $graph['scenario_contract_version'] < 1) {
            throw WorkflowException::definitionInvalid('scenario_contract_version', '/graph/scenario_contract_version');
        }
        foreach (['subject_resource_key', 'subject_read_operation', 'subject_start_operation'] as $key) {
            self::identifier($graph[$key]);
        }
        self::strings($graph['start_permission_keys'], true);
        self::strings($graph['required_capabilities']);
        self::items($graph['nodes'], 100);
        self::items($graph['transitions'], 200);
        $nodes = [];
        $nodeIndexes = [];
        foreach ($graph['nodes'] as $nodeIndex => $node) {
            self::keys($node, ['key', 'label', 'type', 'config']);
            self::identifier($node['key']);
            if (isset($nodes[$node['key']])) {
                throw WorkflowException::definitionInvalid('Duplicate node key: ' . $node['key'], '/graph/nodes/' . $nodeIndex . '/key');
            }
            if (!is_string($node['label']) || mb_strlen($node['label']) > 160 || !is_array($node['config'])) {
                throw WorkflowException::definitionInvalid('Invalid node.', '/graph/nodes/' . $nodeIndex);
            }
            if (!in_array($node['type'], ['start', 'approval', 'human_task', 'exclusive_gateway', 'service_task', 'end'], true)) {
                throw WorkflowException::unsupported('nodes.' . $node['key'] . '.type', '/graph/nodes/' . $nodeIndex . '/type');
            }
            self::config($node, $publish, '/graph/nodes/' . $nodeIndex . '/config');
            $nodes[$node['key']] = $node;
            $nodeIndexes[$node['key']] = $nodeIndex;
        }
        // A concrete unsupported node strategy takes precedence over its capability declaration.
        // These indices belong to the submitted graph, before canonical sorting.
        foreach ($graph['required_capabilities'] as $capabilityIndex => $capability) {
            if (!in_array($capability, self::CAPABILITIES, true)) {
                throw WorkflowException::unsupported($capability, '/graph/required_capabilities/' . $capabilityIndex);
            }
        }
        $edges = [];
        $edgeIndexes = [];
        foreach ($graph['transitions'] as $edgeIndex => $edge) {
            self::keys($edge, ['key', 'from', 'to', 'action', 'operation', 'permission_keys', 'return_edge', 'max_traversals']);
            foreach (['key', 'from', 'to', 'operation'] as $key) {
                self::identifier($edge[$key]);
            }
            self::strings($edge['permission_keys'], true);
            if (isset($edges[$edge['key']])) {
                throw WorkflowException::definitionInvalid('Duplicate transition key: ' . $edge['key'], '/graph/transitions/' . $edgeIndex . '/key');
            }
            if (!is_bool($edge['return_edge']) || !in_array($edge['action'], ['advance', 'approve', 'reject', 'complete', 'return', 'resubmit', 'succeeded', 'branch'], true)) {
                throw WorkflowException::definitionInvalid('Invalid edge.', '/graph/transitions/' . $edgeIndex);
            }
            if ($edge['return_edge'] ? (!is_int($edge['max_traversals']) || $edge['max_traversals'] < 1 || $edge['max_traversals'] > 10 || $edge['action'] !== 'return') : $edge['max_traversals'] !== null) {
                throw WorkflowException::definitionInvalid('Invalid return bound.');
            }
            $edges[$edge['key']] = $edge;
            $edgeIndexes[$edge['key']] = $edgeIndex;
        }
        if ($publish) {
            self::executable($nodes, $edges, $nodeIndexes, $edgeIndexes);
            $used = [];
            foreach ($nodes as $node) {
                if (!in_array($node['type'], ['start', 'end'], true)) { $used[] = 'workflow.' . $node['type']; }
                if (isset($node['config']['allocation_mode']) && $node['config']['allocation_mode'] !== 'multi') { $used[] = 'workflow.' . $node['config']['allocation_mode']; }
                if (isset($node['config']['aggregation_policy'])) { $used[] = 'workflow.' . $node['config']['aggregation_policy']; }
            }
            foreach ($edges as $edge) { if ($edge['return_edge']) { $used[] = 'workflow.return'; } }
            if (array_diff($used, $graph['required_capabilities'])) {
                throw WorkflowException::definitionInvalid('The graph must declare every capability it uses.');
            }
        }
        usort($graph['nodes'], static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));
        usort($graph['transitions'], static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));
        sort($graph['start_permission_keys']);
        sort($graph['required_capabilities']);
        $json = self::json($graph);
        return new self($graph, $json, hash('sha256', $json));
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(json_decode($json, true, 128, JSON_THROW_ON_ERROR));
    }

    public function node(string $key): array
    {
        foreach ($this->data['nodes'] as $node) {
            if ($node['key'] === $key) {
                return $node;
            }
        }
        throw WorkflowException::definitionInvalid('Unknown node.');
    }

    public function startNode(): array
    {
        foreach ($this->data['nodes'] as $node) {
            if ($node['type'] === 'start') {
                return $node;
            }
        }
        throw WorkflowException::definitionInvalid('Missing start.');
    }

    public function outgoing(string $node, ?string $action = null): array
    {
        return array_values(array_filter($this->data['transitions'], static fn(array $edge): bool => $edge['from'] === $node && ($action === null || $edge['action'] === $action)));
    }

    public function edge(string $node, string $action): array
    {
        $edges = $this->outgoing($node, $action);
        return count($edges) === 1 ? $edges[0] : throw WorkflowException::transitionUnavailable();
    }

    public static function json(mixed $value): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    ksort($value, SORT_STRING);
                }
                foreach ($value as $key => $item) {
                    $value[$key] = $sort($item);
                }
            }
            return $value;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function keys(mixed $input, array $keys): void
    {
        if (!is_array($input)) {
            throw WorkflowException::definitionInvalid('Object required.');
        }
        $actual = array_keys($input);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw WorkflowException::definitionInvalid('Unknown or missing fields.');
        }
    }

    private static function config(array $node, bool $publish, string $path): void
    {
        $c = $node['config'];
        if ($node['type'] === 'start') {
            self::keys($c, []);
        } elseif ($node['type'] === 'end') {
            self::keys($c, ['result_code']);
            self::identifier($c['result_code']);
        } elseif (in_array($node['type'], ['approval', 'human_task'], true)) {
            self::keys($c, ['allocation_mode', 'aggregation_policy', 'assignments', 'participant_rules', 'watcher_rules', 'allow_self_approval', 'opinion_required']);
            if (is_string($c['aggregation_policy']) && !in_array($c['aggregation_policy'], ['all_approve', 'any_approve'], true)) {
                throw WorkflowException::unsupported('workflow.' . $c['aggregation_policy'], $path . '/aggregation_policy');
            }
            if (!in_array($c['allocation_mode'], ['direct', 'claim', 'multi'], true) || !is_bool($c['allow_self_approval']) || !is_bool($c['opinion_required']) || ($c['allocation_mode'] === 'multi' ? ($node['type'] !== 'approval' || !in_array($c['aggregation_policy'], ['all_approve', 'any_approve'], true)) : $c['aggregation_policy'] !== null)) {
                throw WorkflowException::definitionInvalid('Invalid allocation/aggregation.');
            }
            foreach (['assignments', 'participant_rules', 'watcher_rules'] as $field) {
                self::items($c[$field], 100);
                foreach ($c[$field] as $ruleIndex => $rule) {
                    self::keys($rule, ['kind', 'key', 'required', 'department_scope']);
                    if (!is_string($rule['kind'])) {
                        throw WorkflowException::definitionInvalid('Invalid assignment kind.', $path . '/' . $field . '/' . $ruleIndex . '/kind');
                    }
                    if (!in_array($rule['kind'], ['member', 'role_members', 'department_members', 'department_leaders', 'subject_owner', 'initiator', 'previous_actor'], true)) {
                        throw WorkflowException::unsupported('assignment.' . (string) $rule['kind'], $path . '/' . $field . '/' . $ruleIndex . '/kind');
                    }
                    if (!is_bool($rule['required']) || !($rule['department_scope'] === null || is_string($rule['department_scope'])) || (in_array($rule['kind'], ['initiator', 'previous_actor', 'subject_owner'], true) ? $rule['key'] !== null : (!is_string($rule['key']) || $rule['key'] === '' || strlen($rule['key']) > 160))) {
                        throw WorkflowException::definitionInvalid('Invalid assignment rule.');
                    }
                }
            }
            if ($publish && $c['assignments'] === []) {
                throw WorkflowException::definitionInvalid('Assignments required.');
            }
        } elseif ($node['type'] === 'exclusive_gateway') {
            self::keys($c, ['branches', 'default_transition_key']);
            self::identifier($c['default_transition_key']);
            self::items($c['branches'], 50);
            $leaves = 0;
            foreach ($c['branches'] as $branch) {
                self::keys($branch, ['transition_key', 'condition']);
                self::identifier($branch['transition_key']);
                WorkflowCondition::validate($branch['condition'], 0, $leaves);
            }
        } else {
            self::keys($c, ['action_key', 'contract_version', 'input']);
            self::identifier($c['action_key']);
            if (!is_int($c['contract_version']) || $c['contract_version'] < 1 || !is_array($c['input'])) {
                throw WorkflowException::definitionInvalid('Invalid service action.');
            }
            WorkflowActionInput::validate($c['input']);
        }
    }

    private static function executable(array $nodes, array $edges, array $nodeIndexes, array $edgeIndexes): void
    {
        $starts = array_filter($nodes, static fn(array $n): bool => $n['type'] === 'start');
        if (count($starts) !== 1) {
            throw WorkflowException::definitionInvalid('Exactly one start node is required.', '/graph/nodes');
        }
        if (!array_filter($nodes, static fn(array $n): bool => $n['type'] === 'end')) {
            throw WorkflowException::definitionInvalid('At least one end node is required.', '/graph/nodes');
        }
        $out = [];
        foreach ($edges as $edgeKey => $edge) {
            $edgePath = '/graph/transitions/' . $edgeIndexes[$edgeKey];
            if (!isset($nodes[$edge['from']])) {
                throw WorkflowException::definitionInvalid('Unknown transition source: ' . $edge['from'], $edgePath . '/from');
            }
            if (!isset($nodes[$edge['to']])) {
                throw WorkflowException::definitionInvalid('Unknown transition target: ' . $edge['to'], $edgePath . '/to');
            }
            if ($edge['from'] === $edge['to'] || ($edge['return_edge'] && !in_array($nodes[$edge['to']]['type'], ['approval', 'human_task'], true))) {
                throw WorkflowException::definitionInvalid('Invalid edge endpoints.', $edgePath);
            }
            $out[$edge['from']][] = $edge;
        }
        foreach ($nodes as $key => $node) {
            $exits = $out[$key] ?? [];
            $allowed = match ($node['type']) {
                'start' => ['advance'], 'approval' => ['approve', 'reject', 'return'], 'human_task' => ['complete', 'return', 'resubmit'], 'service_task' => ['succeeded'], 'exclusive_gateway' => ['branch'], default => [],
            };
            foreach ($exits as $exit) {
                if (!in_array($exit['action'], $allowed, true) || ($exit['action'] === 'return' && !$exit['return_edge'])) {
                    throw WorkflowException::definitionInvalid('Invalid action for node type.');
                }
            }
            if ($node['type'] === 'end' ? $exits !== [] : $exits === []) {
                throw WorkflowException::definitionInvalid(($node['type'] === 'end' ? 'End node has an outgoing transition: ' : 'Node has no outgoing transition: ') . $key, '/graph/nodes/' . $nodeIndexes[$key]);
            }
            $required = match ($node['type']) {
                'start' => ['advance'], 'approval' => ['approve', 'reject'], 'service_task' => ['succeeded'], default => [],
            };
            foreach ($required as $action) {
                if (count(array_filter($exits, static fn(array $e): bool => $e['action'] === $action)) !== 1) {
                    throw WorkflowException::definitionInvalid('Missing/ambiguous action at ' . $key);
                }
            }
            if ($node['type'] === 'human_task' && count(array_filter($exits, static fn(array $e): bool => in_array($e['action'], ['complete', 'resubmit'], true))) !== 1) {
                throw WorkflowException::definitionInvalid('Human task requires exactly one complete or resubmit exit.');
            }
            if ($node['type'] !== 'exclusive_gateway' && count(array_unique(array_column($exits, 'action'))) !== count($exits)) {
                throw WorkflowException::definitionInvalid('Ambiguous task action.');
            }
            if ($node['type'] === 'start' && count($exits) !== 1) {
                throw WorkflowException::definitionInvalid('Start requires one exit.');
            }
            if ($node['type'] === 'exclusive_gateway') {
                $wanted = [...array_column($node['config']['branches'], 'transition_key'), $node['config']['default_transition_key']];
                if (count(array_unique($wanted)) !== count($wanted) || array_diff($wanted, array_column($exits, 'key')) || array_diff(array_column($exits, 'key'), $wanted)) {
                    throw WorkflowException::definitionInvalid('Gateway exits mismatch.');
                }
            }
        }
        $seen = [];
        $stack = [];
        $visit = function (string $key) use (&$visit, &$seen, &$stack, $out): void {
            if (isset($stack[$key])) {
                throw WorkflowException::definitionInvalid('Ordinary cycle.');
            }
            if (isset($seen[$key])) {
                return;
            }
            $stack[$key] = true;
            foreach ($out[$key] ?? [] as $edge) {
                if (!$edge['return_edge']) {
                    $visit($edge['to']);
                }
            }
            unset($stack[$key]);
            $seen[$key] = true;
        };
        $visit(array_key_first($starts));
        if (count($seen) !== count($nodes)) {
            foreach ($nodes as $key => $_node) {
                if (!isset($seen[$key])) {
                    throw WorkflowException::definitionInvalid('Unreachable node: ' . $key, '/graph/nodes/' . $nodeIndexes[$key]);
                }
            }
        }
        foreach ($edges as $edge) {
            if (!$edge['return_edge']) {
                continue;
            }
            $reachable = [];
            $walk = function (string $key) use (&$walk, &$reachable, $out): void {
                if (isset($reachable[$key])) {
                    return;
                }
                $reachable[$key] = true;
                foreach ($out[$key] ?? [] as $next) {
                    if (!$next['return_edge']) {
                        $walk($next['to']);
                    }
                }
            };
            $walk($edge['to']);
            if (!isset($reachable[$edge['from']])) {
                throw WorkflowException::definitionInvalid('Return must target an earlier path.');
            }
        }
    }

    private static function identifier(mixed $v): void
    {
        if (!is_string($v) || strlen($v) > 64 || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $v) !== 1) {
            throw WorkflowException::definitionInvalid('Invalid identifier.');
        }
    }

    private static function items(mixed $v, int $limit): void
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > $limit) {
            throw WorkflowException::definitionInvalid('List/capacity invalid.');
        }
    }

    private static function strings(mixed $v, bool $required = false): void
    {
        self::items($v, 100);
        if (($required && !$v) || count(array_unique($v)) !== count($v)) {
            throw WorkflowException::definitionInvalid('Invalid identifier list.');
        }
        foreach ($v as $item) {
            self::identifier($item);
        }
    }
}
