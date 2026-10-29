import type {
  EditorDocument,
  HumanConfig,
  NodeType,
  ValidationIssue,
  WorkflowGraph,
  WorkflowNode,
} from '../types';

export function cloneDocument(document: EditorDocument): EditorDocument {
  return JSON.parse(JSON.stringify(document));
}

export function humanConfig(node: WorkflowNode): HumanConfig {
  const config = node.config;
  return {
    allocation_mode:
      typeof config.allocation_mode === 'string'
        ? config.allocation_mode
        : 'unsupported',
    aggregation_policy:
      config.aggregation_policy === null
        ? null
        : typeof config.aggregation_policy === 'string'
        ? config.aggregation_policy
        : 'unsupported',
    assignments: Array.isArray(config.assignments) ? config.assignments : [],
    participant_rules: Array.isArray(config.participant_rules)
      ? config.participant_rules
      : [],
    watcher_rules: Array.isArray(config.watcher_rules)
      ? config.watcher_rules
      : [],
    allow_self_approval: config.allow_self_approval === true,
    opinion_required: config.opinion_required === true,
  };
}

export function newNode(type: NodeType, key: string): WorkflowNode {
  const labels: Record<NodeType, string> = {
    start: '开始',
    approval: '审批',
    human_task: '人工办理',
    exclusive_gateway: '条件分支',
    service_task: '系统动作',
    end: '结束',
  };
  const config: Record<string, unknown> = {};
  if (type === 'approval' || type === 'human_task')
    Object.assign(config, {
      allocation_mode: type === 'approval' ? 'multi' : 'direct',
      aggregation_policy: type === 'approval' ? 'all_approve' : null,
      assignments: [],
      participant_rules: [],
      watcher_rules: [],
      allow_self_approval: false,
      opinion_required: false,
    });
  if (type === 'end') config.result_code = 'approved';
  return { key, label: labels[type], type, config };
}

