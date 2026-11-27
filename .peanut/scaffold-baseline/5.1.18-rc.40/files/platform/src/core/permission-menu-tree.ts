import type { PermissionMenuBinding } from '../api/permission-bundles';

export type PermissionMenuTreeNode = PermissionMenuBinding & {
  children: PermissionMenuTreeNode[];
};

/**
 * Project only authoritative menu records into a non-mutating directory→page→
 * action tree. Searching preserves ancestors, and missing retired parents are
 * not invented or silently granted.
 */
export function permissionMenuTree(
  source: readonly PermissionMenuBinding[],
  query = ''
): PermissionMenuTreeNode[] {
  const index = new Map<string, PermissionMenuBinding>();
  for (const item of source) {
    if (!item.menu_key || index.has(item.menu_key))
      throw new Error('重复或无效的菜单标识，请刷新目录');
    index.set(item.menu_key, item);
  }
  const term = query.trim().toLocaleLowerCase();
  const included = new Set<string>();
  for (const item of source) {
    const text = [
      item.name, item.menu_key, item.page_path, item.action_key, item.permission_key,
    ].join(' ').toLocaleLowerCase();
    if (term && !text.includes(term)) continue;
    let node: PermissionMenuBinding | undefined = item;
    const path = new Set<string>();
    while (node) {
      if (path.has(node.menu_key))
        throw new Error('菜单目录存在循环父节点，请修复正式目录');
      path.add(node.menu_key);
      included.add(node.menu_key);
      node = node.parent_key ? index.get(node.parent_key) : undefined;
    }
  }
  const nodes = new Map<string, PermissionMenuTreeNode>();
  for (const item of source)
    if (included.has(item.menu_key))
      nodes.set(item.menu_key, { ...item, children: [] });
  const result: PermissionMenuTreeNode[] = [];
  for (const item of source) {
    const node = nodes.get(item.menu_key);
    if (!node) continue;
    const parent = item.parent_key ? nodes.get(item.parent_key) : undefined;
    if (parent) {
      if (parent.type === 'A')
        throw new Error('按钮不能有子菜单，请修复正式目录');
      parent.children.push(node);
    } else {
      result.push(node);
    }
  }
  return result;
}
