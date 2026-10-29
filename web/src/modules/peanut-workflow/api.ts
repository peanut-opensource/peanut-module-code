import type { WorkflowTransport } from './transport';
import type {
  EditorDocument,
  InboxFilter,
  InboxWorkItem,
  PageResult,
  ValidationIssue,
  WorkItem,
  WorkflowDefinition,
  WorkflowEvent,
  WorkflowAttachment,
  WorkflowInstance,
  WorkflowNode,
  WorkflowScenario,
  WorkflowDefinitionSummary,
  WorkflowTaskDiagnostics,
} from './types';

export interface SaveReceipt {
  definition_id: string;
  draft_revision: number;
  issues: ValidationIssue[];
}
export interface PreviewReceipt {
  issues: ValidationIssue[];
  summary: string;
  participants: string[];
}
export interface PreviewBasis {
  initiator_member_id: string;
  business_department_key: string | null;
  previous_actor_member_id: string | null;
  subject_type: string;
  subject_key: string;
}
export interface StartInput {
  module_key: string;
  workflow_key: string;
  subject_type: string;
  subject_key: string;
  subject_revision_key: string;
  expected_revision: number;
  business_department_key: string | null;
  attachment_file_keys: string[];
}
export interface InstanceActionDetails {
  expected_job_revision?: number;
  work_item_key?: string;
  assignee_member_id?: string;
  expected_task_revision?: number;
}
export interface TaskActionDetails {
  attachment_file_keys?: string[];
  input?: Record<string, unknown>;
  subject_revision_key?: string;
}
export interface WorkflowNotificationDiagnostic {
  sequence_no: number;
  status: string;
  last_error: string | null;
  available_actions: string[];
  outboxes: {
    outbox_key: string;
    delivery_status: string;
    available_actions: string[];
    task: {
      job_key: string;
      status: string;
      revision: number;
      last_error_code: string | null;
    } | null;
  }[];
}

export interface WorkflowApi {
  diagnostics(
    instanceKey: string,
    signal?: AbortSignal
  ): Promise<WorkflowTaskDiagnostics>;
  reconcileTask(
    instance: WorkflowInstance,
    node: WorkflowTaskDiagnostics['items'][number],
    comment: string,
    key: string
  ): Promise<WorkflowInstance>;
  recoverTask(
    instance: WorkflowInstance,
    node: WorkflowTaskDiagnostics['items'][number],
    strategy: 'retry_original' | 'reauthorize',
    comment: string,
    key: string
  ): Promise<WorkflowInstance>;
  definitionSummary(
    moduleKey: string,
    workflowKey: string,
    subjectType: string,
    subjectKey: string,
    signal?: AbortSignal
  ): Promise<WorkflowDefinitionSummary>;
  scenario(
    moduleKey: string,
    workflowKey: string,
    signal?: AbortSignal
  ): Promise<WorkflowScenario>;
  activate(
    moduleKey: string,
    workflowKey: string,
    revision: number,
    version: number,
    key: string
  ): Promise<void>;
  retire(
    moduleKey: string,
    workflowKey: string,
    revision: number,
    key: string
  ): Promise<void>;
  dispose(): void;
  definition(
    moduleKey: string,
    workflowKey: string,
    signal?: AbortSignal
  ): Promise<WorkflowDefinition>;
  save(
    moduleKey: string,
    workflowKey: string,
    document: EditorDocument,
    revision: number | null,
    idempotencyKey: string
  ): Promise<SaveReceipt>;
  validate(
    moduleKey: string,
    workflowKey: string,
    document: EditorDocument
  ): Promise<ValidationIssue[]>;
  preview(
    moduleKey: string,
    workflowKey: string,
    document: EditorDocument,
    basis: PreviewBasis
  ): Promise<PreviewReceipt>;
  publish(
    moduleKey: string,
    workflowKey: string,
    revision: number,
    idempotencyKey: string
  ): Promise<void>;
  inbox(
    filter: InboxFilter,
    page: number,
    signal?: AbortSignal
  ): Promise<PageResult<InboxWorkItem | WorkflowInstance>>;
  instance(key: string, signal?: AbortSignal): Promise<WorkflowInstance>;
  start(input: StartInput, idempotencyKey: string): Promise<WorkflowInstance>;
  events(
    key: string,
    after: string,
    signal?: AbortSignal
  ): Promise<WorkflowEvent[]>;
  taskAction(
    task: WorkItem,
    instance: WorkflowInstance,
    action: string,
    comment: string,
    idempotencyKey: string,
    details?: TaskActionDetails
  ): Promise<WorkflowInstance>;
  instanceAction(
    instance: WorkflowInstance,
    action: string,
    reason: string,
    idempotencyKey: string,
    details?: InstanceActionDetails
  ): Promise<WorkflowInstance>;
}

