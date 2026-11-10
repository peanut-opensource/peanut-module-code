import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createWorkflowApi } from '../../../.local/w20-tests/modules/peanut-workflow/api.js';
import { createWorkflowTransport } from '../../../.local/w20-tests/modules/peanut-workflow/transport.js';
const task = {
  work_item_key: '9007199254740993',
  instance_key: '9007199254740994',
  node_execution_key: '9007199254740995',
  label: '审批',
  type: 'approval',
  revision: 3,
  instance_revision: 5,
  node_revision: 4,
  status: 'pending',
  assignment_state: 'assigned',
  owner_member_id: '20',
  assignee_member_id: '20',
  relations: [],
  available_actions: [
    'claim',
    'release',
    'approve',
    'reject',
    'complete',
    'return',
  ],
  decision: null,
};
const instance = {
  instance_key: task.instance_key,
  subject_type: 'fixture.workflow-business.receipt',
  subject_key: '9007199254740997',
  status: 'running',
  result_code: null,
  revision: 5,
  active_nodes: [
    {
      node_execution_key: task.node_execution_key,
      label: '审批',
      task_termination_evidence_required: false,
      status: 'active',
      revision: 4,
    },
  ],
  own_work_items: [task],
  reassign_work_items: [
    { work_item_key: '9007199254740998', label: '待修复席位', revision: 6 },
  ],
  available_actions: ['withdraw', 'terminate', 'retry', 'reassign', 'resubmit'],
  event_cursor: 2,
  blocker: null,
  subject_revision_key: 'revision-A',
};

test('transport uses exact frozen request shapes, revision checks, bigint IDs and idempotency headers', async () => {
  const calls = [];
  // Exercise the current native V1 transport; only HTTP is a synthetic fixture.
  const transport = createWorkflowTransport({
    baseUrl: 'https://workflow.example.test/adminapi',
    session: () => ({ generation: 1, token: 'synthetic-test-token' }),
    fetch: async (request) => {
      calls.push({
        url: new URL(request.url).pathname,
        body: await request.json(),
        key: request.headers.get('Idempotency-Key'),
      });
      return Response.json({ data: instance, meta: {} });
    },
  });
  try {
    const api = createWorkflowApi(transport);
    const revisions = {
      node_execution_key: task.node_execution_key,
      expected_revision: 5,
      expected_node_revision: 4,
      expected_task_revision: 3,
    };
    const received = await api.taskAction(
      task,
      instance,
      'claim',
      '',
      'claim-key'
    );
    assert.deepEqual(
      received.reassign_work_items,
      instance.reassign_work_items
    );
    assert.equal(
      received.active_nodes[0].task_termination_evidence_required,
      false
    );
    assert.deepEqual(calls.at(-1), {
      url: `/adminapi/api/v1/workflow/tasks/${task.work_item_key}/claim`,
      body: revisions,
      key: 'claim-key',
    });
    await api.taskAction(task, instance, 'release', '', 'release-key');
    assert.deepEqual(calls.at(-1).body, revisions);
    await api.taskAction(task, instance, 'reject', '理由', 'decision-key');
    assert.deepEqual(calls.at(-1).body, {
      ...revisions,
      decision: 'reject',
      comment: '理由',
      attachment_file_keys: [],
    });
    assert.ok(calls.at(-1).url.endsWith('/decisions'));
    await api.taskAction(
      task,
      instance,
      'complete',
      '办理完成',
      'complete-key',
      { input: {} }
    );
    assert.deepEqual(calls.at(-1).body, {
      ...revisions,
      comment: '办理完成',
      attachment_file_keys: [],
      input: {},
    });
    assert.ok(calls.at(-1).url.endsWith('/completions'));
    await api.taskAction(task, instance, 'return', '补正', 'return-key');
    assert.deepEqual(calls.at(-1).body, {
      ...revisions,
      comment: '补正',
      attachment_file_keys: [],
    });
    await api.instanceAction(instance, 'retry', '修复已完成', 'retry-key');
    assert.deepEqual(calls.at(-1).body, {
      expected_revision: 5,
      comment: '修复已完成',
    });
    await api.instanceAction(
      instance,
      'reassign',
      '替代原责任席位',
      'reassign-key',
      {
        work_item_key: task.work_item_key,
        assignee_member_id: '9007199254740999',
        expected_task_revision: 3,
      }
    );
    assert.equal(calls.at(-1).body.assignee_member_id, '9007199254740999');
    assert.equal('reason' in calls.at(-1).body, false);
    await assert.rejects(
      api.taskAction(
        { ...task, available_actions: [] },
        instance,
        'approve',
        '',
        'denied'
      ),
      /unavailable/
    );
    assert.equal(calls.length, 7);
  } finally {
    transport.dispose();
  }
});
