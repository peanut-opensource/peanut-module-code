import { requireGovernancePermission } from './catalog';
import type {
  GovernanceCatalog,
  GovernanceMenuExplanation,
  GovernanceMenuInput,
  GovernanceVisibilityContext,
} from './types';

const fail = (code: string): never => {
  throw new Error(code);
};

const sameStrings = (
  left: readonly string[],
  right: readonly string[]
): boolean =>
  [...new Set(left)].sort().join('\u0000') ===
    [...new Set(right)].sort().join('\u0000') &&
  new Set(left).size === left.length &&
  new Set(right).size === right.length;

export const explainMenuVisibility = (
  menu: GovernanceMenuInput,
  context: GovernanceVisibilityContext,
  catalog: GovernanceCatalog
): GovernanceMenuExplanation => {
  const route =
    menu.routeName === null ? null : catalog.routes.get(menu.routeName);
  if (menu.type === 'page' && route === undefined)
    fail('GOVERNANCE_ROUTE_UNDECLARED');
  if (route !== null && route !== undefined) {
    if (menu.audience !== context.audience || route.audience !== menu.audience)
      fail('GOVERNANCE_ROUTE_AUDIENCE_MISMATCH');
    const expectedModule =
      menu.audience === 'platform' && menu.moduleKey === 'core'
        ? 'platform'
        : menu.moduleKey;
    const routeModule =
      route.moduleKey ?? (route.audience === 'platform' ? 'platform' : 'core');
    if (routeModule !== expectedModule)
      fail('GOVERNANCE_ROUTE_MODULE_MISMATCH');
    if (
      route.path !== menu.routePath ||
      route.componentKey !== menu.componentKey ||
      !sameStrings(route.clientKeys, menu.clientKeys)
    )
      fail('GOVERNANCE_ROUTE_CONTRACT_MISMATCH');
  }

  const permission =
    menu.requiredPermission === null
      ? null
      : requireGovernancePermission(
          catalog,
          menu.requiredPermission,
          context.audience
        );
  const expectedPermissionModule =
    menu.audience === 'platform' && menu.moduleKey === 'core'
      ? 'platform'
      : menu.moduleKey;
  if (permission !== null && permission.moduleKey !== expectedPermissionModule)
    fail('GOVERNANCE_PERMISSION_MODULE_MISMATCH');
  if (
    route !== null &&
    route !== undefined &&
    permission !== null &&
    !route.permissionKeys.includes(permission.key)
  )
    fail('GOVERNANCE_ROUTE_PERMISSION_MISMATCH');

  const icon = menu.icon === null ? null : catalog.icons.get(menu.icon);
  if (menu.icon !== null && icon === undefined)
    fail('GOVERNANCE_ICON_UNDECLARED');

  let reason = 'visible';
  if (!menu.clientKeys.includes(context.clientKey)) {
    reason = 'client_unavailable';
  } else if (
    menu.moduleKey !== 'core' &&
    !context.deploymentModules.has(menu.moduleKey)
  ) {
    reason = 'deployment_module_unavailable';
  } else if (
    menu.moduleKey !== 'core' &&
    context.audience === 'tenant' &&
    !context.tenantModules.has(menu.moduleKey)
  ) {
    reason = 'tenant_module_disabled';
  } else if (permission !== null && !context.permissions.has(permission.key)) {
    reason = 'permission_not_granted';
  }

  return {
    key: menu.key,
    visible: reason === 'visible',
    reason,
    trustedPath: route?.path ?? null,
    icon: icon ?? null,
  };
};