export function record(value: unknown, name: string): Record<string, unknown> {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new Error(`Invalid ${name} response`);
  return value as Record<string, unknown>;
}
export function textField(value: unknown, name: string): string {
  if (typeof value !== 'string') throw new Error(`Invalid ${name}`);
  return value;
}
export function numberField(value: unknown, name: string): number {
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 0)
    throw new Error(`Invalid ${name}`);
  return value;
}
export function strings(value: unknown, name: string): string[] {
  if (!Array.isArray(value)) throw new Error(`Invalid ${name}`);
  return value.map((item) => textField(item, name));
}

function parseAttachment(value: unknown): WorkflowAttachment {
  const row = record(value, 'attachment');
  const attachment = {
    file_key: textField(row.file_key, 'attachment.file_key'),
    name: textField(row.name, 'attachment.name'),
    media_type: textField(row.media_type, 'attachment.media_type'),
    size_bytes: numberField(row.size_bytes, 'attachment.size_bytes'),
    sha256: textField(row.sha256, 'attachment.sha256'),
  };
  if (
    !/^file_[0-9a-f]{32}$/.test(attachment.file_key) ||
    !/^[0-9a-f]{64}$/.test(attachment.sha256) ||
    attachment.name.length === 0 ||
    [...attachment.name].length > 255 ||
    attachment.name.includes('\u0000') ||
    !/^[\x21-\x7e]{1,127}$/.test(attachment.media_type)
  )
    throw new Error('Invalid historical attachment');
  return attachment;
}

