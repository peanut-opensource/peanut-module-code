import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { RouteRecordRaw } from 'vue-router';
const state = vi.hoisted(() => ({
  user: { permissions: ['official.rich-text.document.list'], role: 'editor' },
  app: { menuFromServer: false, appAsyncMenus: [] as RouteRecordRaw[] },
}));
vi.mock('@/store', () => ({
  useUserStore: () => state.user,
  useAppStore: () => state.app,
}));
vi.mock('@/core/runtime', () => ({
  permissionEvaluator: (grants: ReadonlySet<string>, permission: string) =>
    grants.has(permission),
}));
import usePermission from '@/hooks/permission';
const route = (meta: Record<string, unknown>): RouteRecordRaw => ({
  path: '/rich-text',
  name: 'RichTextDocuments',
  component: {},
  meta: { requiresAuth: true, ...meta },
});
beforeEach(() => {
  state.app.menuFromServer = false;
  state.app.appAsyncMenus = [];
});
describe('actual host route meta guards', () => {
  it.each([
    { roles: 'editor' },
    { roles: ['*', 123] },
    { requiredPermissions: {} },
    { requiredPermissions: ['official.rich-text.document.list', 7] },
    { requiredPermissions: [] },
    { tenantModuleKey: {} },
    { tenantModuleKey: '' },
    { activeMenu: {} },
    { activeMenu: '' },
    { order: Number.NaN },
    { order: Number.POSITIVE_INFINITY },
    { requiresAuth: 'false' },
  ])('rejects malformed meta without wildcard/empty fallback: %j', (meta) => {
    expect(usePermission().accessRouter(route(meta))).toBe(false);
  });
  it('preserves role wildcard and validates tenant module permission/name together', () => {
    expect(usePermission().accessRouter(route({ roles: ['*'] }))).toBe(true);
    state.app.menuFromServer = true;
    state.app.appAsyncMenus = [
      route({ tenantModuleKey: 'official.rich-text' }),
    ];
    expect(
      usePermission().accessRouter(
        route({
          tenantModuleKey: 'official.rich-text',
          requiredPermissions: 'official.rich-text.document.list',
        })
      )
    ).toBe(true);
    expect(
      usePermission().accessRouter(
        route({ tenantModuleKey: 'official.rich-text' })
      )
    ).toBe(false);
    expect(
      usePermission().accessRouter(
        route({
          tenantModuleKey: 'official.file',
          requiredPermissions: 'official.rich-text.document.list',
        })
      )
    ).toBe(false);
    expect(
      usePermission().findFirstPermissionRoute(
        [route({ requiredPermissions: 'missing' })],
        'editor'
      )
    ).toBeNull();
  });
});
