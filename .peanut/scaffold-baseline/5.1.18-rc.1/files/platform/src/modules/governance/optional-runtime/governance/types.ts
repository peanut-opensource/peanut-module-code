import type { AdminNavigationRoute } from '@peanut-admin/vue';

export type GovernanceAudience = 'tenant' | 'platform';

export interface GovernancePermissionDefinition {
  key: string;
  moduleKey: string;
  audience: GovernanceAudience;
  active: boolean;
}

export interface GovernanceRouteDefinition extends AdminNavigationRoute {
  audience: GovernanceAudience;
  moduleKey?: string;
  permissionKeys: readonly string[];
  componentKey: string;
  clientKeys: readonly string[];
}

export interface GovernanceIconDefinition {
  label: string;
  glyph: string;
}

export interface GovernanceCatalog {
  permissions: ReadonlyMap<string, GovernancePermissionDefinition>;
  routes: ReadonlyMap<string, GovernanceRouteDefinition>;
  icons: ReadonlyMap<string, GovernanceIconDefinition>;
}

export interface GovernanceCatalogInput {
  permissions: readonly GovernancePermissionDefinition[];
  routes: readonly GovernanceRouteDefinition[];
  icons: Readonly<Record<string, GovernanceIconDefinition>>;
}

export interface GovernanceMenuInput {
  key: string;
  type: 'group' | 'page' | 'link';
  audience: GovernanceAudience;
  routeName: string | null;
  routePath: string | null;
  componentKey: string | null;
  requiredPermission: string | null;
  moduleKey: string;
  clientKeys: readonly string[];
  icon: string | null;
}

export interface GovernanceVisibilityContext {
  audience: GovernanceAudience;
  clientKey: string;
  deploymentModules: ReadonlySet<string>;
  tenantModules: ReadonlySet<string>;
  permissions: ReadonlySet<string>;
}

export interface GovernanceMenuExplanation {
  key: string;
  visible: boolean;
  reason: string;
  trustedPath: string | null;
  icon: GovernanceIconDefinition | null;
}
