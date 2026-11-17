import type { ModuleFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const pages = [
  {
    path: 'providers',
    name: 'AiAccessProviders',
    title: '服务商',
    kind: 'provider',
  },
  {
    path: 'credentials',
    name: 'AiAccessCredentials',
    title: '令牌',
    kind: 'credential',
  },
  { path: 'models', name: 'AiAccessModels', title: '模型', kind: 'model' },
];

const contribution: ModuleFrontendContribution = {
  moduleKey: 'custom.ai-access',
  routes: [
    {
      path: '/ai-access',
      name: 'customAiAccessRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'custom.ai-access' },
      children: [
        ...pages.map(({ path, name, title, kind }) => ({
          path,
          name,
          component: () => import('./views/index.vue'),
          props: { kind },
          meta: {
            title,
            requiresAuth: true,
            tenantModuleKey: 'custom.ai-access',
            requiredPermissions: 'custom.ai-access.view',
          },
        })),
        {
          path: 'test',
          name: 'AiAccessTest',
          component: () => import('./views/test.vue'),
          meta: {
            title: '调用测试',
            requiresAuth: true,
            tenantModuleKey: 'custom.ai-access',
            requiredPermissions: 'custom.ai-access.use',
          },
        },
      ],
    },
  ],
};

export default contribution;
