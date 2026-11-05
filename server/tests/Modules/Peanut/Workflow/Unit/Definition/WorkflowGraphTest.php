<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Tests\Unit\Definition;

use PeanutAdmin\Modules\Workflow\Application\WorkflowException;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;
use PHPUnit\Framework\TestCase;

final class WorkflowGraphTest extends TestCase
{
    public function testCanonicalizesAProductNeutralAnyReviewGraph(): void
    {
        $graph = WorkflowGraph::fromArray(self::validGraph());

        self::assertSame('subject.item', $graph->data['subject_resource_key']);
        self::assertSame('start', $graph->startNode()['key']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $graph->sha256);
        self::assertSame($graph->sha256, WorkflowGraph::fromJson($graph->canonicalJson)->sha256);
        self::assertSame('review', $graph->edge('start', 'advance')['to']);
        self::assertSame('approval', $graph->node('review')['type']);
        self::assertSame('multi', $graph->node('review')['config']['allocation_mode']);
        self::assertSame('any_approve', $graph->node('review')['config']['aggregation_policy']);
    }

    public function testAcceptsOnlyBoundedDeclaredReturnCycles(): void
    {
        $input = self::validGraph(true);
        WorkflowGraph::fromArray($input);
        $input['required_capabilities'][] = 'workflow.return';
        $input['transitions'][] = [
            'key' => 'return-to-review',
            'from' => 'action',
            'to' => 'review',
            'action' => 'return',
            'operation' => 'return',
            'permission_keys' => ['subject.item.return'],
            'return_edge' => true,
            'max_traversals' => 3,
        ];
        $returnIndex = array_key_last($input['transitions']);
        self::assertSame(3, WorkflowGraph::fromArray($input)->edge('action', 'return')['max_traversals']);

        $input['transitions'][$returnIndex]['action'] = 'complete';
        $input['transitions'][$returnIndex]['return_edge'] = false;
        $input['transitions'][$returnIndex]['max_traversals'] = null;
        $input['transitions'] = array_values(array_filter(
            $input['transitions'],
            static fn(array $edge): bool => $edge['key'] !== 'finish',
        ));
        try {
            WorkflowGraph::fromArray($input);
            self::fail('An ordinary cycle must fail closed.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_DEFINITION_INVALID', $exception->errorCode);
            self::assertSame(422, $exception->httpStatus);
            self::assertSame('Ordinary cycle.', $exception->getMessage());
            self::assertNull($exception->validationPath);
        }
    }

    public function testSupportsSequentialReviewsWithoutAFixedStageCount(): void
    {
        $input = self::validGraph();
        $input['required_capabilities'][] = 'workflow.all_approve';
        $input['nodes'][1]['key'] = 'review-one';
        $input['nodes'][1]['label'] = 'First review';
        $secondReview = $input['nodes'][1];
        $secondReview['key'] = 'review-two';
        $secondReview['label'] = 'Second review';
        $secondReview['config']['aggregation_policy'] = 'all_approve';
        $secondReview['config']['assignments'] = [[
            'kind' => 'department_members', 'key' => 'quality',
            'required' => true, 'department_scope' => null,
        ]];
        array_splice($input['nodes'], 2, 0, [$secondReview]);
        foreach ($input['transitions'] as &$edge) {
            if ($edge['from'] === 'review') {
                $edge['from'] = 'review-one';
            }
            if ($edge['to'] === 'review') {
                $edge['to'] = 'review-one';
            }
            if ($edge['key'] === 'approve') {
                $edge['key'] = 'approve-one';
                $edge['to'] = 'review-two';
            }
        }
        unset($edge);
        $input['transitions'][] = [
            'key' => 'approve-two', 'from' => 'review-two', 'to' => 'action',
            'action' => 'approve', 'operation' => 'approve',
            'permission_keys' => ['subject.item.approve'],
            'return_edge' => false, 'max_traversals' => null,
        ];
        $input['transitions'][] = [
            'key' => 'reject-two', 'from' => 'review-two', 'to' => 'done',
            'action' => 'reject', 'operation' => 'reject',
            'permission_keys' => ['subject.item.reject'],
            'return_edge' => false, 'max_traversals' => null,
        ];

        $graph = WorkflowGraph::fromArray($input);

        self::assertSame('review-one', $graph->edge('start', 'advance')['to']);
        self::assertSame('review-two', $graph->edge('review-one', 'approve')['to']);
        self::assertSame('action', $graph->edge('review-two', 'approve')['to']);
        self::assertSame('all_approve', $graph->node('review-two')['config']['aggregation_policy']);
    }

    public function testRejectsOutOfRangeReturnTraversalBounds(): void
    {
        $base = self::validGraph(true);
        WorkflowGraph::fromArray($base);
        foreach ([0, 11] as $bound) {
            $input = $base;
            $input['required_capabilities'][] = 'workflow.return';
            $input['transitions'][] = [
                'key' => 'return-to-review',
                'from' => 'action',
                'to' => 'review',
                'action' => 'return',
                'operation' => 'return',
                'permission_keys' => ['subject.item.return'],
                'return_edge' => true,
                'max_traversals' => $bound,
            ];
            try {
                WorkflowGraph::fromArray($input);
                self::fail("Return traversal bound {$bound} must fail closed.");
            } catch (WorkflowException $exception) {
                self::assertSame('WORKFLOW_DEFINITION_INVALID', $exception->errorCode);
                self::assertSame(422, $exception->httpStatus);
                self::assertSame('Invalid return bound.', $exception->getMessage());
                self::assertNull($exception->validationPath);
            }
        }
    }