/** A historical label never supplies a File grant. Do not retain the returned URL. */
export async function resolveWorkflowAttachment(
  attachment: WorkflowAttachment,
  operation: 'preview' | 'download_original' | 'manage',
  token: string | null,
  signal: AbortSignal
): Promise<Record<string, unknown>> {
  const response = await fetch('/adminapi/api/v1/files/resolve', {
    method: 'POST',
    credentials: 'same-origin',
    signal,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({ file_key: attachment.file_key, operation }),
  });
  const envelope = record(await response.json(), 'File access');
  if (!response.ok || envelope.code !== 20000)
    throw new Error('当前文件权限不可用');
  const data = record(envelope.data, 'File access data');
  if (data.file_key !== attachment.file_key) throw new Error('文件身份已改变');
  if (operation === 'manage') {
    if (data.can_manage !== true) throw new Error('当前身份无文件管理权限');
    return data;
  }
  if (data.state !== 'available') throw new Error('当前文件暂无受控访问能力');
  if (data.source_sha256 !== attachment.sha256)
    throw new Error('当前文件字节与历史附件不一致，不能作为历史原件访问');
  if (
    data.purpose !== operation ||
    (operation === 'download_original' && data.can_download_original !== true)
  )
    throw new Error('当前文件权限不可用');
  return data;
}
function nullableText(value: unknown, name: string): string | null {
  return value === null ? null : textField(value, name);
}
function list<T>(
  value: unknown,
  parse: (item: unknown) => T,
  name: string
): T[] {
  if (!Array.isArray(value)) throw new Error(`Invalid ${name}`);
  return value.map(parse);
}
export function parseIssues(value: unknown): ValidationIssue[] {
  return list(
    value,
    (item) => {
      const row = record(item, 'issue');
      let issueField: string | undefined;
      if (row.field !== undefined) issueField = textField(row.field, 'field');
      else if (row.path !== undefined) issueField = textField(row.path, 'path');
      return {
        code: textField(row.code, 'issue.code'),
        message: textField(row.message, 'issue.message'),
        node_key:
          row.node_key === undefined
            ? undefined
            : textField(row.node_key, 'node_key'),
        field: issueField,
      };
    },
    'issues'
  );
}
export function parseScenario(value: unknown): WorkflowScenario {
  const row = record(value, 'scenario');
  const fields = record(row.fields, 'fields');
  const parsed: WorkflowScenario['fields'] = {};
  Object.entries(fields).forEach(([key, fieldValue]) => {
    const field = record(fieldValue, 'field');
    const { type } = field;
    if (
      type !== 'boolean' &&
      type !== 'integer' &&
      type !== 'string' &&
      type !== 'enum' &&
      type !== 'datetime' &&
      type !== 'decimal'
    )
      throw new Error('CAPABILITY_UNSUPPORTED: scenario field');
    if (typeof field.nullable !== 'boolean')
      throw new Error('Invalid field nullable');
    parsed[key] = {
      type,
      nullable: field.nullable,
      ...(field.values === undefined
        ? {}
        : { values: strings(field.values, 'field values') }),
    };
  });
  return {
    scenario_contract_version: numberField(
      row.scenario_contract_version,
      'scenario_contract_version'
    ),
    fields: parsed,
    result_codes: strings(row.result_codes, 'result_codes'),
    capabilities: strings(row.capabilities, 'capabilities'),
    actions: list(
      row.actions,
      (item) => {
        const action = record(item, 'action');
        return {
          action_key: textField(action.action_key, 'action_key'),
          contract_version: numberField(
            action.contract_version,
            'contract_version'
          ),
          input_schema: record(action.input_schema, 'input_schema'),
        };
      },
      'actions'
    ),
  };
}
function parseInboxTask(value: unknown): InboxWorkItem {
  const row = record(value, 'work item');
  if (
    row.status !== 'pending' &&
    row.status !== 'completed' &&
    row.status !== 'cancelled'
  )
    throw new Error('Invalid task status');
  if (
    row.assignment_state !== 'assigned' &&
    row.assignment_state !== 'unassigned'
  )
    throw new Error('Invalid assignment state');
  return {
    work_item_key: textField(row.work_item_key, 'work_item_key'),
    node_execution_key: textField(row.node_execution_key, 'node_execution_key'),
    instance_key: textField(row.instance_key, 'instance_key'),
    label: textField(row.label, 'label'),
    type: textField(row.type, 'type'),
    revision: numberField(row.revision, 'revision'),
    instance_revision: numberField(row.instance_revision, 'instance_revision'),
    node_revision: numberField(row.node_revision, 'node_revision'),
    status: row.status,
    assignment_state: row.assignment_state,
    owner_member_id: nullableText(row.owner_member_id, 'owner_member_id'),
    assignee_member_id: nullableText(
      row.assignee_member_id,
      'assignee_member_id'
    ),
    relations: strings(row.relations, 'relations'),
    decision: nullableText(row.decision, 'decision'),
  };
}
export function parseTask(value: unknown): WorkItem {
  const row = record(value, 'work item');
  return {
    ...parseInboxTask(row),
    available_actions: strings(row.available_actions, 'available_actions'),
  };
}
function parseBlocker(value: unknown): WorkflowInstance['blocker'] {
  const row = record(value, 'blocker');
  return {
    code: textField(row.code, 'blocker.code'),
    message: textField(row.message, 'blocker.message'),
    node_execution_key: nullableText(
      row.node_execution_key,
      'blocker.node_execution_key'
    ),
  };
}

export function parseInstance(value: unknown): WorkflowInstance {
  const row = record(value, 'instance');
  return {
    instance_key: textField(row.instance_key, 'instance_key'),
    subject_type: textField(row.subject_type, 'subject_type'),
    subject_key: textField(row.subject_key, 'subject_key'),
    status: textField(row.status, 'status'),
    result_code: nullableText(row.result_code, 'result_code'),
    revision: numberField(row.revision, 'revision'),
    active_nodes: list(
      row.active_nodes,
      (item) => {
        const node = record(item, 'active node');
        if (typeof node.task_termination_evidence_required !== 'boolean')
          throw new Error('Invalid task_termination_evidence_required');
        return {
          node_execution_key: textField(
            node.node_execution_key,
            'node_execution_key'
          ),
          label: textField(node.label, 'label'),
          task_termination_evidence_required:
            node.task_termination_evidence_required,
          status: textField(node.status, 'status'),
          revision: numberField(node.revision, 'revision'),
        };
      },
      'active_nodes'
    ),
    own_work_items: list(row.own_work_items, parseTask, 'own_work_items'),
    reassign_work_items: list(
      row.reassign_work_items,
      (item) => {
        const task = record(item, 'reassign work item');
        return {
          work_item_key: textField(task.work_item_key, 'work_item_key'),
          label: textField(task.label, 'label'),
          revision: numberField(task.revision, 'revision'),
        };
      },
      'reassign_work_items'
    ),
    available_actions: strings(row.available_actions, 'available_actions'),
    event_cursor: numberField(row.event_cursor, 'event_cursor'),
    blocker: row.blocker === null ? null : parseBlocker(row.blocker),
    subject_revision_key: textField(
      row.subject_revision_key,
      'subject_revision_key'
    ),
  };
}

