import type { RouteRecordRaw } from 'vue-router';

import { evaluateRequiredPermissions } from '@peanut-admin/vue';
import {
  allowsInstanceTools,
  deploymentMode,
  routesForDeployment,
} from '@peanut-admin/ui-vue';

const exactEvaluator = (
  permissions: ReadonlySet<string>,
  permission: string
): boolean => permissions.has(permission);

function expect(condition: boolean, message: string): void {
  if (!condition) throw new Error(message);
}

expect(
  evaluateRequiredPermissions('', [], exactEvaluator),
  'empty string must pass'
);
expect(
  evaluateRequiredPermissions([], [], exactEvaluator),
  'empty list must pass'
);
expect(
  evaluateRequiredPermissions('official.article.list', ['*'], exactEvaluator),
  'root wildcard grant must pass'
);
expect(
  evaluateRequiredPermissions(
    'official.article.list',
    ['official.article.list'],
    exactEvaluator
  ),
  'exact single permission must pass'
);
expect(
  evaluateRequiredPermissions(
    ['official.article.edit', 'official.article.list'],
    ['official.article.list'],
    exactEvaluator
  ),
  'multiple requirements must use any-of'
);
expect(
  !evaluateRequiredPermissions(
    'official.article.edit',
    ['official.article.list'],
    exactEvaluator
  ),
  'missing permission must fail'
);
expect(
  !evaluateRequiredPermissions('*', ['official.article.list'], exactEvaluator),
  'requesting wildcard must not bypass authorization'
);

const fixtureRoutes: RouteRecordRaw[] = [
  {
    path: '/business',
    meta: { requiresAuth: true },
    children: [
      {
        path: 'tenant-switch',
        redirect: '/',
        meta: { requiresAuth: true, controlPlane: 'tenant-selection' as const },
      },
    ],
  },
  {
    path: '/platform',
    redirect: '/',
    meta: { requiresAuth: true, controlPlane: 'platform' as const },
  },
];
const standaloneRoutes = routesForDeployment(
  fixtureRoutes,
  deploymentMode(undefined)
);
expect(standaloneRoutes.length === 1, 'Standalone exposed the platform route');
expect(
  standaloneRoutes[0].children?.length === 0,
  'Standalone exposed the Tenant switch route'
);
expect(
  deploymentMode('invalid') === 'standalone',
  'invalid deployment mode did not fail closed'
);
expect(
  allowsInstanceTools('standalone') &&
    !allowsInstanceTools(undefined) &&
    !allowsInstanceTools('invalid') &&
    !allowsInstanceTools('multi-tenant'),
  'instance tools must require explicit standalone mode'
);
expect(
  routesForDeployment(fixtureRoutes, deploymentMode('multi-tenant')).length ===
    2,
  'multi-tenant deployment lost its explicitly declared control plane'
);

console.log('PB04-AUTH-HOST-001 Web passed');
