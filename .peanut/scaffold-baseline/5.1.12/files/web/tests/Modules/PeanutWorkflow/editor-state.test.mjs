import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  createEditorHistory,
  graphIssues,
  newNode,
} from '../../../.local/w20-tests/modules/peanut-workflow/designer/editor-state.js';
import {
  requiresReason,
  safeFormEntry,
  visibleTaskActions,
} from '../../../.local/w20-tests/modules/peanut-workflow/runtime/task-actions.js';
import {
  parseInstance,
  parseTask,
  readGraph,
  readLayout,
  workflowBase,
} from '../../../.local/w20-tests/modules/peanut-workflow/api.js';

function document() {
  const approval = newNode('approval', 'approval');
  approval.config.assignments = [
    {
      kind: 'member',
      key: '9007199254740993',
      required: true,
      department_scope: null,
    },
  ];
  return {
    graph: {
      contract_version: 2,
      scenario_contract_version: 1,
      subject_resource_key: 'application',
      subject_read_operation: 'read',
      subject_start_operation: 'start',
      start_permission_keys: ['application.start'],
      required_capabilities: [],
      nodes: [newNode('start', 'start'), approval, newNode('end', 'end')],
      transitions: [
        {
          key: 'first',
          from: 'start',
          to: 'approval',
          action: 'advance',
          operation: null,
          permission_keys: [],
          return_edge: false,
          max_traversals: null,
        },
        {
          key: 'last',
          from: 'approval',
          to: 'end',
          action: 'approve',
          operation: null,
          permission_keys: [],
          return_edge: false,
          max_traversals: null,
        },
      ],
    },
    layout: {
      nodes: [{ key: 'approval', x: 300, y: 120 }],
      viewport: { x: 20, y: 40, zoom: 0.8 },
    },
  };
}
test('property edits, layout and wizard/canvas share undo/redo with no mutation leaks', () => {
  const initial = document();
  const history = createEditorHistory(initial);
  history.update((draft) => {
    draft.graph.nodes[1].label = 'Edited';
    draft.graph.nodes[1].config.aggregation_policy = 'any_approve';
  });
  history.update((draft) => {
    draft.layout.nodes[0].x = 400;
  });
  assert.equal(history.dirty, true);
  assert.equal(
    history.document.graph.nodes[1].config.aggregation_policy,
    'any_approve'
  );
  history.undo();
  assert.equal(history.document.layout.nodes[0].x, 300);
  assert.equal(history.document.graph.nodes[1].label, 'Edited');
  history.undo();
  assert.deepEqual(history.document, initial);
  assert.equal(history.dirty, false);
  history.redo();
  history.redo();
  assert.equal(history.document.layout.nodes[0].x, 400);
  const outside = history.document;
  outside.graph.nodes[1].label = 'leak';
  assert.equal(history.document.graph.nodes[1].label, 'Edited');
  assert.equal(initial.graph.nodes[1].label, '审批');
  history.markSaved();
  assert.equal(history.dirty, false);
});
test('new edit after undo drops redo, replacing definition drops all history', () => {
  const history = createEditorHistory(document());
  history.update((draft) => {
    draft.graph.nodes[1].label = 'A';
  });
  history.undo();
  history.update((draft) => {
    draft.graph.nodes[1].label = 'B';
  });
  assert.equal(history.canRedo, false);
  history.replace(document());
  assert.equal(history.canUndo, false);
  assert.equal(history.dirty, false);
});
test('direct/claim never retain multi aggregation; human task cannot be multi', () => {
  const graph = document().graph;
  graph.nodes[1].config.allocation_mode = 'claim';
  assert.ok(
    graphIssues(graph).some((issue) => issue.field === 'aggregation_policy')
  );
  graph.nodes[1].config.aggregation_policy = null;
  assert.equal(graphIssues(graph).length, 0);
  graph.nodes[1].type = 'human_task';
  graph.nodes[1].config.allocation_mode = 'multi';
  assert.ok(
    graphIssues(graph).some((issue) => issue.field === 'allocation_mode')
  );
});
test('missing sources, first response, unsupported position and unsafe cycles are reported', () => {
  const graph = document().graph;
  graph.nodes[1].config.assignments = [];
  graph.nodes[1].config.aggregation_policy = 'first_response';
  assert.ok(
    graphIssues(graph).some((issue) => issue.code === 'ASSIGNEE_UNRESOLVED')
  );
  assert.ok(
    graphIssues(graph).some((issue) => issue.code === 'CAPABILITY_UNSUPPORTED')
  );
  assert.throws(() => readGraph(graph), /CAPABILITY_UNSUPPORTED/);
  const cyclic = document().graph;
  cyclic.transitions.push({
    ...cyclic.transitions[0],
    key: 'cycle',
    from: 'approval',
    to: 'start',
  });
  assert.ok(
    graphIssues(cyclic).some((issue) => issue.message.includes('成环'))
  );
  const position = document().graph;
  position.nodes[1].config.assignments = [
    {
      kind: 'position_members',
      key: '采购',
      required: true,
      department_scope: null,
    },
  ];
  assert.throws(() => readGraph(position), /CAPABILITY_UNSUPPORTED/);
});
test('graph/layout round trip keeps labels, bigint identifiers and layout separately', () => {
  const source = document();
  const graph = readGraph(JSON.parse(JSON.stringify(source.graph)));
  const layout = readLayout(JSON.parse(JSON.stringify(source.layout)));
  assert.deepEqual(graph, source.graph);
  assert.deepEqual(layout, source.layout);
  assert.equal('position' in graph.nodes[0], false);
  assert.throws(
    () =>
      readLayout({ nodes: [{ key: 'a', x: Infinity, y: 2 }], viewport: null }),
    /coordinate/
  );
});
test('contextual assignee rules preserve legal null keys and unknown policies never normalize away', () => {
  const source = document();
  source.graph.nodes[1].config.assignments = [
    {
      kind: 'subject_owner',
      key: null,
      required: true,
      department_scope: null,
    },
  ];
  assert.equal(
    readGraph(source.graph).nodes[1].config.assignments[0].key,
    null
  );
  assert.equal(graphIssues(source.graph).length, 0);
  const invalid = document();
  invalid.graph.nodes[1].config.aggregation_policy = 'first_response';
  const history = createEditorHistory(invalid);
  assert.equal(
    history.document.graph.nodes[1].config.aggregation_policy,
    'first_response'
  );
  assert.ok(
    graphIssues(history.document.graph).some(
      (issue) => issue.code === 'CAPABILITY_UNSUPPORTED'
    )
  );
});
function task() {
  return {
    work_item_key: '9007199254740993',
    instance_key: '9007199254740994',
    node_execution_key: '9007199254740995',
    label: '<img onerror=alert(1)>',
    type: 'approval',
    revision: 4,
    instance_revision: 3,
    node_revision: 2,
    status: 'pending',
    assignment_state: 'unassigned',
    owner_member_id: null,
    assignee_member_id: null,
    relations: ['candidate'],
    available_actions: ['claim'],
    decision: null,
  };
}
test('actions come solely from available_actions, with no candidate or owner inference', () => {
  const candidate = task();
  assert.deepEqual(visibleTaskActions(candidate), ['claim']);
  candidate.relations = ['participant', 'watcher'];
  candidate.owner_member_id = '20';
  candidate.available_actions = [];
  assert.deepEqual(visibleTaskActions(candidate), []);
  candidate.available_actions = ['approve', 'reject', 'delegate'];
  assert.deepEqual(visibleTaskActions(candidate), ['approve', 'reject']);
  assert.equal(requiresReason('reject'), true);
  assert.equal(requiresReason('return'), true);
  assert.equal(requiresReason('terminate'), true);
  assert.equal(requiresReason('approve'), false);
});
test('adapter refuses numeric bigint IDs, invalid status and malformed relation data', () => {
  assert.equal(parseTask(task()).work_item_key, '9007199254740993');
  assert.throws(
    () => parseTask({ ...task(), work_item_key: 9007199254740993 }),
    /work_item_key/
  );
  assert.throws(() => parseTask({ ...task(), status: 'claimed' }), /status/);
  assert.throws(() => parseTask({ ...task(), relations: [1] }), /relations/);
  assert.throws(
    () =>
      parseInstance({
        instance_key: '1',
        status: 'running',
        result_code: null,
        revision: 1,
        active_nodes: [],
        own_work_items: [null],
      }),
    /work item/
  );
});
test('base path never doubles adminapi and form entry rejects external navigation', () => {
  assert.equal(
    workflowBase('https://app.example/adminapi/'),
    '/api/v1/workflow'
  );
  assert.equal(workflowBase('/'), '/adminapi/api/v1/workflow');
  assert.equal(safeFormEntry('/application/20'), '/application/20');
  assert.equal(safeFormEntry('//evil.example'), null);
  assert.equal(safeFormEntry('javascript:alert(1)'), null);
  assert.equal(safeFormEntry('/\\evil'), null);
});