export function readGraph(value: unknown): EditorDocument['graph'] {
  const row = record(value, 'graph');
  if (row.contract_version !== 2)
    throw new Error('CAPABILITY_UNSUPPORTED: unsupported graph contract');
  const nodes = list<WorkflowNode>(
    row.nodes,
    (item) => {
      const node = record(item, 'node');
      const { type } = node;
      if (
        type !== 'start' &&
        type !== 'approval' &&
        type !== 'human_task' &&
        type !== 'exclusive_gateway' &&
        type !== 'service_task' &&
        type !== 'end'
      )
        throw new Error('CAPABILITY_UNSUPPORTED: unsupported node');
      const config = record(node.config, 'config');
      if (type === 'approval' || type === 'human_task') {
        if (
          !['direct', 'claim', 'multi'].includes(
            textField(config.allocation_mode, 'allocation_mode')
          )
        )
          throw new Error('CAPABILITY_UNSUPPORTED: allocation mode');
        if (
          config.aggregation_policy !== null &&
          config.aggregation_policy !== 'all_approve' &&
          config.aggregation_policy !== 'any_approve'
        )
          throw new Error('CAPABILITY_UNSUPPORTED: aggregation policy');
        if (
          typeof config.allow_self_approval !== 'boolean' ||
          typeof config.opinion_required !== 'boolean'
        )
          throw new Error('Invalid human task flags');
        ['assignments', 'participant_rules', 'watcher_rules'].forEach(
          (field) => {
            list(
              config[field],
              (entry) => {
                const rule = record(entry, 'assignment');
                const kind = textField(rule.kind, 'kind');
                if (
                  ![
                    'member',
                    'role_members',
                    'department_members',
                    'department_leaders',
                    'subject_owner',
                    'initiator',
                    'previous_actor',
                  ].includes(kind)
                )
                  throw new Error('CAPABILITY_UNSUPPORTED: assignment kind');
                if (
                  ['initiator', 'subject_owner', 'previous_actor'].includes(
                    kind
                  )
                )
                  nullableText(rule.key, 'key');
                else textField(rule.key, 'key');
                nullableText(rule.department_scope, 'department_scope');
                if (typeof rule.required !== 'boolean')
                  throw new Error('Invalid assignment required');
                return rule;
              },
              field
            );
          }
        );
      }
      return {
        key: textField(node.key, 'key'),
        label: textField(node.label, 'label'),
        type,
        config,
      };
    },
    'nodes'
  );
  const transitions = list(
    row.transitions,
    (item) => {
      const edge = record(item, 'transition');
      if (typeof edge.return_edge !== 'boolean')
        throw new Error('Invalid return_edge');
      return {
        key: textField(edge.key, 'key'),
        from: textField(edge.from, 'from'),
        to: textField(edge.to, 'to'),
        action: textField(edge.action, 'action'),
        operation: nullableText(edge.operation, 'operation'),
        permission_keys: strings(edge.permission_keys, 'permission_keys'),
        return_edge: edge.return_edge,
        max_traversals:
          edge.max_traversals === null
            ? null
            : numberField(edge.max_traversals, 'max_traversals'),
      };
    },
    'transitions'
  );
  return {
    contract_version: 2,
    scenario_contract_version: numberField(
      row.scenario_contract_version,
      'scenario_contract_version'
    ),
    subject_resource_key: textField(
      row.subject_resource_key,
      'subject_resource_key'
    ),
    subject_read_operation: textField(
      row.subject_read_operation,
      'subject_read_operation'
    ),
    subject_start_operation: textField(
      row.subject_start_operation,
      'subject_start_operation'
    ),
    start_permission_keys: strings(
      row.start_permission_keys,
      'start_permission_keys'
    ),
    required_capabilities: strings(
      row.required_capabilities,
      'required_capabilities'
    ),
    nodes,
    transitions,
  };
}
export function readLayout(value: unknown): EditorDocument['layout'] {
  const row = record(value, 'layout');
  const finite = (input: unknown): number => {
    if (typeof input !== 'number' || !Number.isFinite(input))
      throw new Error('Invalid layout coordinate');
    return input;
  };
  const nodes = list(
    row.nodes,
    (item) => {
      const node = record(item, 'layout node');
      return {
        key: textField(node.key, 'key'),
        x: finite(node.x),
        y: finite(node.y),
      };
    },
    'layout.nodes'
  );
  if (row.viewport === null) return { nodes, viewport: null };
  const viewport = record(row.viewport, 'viewport');
  return {
    nodes,
    viewport: {
      x: finite(viewport.x),
      y: finite(viewport.y),
      zoom: finite(viewport.zoom),
    },
  };
}

