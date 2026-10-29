import type {
  WorkflowCondition,
  WorkflowGraph,
  WorkflowScenario,
} from '../types';

export function objectValue(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};
}
export function schemaProperties(
  schema: Record<string, unknown>
): Record<string, Record<string, unknown>> {
  return Object.fromEntries(
    Object.entries(objectValue(schema.properties)).map(([key, value]) => [
      key,
      objectValue(value),
    ])
  );
}
export function initialValue(schema: Record<string, unknown>): unknown {
  if ('const' in schema) return schema.const;
  if ('default' in schema) return schema.default;
  if (Array.isArray(schema.enum)) return schema.enum[0] ?? null;
  if (schema.type === 'integer') return 0;
  if (schema.type === 'boolean') return false;
  if (schema.type === 'object') return {};
  if (schema.type === 'array') return [];
  if (schema.type === 'null') return null;
  return '';
}
export function newCondition(scenario: WorkflowScenario): WorkflowCondition {
  const field = Object.keys(scenario.fields)[0] ?? '';
  const registered = scenario.fields[field];
  let value: unknown = registered?.values?.[0] ?? '';
  if (registered?.type === 'integer') value = 0;
  else if (registered?.type === 'boolean') value = false;
  return { field, operator: 'eq', value };
}
export function usedCapabilities(graph: WorkflowGraph): string[] {
  const capabilities = new Set<string>();
  graph.nodes.forEach((node) => {
    if (node.type === 'approval' || node.type === 'human_task') {
      capabilities.add(`workflow.${node.type}`);
      const mode = node.config.allocation_mode;
      if (mode === 'direct' || mode === 'claim')
        capabilities.add(`workflow.${mode}`);
      const aggregation = node.config.aggregation_policy;
      if (aggregation === 'all_approve' || aggregation === 'any_approve')
        capabilities.add(`workflow.${aggregation}`);
    }
    if (node.type === 'exclusive_gateway' || node.type === 'service_task')
      capabilities.add(`workflow.${node.type}`);
  });
  if (graph.transitions.some((edge) => edge.return_edge))
    capabilities.add('workflow.return');
  return [...capabilities].sort();
}
export function conditionIssues(
  condition: unknown,
  scenario: WorkflowScenario,
  depth = 0,
  budget = { leaves: 0 }
): string[] {
  const row = objectValue(condition);
  if (depth > 3) return ['条件嵌套最多三层'];
  let group: 'all' | 'any' | null = null;
  if ('all' in row) group = 'all';
  else if ('any' in row) group = 'any';
  if (group) {
    const children = row[group];
    if (
      Object.keys(row).length !== 1 ||
      !Array.isArray(children) ||
      !children.length
    )
      return ['条件组必须非空且只使用 all 或 any'];
    return children.flatMap((child: unknown) =>
      conditionIssues(child, scenario, depth + 1, budget)
    );
  }
  budget.leaves += 1;
  if (budget.leaves > 50) return ['网关最多50个条件叶子'];
  if (
    Object.keys(row).sort().join(',') !== 'field,operator,value' ||
    typeof row.field !== 'string'
  )
    return ['条件字段形状无效'];
  const field = scenario.fields[row.field];
  if (!field) return ['条件字段未登记'];
  if (
    ![
      'eq',
      'ne',
      'gt',
      'gte',
      'lt',
      'lte',
      'in',
      'not_in',
      'is_null',
      'is_not_null',
    ].includes(String(row.operator))
  )
    return ['条件操作符未实现'];
  if (
    ['gt', 'gte', 'lt', 'lte'].includes(String(row.operator)) &&
    !['integer', 'decimal', 'datetime'].includes(field.type)
  )
    return ['该字段不支持大小比较'];
  if (row.operator === 'is_null' || row.operator === 'is_not_null')
    return field.nullable && row.value === null
      ? []
      : ['空值比较需可空字段及null值'];
  const many = row.operator === 'in' || row.operator === 'not_in';
  if (
    many &&
    (!Array.isArray(row.value) ||
      row.value.length === 0 ||
      row.value.length > 100)
  )
    return ['集合必须含1至100个值'];
  const values: unknown[] =
    many && Array.isArray(row.value) ? row.value : [row.value];
  const valid = (value: unknown) => {
    if (value === null) return field.nullable;
    if (field.type === 'boolean') return typeof value === 'boolean';
    if (field.type === 'integer')
      return typeof value === 'number' && Number.isSafeInteger(value);
    if (typeof value !== 'string' || value.length > 2000) return false;
    if (field.type === 'enum') return field.values?.includes(value) === true;
    if (field.type === 'decimal')
      return value.length <= 100 && /^-?\d+(?:\.\d+)?$/.test(value);
    if (field.type === 'datetime')
      return (
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/.test(value) &&
        !Number.isNaN(Date.parse(value)) &&
        new Date(value).toISOString() ===
          (value.includes('.') ? value : value.replace('Z', '.000Z'))
      );
    return true;
  };
  return values.every(valid)
    ? []
    : ['条件值不符合登记类型、精确整数/十进制或UTC规则'];
}
export function configurationIssues(
  graph: WorkflowGraph,
  scenario: WorkflowScenario
): string[] {
  const issues: string[] = [];
  if (graph.scenario_contract_version !== scenario.scenario_contract_version)
    issues.push('场景合同版本已变化，请明确更新后验证');
  graph.required_capabilities.forEach((capability) => {
    if (!scenario.capabilities.includes(capability))
      issues.push(`CAPABILITY_UNSUPPORTED: ${capability}`);
  });
  usedCapabilities(graph).forEach((capability) => {
    if (!graph.required_capabilities.includes(capability))
      issues.push(`缺少所用能力登记：${capability}`);
  });
  graph.nodes.forEach((node) => {
    if (
      node.type === 'end' &&
      !scenario.result_codes.includes(String(node.config.result_code))
    )
      issues.push(`${node.key}: 结果未登记`);
    if (node.type === 'exclusive_gateway') {
      const outgoing = graph.transitions.filter(
        (edge) => edge.from === node.key && !edge.return_edge
      );
      const branches = Array.isArray(node.config.branches)
        ? node.config.branches.map(objectValue)
        : [];
      const keys = [
        node.config.default_transition_key,
        ...branches.map((branch) => branch.transition_key),
      ];
      if (
        outgoing.length < 2 ||
        outgoing.some((edge) => edge.action !== 'branch') ||
        keys.length !== outgoing.length ||
        new Set(keys).size !== keys.length ||
        keys.some((key) => !outgoing.some((edge) => edge.key === key))
      )
        issues.push(`${node.key}: 条件和唯一默认规则必须覆盖全部branch出口`);
      const budget = { leaves: 0 };
      branches.forEach((branch) =>
        conditionIssues(branch.condition, scenario, 0, budget).forEach(
          (issue) => issues.push(`${node.key}: ${issue}`)
        )
      );
    }
    if (node.type === 'service_task') {
      const action = scenario.actions.find(
        (candidate) =>
          candidate.action_key === node.config.action_key &&
          candidate.contract_version === node.config.contract_version
      );
      if (!action) {
        issues.push(`${node.key}: CAPABILITY_UNSUPPORTED: 登记动作及版本`);
        return;
      }
      const input = objectValue(node.config.input);
      const required = Array.isArray(action.input_schema.required)
        ? action.input_schema.required
        : [];
      if (required.some((key) => typeof key !== 'string' || !(key in input)))
        issues.push(`${node.key}: 必填动作参数缺少映射`);
      Object.entries(input).forEach(([key, value]) => {
        const binding = objectValue(value);
        if (key.length > 160 || Object.keys(input).length > 100)
          issues.push(`${node.key}: 动作参数超出限制`);
        if (
          binding.source === 'instance' &&
          ![
            'subject_key',
            'subject_revision_key',
            'instance_key',
            'cycle_no',
          ].includes(String(binding.field))
        )
          issues.push(`${node.key}: 非法实例字段绑定`);
        else if (
          binding.source === 'node_input' &&
          !(String(binding.field) in scenario.fields)
        )
          issues.push(`${node.key}: 未登记输入字段`);
        else if (
          binding.source !== 'literal' &&
          binding.source !== 'instance' &&
          binding.source !== 'node_input'
        )
          issues.push(`${node.key}: 未实现的输入来源`);
      });
    }
  });
  return issues;
}
