export default {
  'systemMenu.field.perms.default': '源默认权限包',
  'systemMenu.field.perms.managed.placeholder':
    '选择已有权限包，清空恢复源默认',
  'systemMenu.field.perms.managed.hint':
    '可引用同一安全域内启用的已有权限包；清空后恢复系统或模块声明的默认权限包。仅调整此菜单的访问条件，不改变角色授权或 API 关联。',
  'systemMenu.field.action': '页面动作标识',
  'systemMenu.field.action.placeholder':
    '如 role/add，与页面 v-permission 的动作标识一致',
  'systemMenu.field.action.hint':
    '按钮须直属页面菜单；仅在该父页面将此动作关联到所选权限包，不改变其他页面或角色授权。',
  'systemMenu.field.perms.hint':
    '引用独立权限包；多个菜单或按钮可复用同一权限包，修改菜单不授予角色权限。',
  'systemMenu.field.perms.error': '权限包目录未完整加载，请重新打开窗口',
  'menu.system': '系统管理',
  'menu.system.menu': '菜单管理',

  'systemMenu.operation.create': '新增菜单',
  'systemMenu.operation.refresh': '刷新',
  'systemMenu.operation.addChild': '新增子级',
  'systemMenu.operation.edit': '编辑',
  'systemMenu.operation.delete': '删除',

  'systemMenu.columns.name': '菜单名称',
  'systemMenu.columns.type': '类型',
  'systemMenu.columns.icon': '图标',
  'systemMenu.columns.perms': '所需权限包',
  'systemMenu.columns.sort': '排序',
  'systemMenu.columns.status': '启用',
  'systemMenu.columns.operations': '操作',

  'systemMenu.type.M': '目录',
  'systemMenu.type.C': '菜单',
  'systemMenu.type.A': '按钮',

  'systemMenu.field.type': '菜单类型',
  'systemMenu.field.type.required': '请选择菜单类型',
  'systemMenu.field.pid': '上级菜单',
  'systemMenu.field.pid.root': '顶级菜单',
  'systemMenu.field.pid.placeholder': '不选则为顶级菜单',
  'systemMenu.field.name': '菜单名称',
  'systemMenu.field.name.placeholder': '请输入菜单名称',
  'systemMenu.field.name.required': '菜单名称不能为空',
  'systemMenu.field.icon': '图标',
  'systemMenu.field.icon.placeholder': '如 icon-settings',
  'systemMenu.field.paths': '路由路径',
  'systemMenu.field.paths.placeholder': '如 /system/menu',
  'systemMenu.field.component': '前端组件',
  'systemMenu.field.component.placeholder': '如 system/menu/index',
  'systemMenu.field.perms': '所需权限包',
  'systemMenu.field.perms.placeholder': '请选择已有权限包，可留空',
  'systemMenu.field.sort': '排序',
  'systemMenu.field.flags': '选项',
  'systemMenu.field.isShow': '显示',
  'systemMenu.field.isCache': '缓存',

  'systemMenu.modal.addTitle': '新增菜单',
  'systemMenu.modal.editTitle': '编辑菜单',
  'systemMenu.delete.confirm': '确定删除该菜单及其权限绑定吗？',
  'systemMenu.tip.success': '操作成功',
};