function parseDefinition(value: unknown): WorkflowDefinition {
  const row = record(value, 'definition');
  // Graph/layout are validated separately before they enter editor state.
  const graph = readGraph(row.graph);
  const layout = readLayout(row.layout);
  return {
    definition_id: textField(row.definition_id, 'definition_id'),
    module_key: textField(row.module_key, 'module_key'),
    workflow_key: textField(row.workflow_key, 'workflow_key'),
    draft_revision: numberField(row.draft_revision, 'draft_revision'),
    graph,
    layout,
    status: textField(row.status, 'status'),
    latest_version: numberField(row.latest_version, 'latest_version'),
    active_version:
      row.active_version === null
        ? null
        : numberField(row.active_version, 'active_version'),
    versions: list(
      row.versions,
      (versionValue) => {
        const item = record(versionValue, 'version');
        return {
          version: numberField(item.version, 'version'),
          graph_sha256: textField(item.graph_sha256, 'graph_sha256'),
          published_at: textField(item.published_at, 'published_at'),
        };
      },
      'versions'
    ),
  };
}

export function createWorkflowApi(transport: WorkflowTransport): WorkflowApi {
  const base = () => '';
  const path = (moduleKey: string, workflowKey: string) =>
    `${base()}/definitions/${encodeURIComponent(
      moduleKey
    )}/${encodeURIComponent(workflowKey)}`;
  const getEnvelope = (url: string, signal?: AbortSignal) =>
    transport.request(url, 'GET', undefined, undefined, signal);
  const get = async (url: string, signal?: AbortSignal) =>
    (await getEnvelope(url, signal)).data;
  const write = async (
    method: 'post' | 'put',
    url: string,
    data: unknown,
    key: string
  ) =>
    (
      await transport.request(
        url,
        method === 'post' ? 'POST' : 'PUT',
        data,
        key
      )
    ).data;
  return {
    dispose: () => transport.dispose(),
    async diagnostics(instanceKey, signal) {
      const row = record(
        await get(
          `${base()}/instances/${encodeURIComponent(instanceKey)}/diagnostics`,
          signal
        ),
        'diagnostics'
      );
      return {
        instance_key: textField(row.instance_key, 'instance_key'),
        revision: numberField(row.revision, 'revision'),
        items: list(
          row.items,
          (value) => {
            const node = record(value, 'diagnostic node');
            const job = record(node.job, 'diagnostic job');
            return {
              node_execution_key: textField(
                node.node_execution_key,
                'node_execution_key'
              ),
              node_revision: numberField(node.node_revision, 'node_revision'),
              status: textField(node.status, 'status'),
              job: {
                job_key: textField(job.job_key, 'job_key'),
                status: textField(job.status, 'job.status'),
                revision: numberField(job.revision, 'job.revision'),
                last_error_code: nullableText(
                  job.last_error_code,
                  'last_error_code'
                ),
              },
              available_actions: strings(
                node.available_actions,
                'available_actions'
              ),
            };
          },
          'diagnostic items'
        ),
      };
    },
    async reconcileTask(instance, node, comment, key) {
      return parseInstance(
        await write(
          'post',
          `${base()}/instances/${encodeURIComponent(
            instance.instance_key
          )}/reconcile`,
          {
            expected_revision: instance.revision,
            node_execution_key: node.node_execution_key,
            expected_node_revision: node.node_revision,
            expected_job_revision: node.job.revision,
            comment,
          },
          key
        )
      );
    },
    async recoverTask(instance, node, strategy, comment, key) {
      return parseInstance(
        await write(
          'post',
          `${base()}/instances/${encodeURIComponent(
            instance.instance_key
          )}/recover`,
          {
            expected_revision: instance.revision,
            node_execution_key: node.node_execution_key,
            expected_node_revision: node.node_revision,
            expected_job_revision: node.job.revision,
            strategy,
            comment,
          },
          key
        )
      );
    },
    async definitionSummary(
      moduleKey,
      workflowKey,
      subjectType,
      subjectKey,
      signal
    ) {
      const row = record(
        await get(
          `${path(
            moduleKey,
            workflowKey
          )}/summary?subject_type=${encodeURIComponent(
            subjectType
          )}&subject_key=${encodeURIComponent(subjectKey)}`,
          signal
        ),
        'definition summary'
      );
      return {
        definition_id: textField(row.definition_id, 'definition_id'),
        module_key: textField(row.module_key, 'module_key'),
        workflow_key: textField(row.workflow_key, 'workflow_key'),
        status: textField(row.status, 'status'),
        draft_revision: numberField(row.draft_revision, 'draft_revision'),
        active_version:
          row.active_version === null
            ? null
            : numberField(row.active_version, 'active_version'),
      };
    },
    async scenario(moduleKey, workflowKey, signal) {
      return parseScenario(
        await get(`${path(moduleKey, workflowKey)}/scenario`, signal)
      );
    },
    async activate(moduleKey, workflowKey, revision, version, key) {
      await write(
        'post',
        `${path(moduleKey, workflowKey)}/activate`,
        { expected_revision: revision, version },
        key
      );
    },
    async retire(moduleKey, workflowKey, revision, key) {
      await write(
        'post',
        `${path(moduleKey, workflowKey)}/retire`,
        { expected_revision: revision },
        key
      );
    },
    async definition(moduleKey, workflowKey, signal) {
      return parseDefinition(await get(path(moduleKey, workflowKey), signal));
    },
    async save(moduleKey, workflowKey, document, revision, key) {
      const row = record(
        await write(
          'put',
          `${path(moduleKey, workflowKey)}/draft`,
          { expected_revision: revision, ...document },
          key
        ),
        'save'
      );
      return {
        definition_id: textField(row.definition_id, 'definition_id'),
        draft_revision: numberField(row.draft_revision, 'draft_revision'),
        issues: parseIssues(row.issues),
      };
    },
    async validate(moduleKey, workflowKey, document) {
      const row = record(
        await write(
          'post',
          '/definitions/validate',
          { graph: document.graph },
          crypto.randomUUID()
        ),
        'validation'
      );
      return parseIssues(row.issues);
    },
    async preview(moduleKey, workflowKey, document, basis) {
      const row = record(
        await write(
          'post',
          '/definitions/preview',
          { graph: document.graph, basis },
          crypto.randomUUID()
        ),
        'preview'
      );
      if (row.simulated !== true) throw new Error('Invalid simulation receipt');
      const participants = list(
        row.nodes,
        (item) => {
          const node = record(item, 'preview node');
          const key = textField(node.node_key, 'node_key');
          return list(
            node.members,
            (entry) => {
              const member = record(entry, 'preview member');
              return `${key}: ${textField(
                member.member_id,
                'member_id'
              )} (${textField(member.source_kind, 'source_kind')})`;
            },
            'members'
          );
        },
        'preview nodes'
      ).flat();
      return {
        issues: parseIssues(row.issues),
        summary: '仅为规则与人员解析模拟，未执行业务动作。',
        participants,
      };
    },
    async publish(moduleKey, workflowKey, revision, key) {
      await write(
        'post',
        `${path(moduleKey, workflowKey)}/publish`,
        { expected_revision: revision },
        key
      );
    },
    async inbox(filter, page, signal) {
      const envelope = await getEnvelope(
        `/inbox?filter=${filter}&page=${page}&page_size=20`,
        signal
      );
      const data = record(envelope.data, 'inbox');
      const { meta } = envelope;
      return {
        lists: list<InboxWorkItem | WorkflowInstance>(
          data.items,
          filter === 'initiated' ? parseInstance : parseInboxTask,
          'items'
        ),
        count: numberField(meta.total, 'total'),
        pageNo: numberField(meta.page, 'page'),
        pageSize: numberField(meta.page_size, 'page_size'),
      };
    },
    async instance(key, signal) {
      return parseInstance(
        await get(`${base()}/instances/${encodeURIComponent(key)}`, signal)
      );
    },
    async start(input, key) {
      return parseInstance(
        await write('post', `${base()}/instances`, input, key)
      );
    },
    async events(key, after, signal) {
      const data = record(
        await get(
          `${base()}/instances/${encodeURIComponent(
            key
          )}/events?after_sequence=${encodeURIComponent(after)}&page_size=20`,
          signal
        ),
        'events'
      );
      return list(
        data.items,
        (item) => {
          const row = record(item, 'event');
          return {
            sequence_no: numberField(row.sequence_no, 'sequence_no'),
            event_key: textField(row.event_key, 'event_key'),
            node_execution_key: nullableText(
              row.node_execution_key,
              'node_execution_key'
            ),
            actor_member_id: nullableText(
              row.actor_member_id,
              'actor_member_id'
            ),
            comment: nullableText(row.comment, 'comment'),
            metadata: record(row.metadata, 'metadata'),
            attachments: list(row.attachments, parseAttachment, 'attachments'),
            occurred_at: textField(row.occurred_at, 'occurred_at'),
          };
        },
        'events'
      );
    },
    async taskAction(task, instance, action, comment, key, details) {
      if (!task.available_actions.includes(action))
        throw new Error('Action is unavailable');
      const node = instance.active_nodes.find(
        (entry) => entry.node_execution_key === task.node_execution_key
      );
      if (!node) throw new Error('Current node execution is unavailable');
      if (
        ![
          'claim',
          'release',
          'approve',
          'reject',
          'complete',
          'return',
          'resubmit',
        ].includes(action)
      )
        throw new Error('Unsupported work item action');
      if (action === 'complete' && !details?.input)
        throw new Error('Completion requires a business form provider');
      if (action === 'resubmit' && !details?.subject_revision_key)
        throw new Error('Resubmission requires a confirmed subject revision');
      const decision = action === 'approve' || action === 'reject';
      let resource: string = action;
      if (decision) resource = 'decisions';
      else if (action === 'complete') resource = 'completions';
      let actionBody: Record<string, unknown> = {};
      if (decision) {
        actionBody = {
          decision: action,
          comment: comment || null,
          attachment_file_keys: details?.attachment_file_keys ?? [],
        };
      } else if (action !== 'claim' && action !== 'release') {
        actionBody = {
          comment: comment || null,
          attachment_file_keys: details?.attachment_file_keys ?? [],
          ...(action === 'complete' ? { input: details?.input } : {}),
          ...(action === 'resubmit'
            ? { subject_revision_key: details?.subject_revision_key }
            : {}),
        };
      }
      return parseInstance(
        await write(
          'post',
          `${base()}/tasks/${encodeURIComponent(
            task.work_item_key
          )}/${resource}`,
          {
            node_execution_key: task.node_execution_key,
            expected_revision: instance.revision,
            expected_node_revision: node.revision,
            expected_task_revision: task.revision,
            ...actionBody,
          },
          key
        )
      );
    },
    async instanceAction(instance, action, reason, key, details) {
      if (!['withdraw', 'terminate', 'retry', 'reassign'].includes(action))
        throw new Error('Unsupported instance action');
      if (!instance.available_actions.includes(action))
        throw new Error('Action is unavailable');
      return parseInstance(
        await write(
          'post',
          `${base()}/instances/${encodeURIComponent(
            instance.instance_key
          )}/${action}`,
          {
            expected_revision: instance.revision,
            comment: reason,
            ...(action === 'terminate' &&
            details?.expected_job_revision !== undefined
              ? { expected_job_revision: details.expected_job_revision }
              : {}),
            ...(action === 'reassign'
              ? {
                  work_item_key: details?.work_item_key,
                  assignee_member_id: details?.assignee_member_id,
                  expected_task_revision: details?.expected_task_revision,
                }
              : {}),
          },
          key
        )
      );
    },
  };
}