/** Draft diagnostics are advisory. Only the server can approve publication. */
export function graphIssues(graph: WorkflowGraph): ValidationIssue[] {
  const issues: ValidationIssue[] = [];
  const add = (
    code: string,
    message: string,
    node_key?: string,
    field?: string
  ) => issues.push({ code, message, node_key, field });
  if (graph.contract_version !== 2)
    add('CAPABILITY_UNSUPPORTED', '只支持图协议 V2');
  if (graph.nodes.length > 100 || graph.transitions.length > 200)
    add('DEFINITION_INVALID', '最多100个节点、200条连线');
  if (graph.nodes.filter((node) => node.type === 'start').length !== 1)
    add('DEFINITION_INVALID', '需要且只能有一个开始节点');
  const keys = new Set<string>();
  graph.nodes.forEach((node) => {
    if (keys.has(node.key) || !node.key)
      add('DEFINITION_INVALID', '节点标识必须唯一且非空', node.key, 'key');
    keys.add(node.key);
    if (!node.label.trim())
      add('DEFINITION_INVALID', '请填写节点名称', node.key, 'label');
    if (node.type === 'approval' || node.type === 'human_task') {
      const config = humanConfig(node);
      if (!['direct', 'claim', 'multi'].includes(config.allocation_mode))
        add(
          'CAPABILITY_UNSUPPORTED',
          '该分配模式尚未实现',
          node.key,
          'allocation_mode'
        );
      if (!config.assignments.length)
        add('ASSIGNEE_UNRESOLVED', '请配置处理人来源', node.key, 'assignments');
      if (
        node.config.aggregation_policy &&
        !['all_approve', 'any_approve'].includes(
          String(node.config.aggregation_policy)
        )
      )
        add(
          'CAPABILITY_UNSUPPORTED',
          '该聚合策略尚未实现',
          node.key,
          'aggregation_policy'
        );
      if (node.type === 'human_task' && config.allocation_mode === 'multi')
        add(
          'DEFINITION_INVALID',
          '人工办理只支持直派或领取',
          node.key,
          'allocation_mode'
        );
      if (
        config.allocation_mode === 'multi' &&
        config.aggregation_policy === null
      )
        add(
          'DEFINITION_INVALID',
          '多人表决必须选择聚合策略',
          node.key,
          'aggregation_policy'
        );
      if (
        config.allocation_mode !== 'multi' &&
        config.aggregation_policy !== null
      )
        add(
          'DEFINITION_INVALID',
          '直派/领取不使用多人聚合策略',
          node.key,
          'aggregation_policy'
        );
      [
        ...config.assignments,
        ...config.participant_rules,
        ...config.watcher_rules,
      ].forEach((rule) => {
        if (
          ![
            'member',
            'role_members',
            'department_members',
            'department_leaders',
            'subject_owner',
            'initiator',
            'previous_actor',
          ].includes(rule.kind)
        )
          add(
            'CAPABILITY_UNSUPPORTED',
            '该人员来源尚未实现',
            node.key,
            'assignments'
          );
        if (
          [
            'member',
            'role_members',
            'department_members',
            'department_leaders',
          ].includes(rule.kind) &&
          !rule.key?.trim()
        )
          add(
            'ASSIGNEE_UNRESOLVED',
            '请填写来源绑定标识',
            node.key,
            'assignments'
          );
      });
    }
  });
  const edges = new Set<string>();
  graph.transitions.forEach((edge) => {
    if (edges.has(edge.key) || !edge.key)
      add('DEFINITION_INVALID', '连线标识重复或为空');
    edges.add(edge.key);
    if (!keys.has(edge.from) || !keys.has(edge.to))
      add('DEFINITION_INVALID', '连线端点不存在', edge.from);
    if (graph.nodes.find((node) => node.key === edge.from)?.type === 'end')
      add('DEFINITION_INVALID', '结束节点不能有出边', edge.from);
    if (edge.return_edge && (!edge.max_traversals || edge.max_traversals > 10))
      add('DEFINITION_INVALID', '退回连线必须限制往返次数（1–10）', edge.from);
  });
  const start = graph.nodes.find((node) => node.type === 'start');
  const reached = new Set<string>();
  const visiting = new Set<string>();
  const visit = (key: string) => {
    if (visiting.has(key)) {
      add('DEFINITION_INVALID', '前向流程不能成环', key);
      return;
    }
    if (reached.has(key)) return;
    reached.add(key);
    visiting.add(key);
    graph.transitions
      .filter((edge) => edge.from === key && !edge.return_edge)
      .forEach((edge) => visit(edge.to));
    visiting.delete(key);
  };
  if (start) visit(start.key);
  graph.nodes.forEach((node) => {
    if (!reached.has(node.key))
      add('DEFINITION_INVALID', '节点不可从开始到达', node.key);
    if (
      node.type !== 'end' &&
      !graph.transitions.some(
        (edge) => edge.from === node.key && !edge.return_edge
      )
    )
      add('DEFINITION_INVALID', '节点缺少后续路径', node.key);
  });
  return issues;
}

export function createEditorHistory(initial: EditorDocument) {
  let current = cloneDocument(initial);
  let saved = JSON.stringify(current);
  const past: EditorDocument[] = [];
  const future: EditorDocument[] = [];
  return {
    get document() {
      return cloneDocument(current);
    },
    get dirty() {
      return JSON.stringify(current) !== saved;
    },
    get canUndo() {
      return past.length > 0;
    },
    get canRedo() {
      return future.length > 0;
    },
    update(change: (draft: EditorDocument) => void) {
      const next = cloneDocument(current);
      change(next);
      if (JSON.stringify(next) === JSON.stringify(current)) return;
      past.push(current);
      if (past.length > 100) past.shift();
      current = next;
      future.length = 0;
    },
    undo() {
      const prior = past.pop();
      if (prior) {
        future.push(current);
        current = prior;
      }
    },
    redo() {
      const next = future.pop();
      if (next) {
        past.push(current);
        current = next;
      }
    },
    markSaved() {
      saved = JSON.stringify(current);
    },
    replace(document: EditorDocument) {
      current = cloneDocument(document);
      saved = JSON.stringify(current);
      past.length = 0;
      future.length = 0;
    },
  };
}
