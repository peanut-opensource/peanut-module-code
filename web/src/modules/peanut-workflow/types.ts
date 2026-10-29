export type NodeType =
  | 'start'
  | 'approval'
  | 'human_task'
  | 'exclusive_gateway'
  | 'service_task'
  | 'end';
export type AllocationMode = 'direct' | 'claim' | 'multi';
export type AggregationPolicy = 'all_approve' | 'any_approve' | null;
export type AssignmentKind =
  | 'member'
  | 'role_members'
  | 'department_members'
  | 'department_leaders'
  | 'subject_owner'
  | 'initiator'
  | 'previous_actor';
export interface AssignmentRule {
  kind: AssignmentKind;
  key: string | null;
  required: boolean;
  department_scope: string | null;
}
export interface HumanConfig {
  allocation_mode: AllocationMode | string;
  aggregation_policy: AggregationPolicy | string;
  assignments: AssignmentRule[];
  participant_rules: AssignmentRule[];
  watcher_rules: AssignmentRule[];
  allow_self_approval: boolean;
  opinion_required: boolean;
}
export interface WorkflowNode {
  key: string;
  label: string;
  type: NodeType;
  config: Record<string, unknown>;
}
export interface WorkflowTransition {
  key: string;
  from: string;
  to: string;
  action: string;
  operation: string | null;
  permission_keys: string[];
  return_edge: boolean;
  max_traversals: number | null;
}
export interface WorkflowGraph {
  contract_version: 2;
  subject_resource_key: string;
  subject_read_operation: string;
  subject_start_operation: string;
  start_permission_keys: string[];
  scenario_contract_version: number;
  required_capabilities: string[];
  nodes: WorkflowNode[];
  transitions: WorkflowTransition[];
}
export interface WorkflowLayout {
  nodes: { key: string; x: number; y: number }[];
  viewport: { x: number; y: number; zoom: number } | null;
}
export interface EditorDocument {
  graph: WorkflowGraph;
  layout: WorkflowLayout;
}
export interface ValidationIssue {
  code: string;
  message: string;
  node_key?: string;
  field?: string;
}
export interface WorkflowDefinition {
  definition_id: string;
  module_key: string;
  workflow_key: string;
  draft_revision: number;
  graph: WorkflowGraph;
  layout: WorkflowLayout;
  status: string;
  latest_version: number;
  active_version: number | null;
  versions: { version: number; graph_sha256: string; published_at: string }[];
}
export interface WorkflowDefinitionSummary {
  definition_id: string;
  module_key: string;
  workflow_key: string;
  status: string;
  draft_revision: number;
  active_version: number | null;
}
export interface ScenarioField {
  type: 'boolean' | 'integer' | 'string' | 'enum' | 'datetime' | 'decimal';
  nullable: boolean;
  values?: string[];
}
export interface WorkflowScenario {
  scenario_contract_version: number;
  fields: Record<string, ScenarioField>;
  result_codes: string[];
  capabilities: string[];
  actions: {
    action_key: string;
    contract_version: number;
    input_schema: Record<string, unknown>;
  }[];
}
export type WorkflowCondition =
  | { all: WorkflowCondition[] }
  | { any: WorkflowCondition[] }
  | { field: string; operator: string; value: unknown };
export type ActionBinding =
  | { source: 'literal'; value: unknown }
  | { source: 'instance'; field: string }
  | { source: 'node_input'; field: string };
export interface WorkItem {
  work_item_key: string;
  node_execution_key: string;
  instance_key: string;
  label: string;
  type: string;
  revision: number;
  instance_revision: number;
  node_revision: number;
  status: 'pending' | 'completed' | 'cancelled';
  assignment_state: 'unassigned' | 'assigned';
  owner_member_id: string | null;
  assignee_member_id: string | null;
  relations: string[];
  available_actions: string[];
  decision: string | null;
}
/** Read-visible list projection; fetch instance detail before selecting an action. */
export type InboxWorkItem = Omit<WorkItem, 'available_actions'>;
export interface WorkflowInstance {
  instance_key: string;
  subject_type: string;
  subject_key: string;
  status: string;
  result_code: string | null;
  revision: number;
  active_nodes: {
    node_execution_key: string;
    label: string;
    task_termination_evidence_required: boolean;
    status: string;
    revision: number;
  }[];
  own_work_items: WorkItem[];
  reassign_work_items: Pick<WorkItem, 'work_item_key' | 'label' | 'revision'>[];
  available_actions: string[];
  event_cursor: number;
  blocker: {
    code: string;
    message: string;
    node_execution_key: string | null;
  } | null;
  subject_revision_key: string;
}
export interface WorkflowAttachment {
  file_key: string;
  name: string;
  media_type: string;
  size_bytes: number;
  sha256: string;
}

export interface WorkflowEvent {
  sequence_no: number;
  event_key: string;
  node_execution_key: string | null;
  actor_member_id: string | null;
  comment: string | null;
  metadata: Record<string, unknown>;
  attachments: WorkflowAttachment[];
  occurred_at: string;
}
export interface WorkflowTaskDiagnostics {
  instance_key: string;
  revision: number;
  items: {
    node_execution_key: string;
    node_revision: number;
    status: string;
    job: {
      job_key: string;
      status: string;
      revision: number;
      last_error_code: string | null;
    };
    available_actions: string[];
  }[];
}
export interface PageResult<T> {
  lists: T[];
  count: number;
  pageNo: number;
  pageSize: number;
}
export type InboxFilter =
  | 'assigned'
  | 'claimable'
  | 'completed'
  | 'initiated'
  | 'participant'
  | 'watcher';
export interface WriteIdentity {
  expected_revision: number;
  idempotency_key: string;
}

export const ALLOCATION_LABELS: Record<AllocationMode, string> = {
  direct: '单人直派（必须恰好一人）',
  claim: '候选池领取（一个共享任务）',
  multi: '多人表决（每人一个工作项）',
};
export const AGGREGATION_LABELS = {
  all_approve: '全员同意才通过；任一拒绝即不通过',
  any_approve: '任一同意即可（拒绝继续收集）；全部拒绝才不通过',
};
export const ASSIGNMENT_LABELS: Record<AssignmentKind, string> = {
  member: '指定成员',
  role_members: '角色成员',
  department_members: '部门直接成员',
  department_leaders: '部门负责人账号',
  subject_owner: '业务负责人',
  initiator: '发起人',
  previous_actor: '前序单人实际处理人',
};
