export default {
  'systemMenu.field.perms.default': 'Source default permission bundle',
  'systemMenu.field.perms.managed.placeholder':
    'Select an existing bundle, or clear to restore the source default',
  'systemMenu.field.perms.managed.hint':
    'Select an active bundle in the same audience. Clearing restores the system or module default. This changes this menu access condition; role grants and API bindings remain separate.',
  'systemMenu.field.action': 'Page action key',
  'systemMenu.field.action.placeholder':
    'For example role/add, matching the page v-permission action',
  'systemMenu.field.action.hint':
    'A button must belong directly to a page menu. This binds the action to the selected bundle on that page only; role grants remain separate.',
  'systemMenu.field.perms.hint':
    'Reference an independent permission bundle. Menus and buttons can reuse the same bundle; editing navigation never grants role permissions.',
  'systemMenu.field.perms.error':
    'The permission bundle catalog did not load completely. Reopen this dialog.',
  'menu.system': 'System',
  'menu.system.menu': 'Menu Management',

  'systemMenu.operation.create': 'New Menu',
  'systemMenu.operation.refresh': 'Refresh',
  'systemMenu.operation.addChild': 'Add Child',
  'systemMenu.operation.edit': 'Edit',
  'systemMenu.operation.delete': 'Delete',

  'systemMenu.columns.name': 'Name',
  'systemMenu.columns.type': 'Type',
  'systemMenu.columns.icon': 'Icon',
  'systemMenu.columns.perms': 'Required bundle',
  'systemMenu.columns.sort': 'Sort',
  'systemMenu.columns.status': 'Enabled',
  'systemMenu.columns.operations': 'Operations',

  'systemMenu.type.M': 'Directory',
  'systemMenu.type.C': 'Menu',
  'systemMenu.type.A': 'Button',

  'systemMenu.field.type': 'Menu Type',
  'systemMenu.field.type.required': 'Please select a menu type',
  'systemMenu.field.pid': 'Parent Menu',
  'systemMenu.field.pid.root': 'Top Level',
  'systemMenu.field.pid.placeholder': 'Leave empty for top level',
  'systemMenu.field.name': 'Name',
  'systemMenu.field.name.placeholder': 'Enter menu name',
  'systemMenu.field.name.required': 'Name is required',
  'systemMenu.field.icon': 'Icon',
  'systemMenu.field.icon.placeholder': 'e.g. icon-settings',
  'systemMenu.field.paths': 'Route Path',
  'systemMenu.field.paths.placeholder': 'e.g. /system/menu',
  'systemMenu.field.component': 'Component',
  'systemMenu.field.component.placeholder': 'e.g. system/menu/index',
  'systemMenu.field.perms': 'Required bundle',
  'systemMenu.field.perms.placeholder':
    'Select an existing bundle, or leave empty',
  'systemMenu.field.sort': 'Sort',
  'systemMenu.field.flags': 'Options',
  'systemMenu.field.isShow': 'Visible',
  'systemMenu.field.isCache': 'Cache',

  'systemMenu.modal.addTitle': 'New Menu',
  'systemMenu.modal.editTitle': 'Edit Menu',
  'systemMenu.delete.confirm': 'Delete this menu and its permission bindings?',
  'systemMenu.tip.success': 'Success',
};