    public function testRejectsUnknownKeysAndUnreachableNodes(): void
    {
        $base = self::validGraph();
        WorkflowGraph::fromArray($base);
        $input = $base;
        $input['unknown'] = true;

        try {
            WorkflowGraph::fromArray($input);
            self::fail('Unknown graph keys must fail closed.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_DEFINITION_INVALID', $exception->errorCode);
            self::assertSame(422, $exception->httpStatus);
            self::assertSame('Unknown or missing fields.', $exception->getMessage());
            self::assertNull($exception->validationPath);
        }

        $input = $base;
        $orphanIndex = count($input['nodes']);
        $input['nodes'][] = [
            'key' => 'orphan', 'label' => 'Orphan', 'type' => 'end',
            'config' => ['result_code' => 'orphan'],
        ];
        try {
            WorkflowGraph::fromArray($input);
            self::fail('An unreachable node must fail closed.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_DEFINITION_INVALID', $exception->errorCode);
            self::assertSame(422, $exception->httpStatus);
            self::assertSame('Unreachable node: orphan', $exception->getMessage());
            self::assertSame('/graph/nodes/' . $orphanIndex, $exception->validationPath);
        }
    }

    public function testRejectsNonHumanApprovalAndRejectionEdges(): void
    {
        $base = self::validGraph();
        WorkflowGraph::fromArray($base);
        $finishIndex = array_search('finish', array_column($base['transitions'], 'key'), true);
        foreach (['approve', 'reject'] as $action) {
            $input = $base;
            $input['transitions'][$finishIndex]['action'] = $action;
            try {
                WorkflowGraph::fromArray($input);
                self::fail("{$action} must require a human work item.");
            } catch (WorkflowException $exception) {
                self::assertSame('WORKFLOW_DEFINITION_INVALID', $exception->errorCode);
                self::assertSame(422, $exception->httpStatus);
                self::assertSame('Invalid action for node type.', $exception->getMessage());
                self::assertNull($exception->validationPath);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function validGraph(bool $returnable = false): array
    {
        return [
            'contract_version' => 2,
            'scenario_contract_version' => 1,
            'subject_resource_key' => 'subject.item',
            'subject_read_operation' => 'read',
            'subject_start_operation' => 'submit',
            'start_permission_keys' => ['subject.item.submit'],
            'required_capabilities' => $returnable
                ? ['workflow.approval', 'workflow.any_approve', 'workflow.human_task', 'workflow.direct']
                : ['workflow.approval', 'workflow.any_approve', 'workflow.service_task'],
            'nodes' => [
                ['key' => 'start', 'label' => 'Start', 'type' => 'start', 'config' => []],
                [
                    'key' => 'review', 'label' => 'Review', 'type' => 'approval',
                    'config' => [
                        'allocation_mode' => 'multi', 'aggregation_policy' => 'any_approve',
                        'assignments' => [[
                            'kind' => 'role_members', 'key' => 'reviewer',
                            'required' => true, 'department_scope' => null,
                        ]],
                        'participant_rules' => [], 'watcher_rules' => [],
                        'allow_self_approval' => false, 'opinion_required' => false,
                    ],
                ],
                [
                    'key' => 'action', 'label' => 'Finalize',
                    'type' => $returnable ? 'human_task' : 'service_task',
                    'config' => $returnable ? [
                        'allocation_mode' => 'direct', 'aggregation_policy' => null,
                        'assignments' => [[
                            'kind' => 'role_members', 'key' => 'processor',
                            'required' => true, 'department_scope' => null,
                        ]],
                        'participant_rules' => [], 'watcher_rules' => [],
                        'allow_self_approval' => false, 'opinion_required' => false,
                    ] : [
                        'action_key' => 'subject.item.finalize', 'contract_version' => 1, 'input' => [],
                    ],
                ],
                ['key' => 'done', 'label' => 'Done', 'type' => 'end', 'config' => ['result_code' => 'done']],
            ],
            'transitions' => [
                [
                    'key' => 'submit', 'from' => 'start', 'to' => 'review',
                    'action' => 'advance', 'operation' => 'submit',
                    'permission_keys' => ['subject.item.submit'],
                    'return_edge' => false, 'max_traversals' => null,
                ],
                [
                    'key' => 'approve', 'from' => 'review', 'to' => 'action',
                    'action' => 'approve', 'operation' => 'approve',
                    'permission_keys' => ['subject.item.approve'],
                    'return_edge' => false, 'max_traversals' => null,
                ],
                [
                    'key' => 'reject', 'from' => 'review', 'to' => 'done',
                    'action' => 'reject', 'operation' => 'reject',
                    'permission_keys' => ['subject.item.reject'],
                    'return_edge' => false, 'max_traversals' => null,
                ],
                [
                    'key' => 'finish', 'from' => 'action', 'to' => 'done',
                    'action' => $returnable ? 'complete' : 'succeeded', 'operation' => 'finalize',
                    'permission_keys' => ['subject.item.finalize'],
                    'return_edge' => false, 'max_traversals' => null,
                ],
            ],
        ];
    }
}
