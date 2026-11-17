import { describe, expect, it } from 'vitest';

import {
  createGovernanceCatalog,
  createDataPolicyDraft,
  createRolePermissionDraft,
  explainMenuVisibility,
  projectAuditDetail,
} from '../../governance/index';

describe('governance catalog', () => {
  const catalog = createGovernanceCatalog({
    permissions: [
      {
        key: 'core.role.read',
        moduleKey: 'core',
        audience: 'tenant',
        active: true,
      },
      {
        key: 'example.report.read',
        moduleKey: 'example.report',
        audience: 'tenant',
        active: true,
      },
      {
        key: 'platform.role.read',
        moduleKey: 'platform',
        audience: 'platform',
        active: true,
      },
    ],
    routes: [
      {
        name: 'tenant.roles.list',
        path: '/app/roles',
        audience: 'tenant',
        permissionKeys: ['core.role.read'],
        componentKey: 'core.role.list',
        clientKeys: ['admin-web'],
      },
      {
        name: 'example.report.list',
        path: '/app/reports',
        audience: 'tenant',
        moduleKey: 'example.report',
        permissionKeys: ['example.report.read'],
        componentKey: 'example.report.page',
        clientKeys: ['admin-web'],
      },
    ],
    icons: {
      Shield: { label: 'Roles', glyph: 'S' },
      Files: { label: 'Files', glyph: 'F' },
    },
  });

  it('explains module and permission visibility without trusting server paths', () => {
    expect(
      explainMenuVisibility(
        {
          key: 'example.report',
          type: 'page',
          audience: 'tenant',
          routeName: 'example.report.list',
          routePath: '/app/reports',
          componentKey: 'example.report.page',
          requiredPermission: 'example.report.read',
          moduleKey: 'example.report',
          clientKeys: ['admin-web'],
          icon: 'Files',
        },
        {
          audience: 'tenant',
          clientKey: 'admin-web',
          deploymentModules: new Set(['example.report']),
          tenantModules: new Set(),
          permissions: new Set(['example.report.read']),
        },
        catalog
      )
    ).toMatchObject({
      visible: false,
      reason: 'tenant_module_disabled',
      trustedPath: '/app/reports',
    });

    expect(() =>
      explainMenuVisibility(
        {
          key: 'injected',
          type: 'page',
          audience: 'tenant',
          routeName: 'server.injected',
          routePath: '/app/injected',
          componentKey: 'server.injected',
          requiredPermission: 'core.role.read',
          moduleKey: 'core',
          clientKeys: ['admin-web'],
          icon: 'Shield',
        },
        {
          audience: 'tenant',
          clientKey: 'admin-web',
          deploymentModules: new Set(),
          tenantModules: new Set(),
          permissions: new Set(['core.role.read']),
        },
        catalog
      )
    ).toThrow('GOVERNANCE_ROUTE_UNDECLARED');

    expect(() =>
      explainMenuVisibility(
        {
          key: 'mismatch',
          type: 'page',
          audience: 'tenant',
          routeName: 'tenant.roles.list',
          routePath: '/app/roles',
          componentKey: 'server.injected',
          requiredPermission: 'core.role.read',
          moduleKey: 'core',
          clientKeys: ['admin-web'],
          icon: 'Shield',
        },
        {
          audience: 'tenant',
          clientKey: 'admin-web',
          deploymentModules: new Set(),
          tenantModules: new Set(),
          permissions: new Set(['core.role.read']),
        },
        catalog
      )
    ).toThrow('GOVERNANCE_ROUTE_CONTRACT_MISMATCH');
  });

  it('builds validation-only drafts aligned with the trusted role and data-policy APIs', () => {
    expect(
      createRolePermissionDraft({
        audience: 'tenant',
        roleId: '9',
        currentRevision: 4,
        ifMatch: '"rev-4"',
        permissionKeys: ['core.role.read', 'core.role.read'],
        availableModules: new Set(),
        catalog,
      }).payload.permission_keys
    ).toEqual(['core.role.read']);
    expect(() =>
      createRolePermissionDraft({
        audience: 'tenant',
        roleId: '9',
        currentRevision: 4,
        ifMatch: '"rev-4"',
        permissionKeys: ['platform.role.read'],
        availableModules: new Set(),
        catalog,
      })
    ).toThrow('GOVERNANCE_PERMISSION_AUDIENCE_MISMATCH');

    const createDraft = createDataPolicyDraft({
      mode: 'create',
      audience: 'tenant',
      roleId: '9',
      resourceKey: 'example.report',
      operation: 'list',
      payload: {
        status: 'active',
        reason: 'regional access',
        valid_from: '2026-07-24T00:00:00.000Z',
        valid_until: '2026-08-24T00:00:00.000Z',
        groups: [
          {
            name: 'east-region',
            conditions: [
              {
                condition_key: 'core.specified_objects',
                target_set: {
                  name: 'east',
                  target_resource_key: 'example.report',
                  targets: [{ target_id: '101' }],
                },
              },
            ],
          },
        ],
      },
    });
    expect(createDraft).toMatchObject({
      kind: 'validated-draft',
      mode: 'create',
      audience: 'tenant',
      expectedRevision: null,
      payload: {
        status: 'active',
        groups: [
          {
            conditions: [
              {
                target_set: {
                  target_resource_key: 'example.report',
                  targets: [{ target_id: '101' }],
                },
              },
            ],
          },
        ],
      },
    });
    const updateDraft = createDataPolicyDraft({
      mode: 'update',
      audience: 'tenant',
      roleId: '9',
      currentRevision: 7,
      ifMatch: '"rev-7"',
      resourceKey: 'example.report',
      operation: 'list',
      payload: createDraft.payload,
    });
    expect(updateDraft).toMatchObject({ mode: 'update', expectedRevision: 7 });
    expect(() =>
      createDataPolicyDraft({
        mode: 'update',
        audience: 'tenant',
        roleId: '9',
        currentRevision: 7,
        ifMatch: '"rev-6"',
        resourceKey: 'example.report',
        operation: 'list',
        payload: createDraft.payload,
      })
    ).toThrow('REVISION_MISMATCH');
    expect(() =>
      createDataPolicyDraft({
        mode: 'create',
        audience: 'tenant',
        roleId: '9',
        resourceKey: 'example.report',
        operation: 'list',
        payload: { status: 'active', groups: [] },
      })
    ).toThrow('DATA_POLICY_GROUPS_INVALID');
  });

  it('rejects non-canonical paths, duplicate paths, and invalid route permissions', () => {
    for (const path of [
      '/app/../roles',
      '/app/%2e%2e/roles',
      '/app/roles?x=1',
      '/app/roles#x',
      '/app//roles',
      '/app\\roles',
      '/app/roles/',
      '/platform/roles',
    ]) {
      expect(() =>
        createGovernanceCatalog({
          permissions: [
            {
              key: 'core.role.read',
              moduleKey: 'core',
              audience: 'tenant',
              active: true,
            },
          ],
          routes: [
            {
              name: 'tenant.invalid',
              path,
              audience: 'tenant',
              permissionKeys: ['core.role.read'],
              componentKey: 'core.role.list',
              clientKeys: ['admin-web'],
            },
          ],
          icons: {},
        })
      ).toThrow('GOVERNANCE_ROUTE_INVALID');
    }
    expect(() =>
      createGovernanceCatalog({
        permissions: [
          {
            key: 'core.role.read',
            moduleKey: 'core',
            audience: 'tenant',
            active: true,
          },
        ],
        routes: [
          {
            name: 'tenant.roles.list',
            path: '/app/roles',
            audience: 'tenant',
            permissionKeys: ['core.role.read'],
            componentKey: 'core.role.list',
            clientKeys: ['admin-web'],
          },
          {
            name: 'tenant.roles.alias',
            path: '/app/roles',
            audience: 'tenant',
            permissionKeys: ['core.role.read'],
            componentKey: 'core.role.list',
            clientKeys: ['admin-web'],
          },
        ],
        icons: {},
      })
    ).toThrow('GOVERNANCE_ROUTE_INVALID');
    expect(() =>
      createGovernanceCatalog({
        permissions: [
          {
            key: 'core.role.retired',
            moduleKey: 'core',
            audience: 'tenant',
            active: false,
          },
        ],
        routes: [
          {
            name: 'tenant.retired',
            path: '/app/retired',
            audience: 'tenant',
            permissionKeys: ['core.role.retired'],
            componentKey: 'core.role.list',
            clientKeys: ['admin-web'],
          },
        ],
        icons: {},
      })
    ).toThrow('GOVERNANCE_PERMISSION_INACTIVE');
    expect(() =>
      createGovernanceCatalog({
        permissions: [
          {
            key: 'core.role.read',
            moduleKey: 'core',
            audience: 'tenant',
            active: true,
          },
        ],
        routes: [
          {
            name: 'service.roles',
            path: '/platform/roles',
            audience: 'service' as never,
            permissionKeys: ['core.role.read'],
            componentKey: 'core.role.list',
            clientKeys: ['admin-web'],
          },
        ],
        icons: {},
      })
    ).toThrow('GOVERNANCE_ROUTE_INVALID');
    expect(() =>
      createGovernanceCatalog({
        permissions: [
          {
            key: 'service.role.read',
            moduleKey: 'service',
            audience: 'service' as never,
            active: true,
          },
        ],
        routes: [],
        icons: {},
      })
    ).toThrow('GOVERNANCE_PERMISSION_INVALID');
  });

  it('projects audit details through a scalar metadata allowlist', () => {
    expect(
      projectAuditDetail(
        {
          id: '12',
          audience: 'tenant',
          eventType: 'tenant.role.updated',
          action: 'core.role.update',
          outcome: 'success',
          requestId: 'req_123',
          occurredAt: '2026-07-24T00:00:00.000Z',
          metadata: {
            revision: 7,
            permission_count: 3,
            token: 'secret',
            sql: 'SELECT secret',
            raw_target_set: ['101'],
          },
        },
        ['revision', 'permission_count']
      )
    ).toMatchObject({ metadata: { permission_count: 3, revision: 7 } });
    expect(() =>
      projectAuditDetail(
        {
          id: '12',
          audience: 'tenant',
          eventType: 'tenant.role.updated',
          action: 'core.role.update',
          outcome: 'success',
          requestId: 'req_123',
          occurredAt: '2026-07-24T00:00:00.000Z',
          metadata: {},
        },
        ['revision', 'raw_target_set']
      )
    ).toThrow('AUDIT_METADATA_ALLOWLIST_INVALID');
  });
});
