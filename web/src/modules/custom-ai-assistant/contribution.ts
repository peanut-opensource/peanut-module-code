import type { ModuleFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';
const names: Record<string, string> = {
  connection: 'AI连接',
  model: '模型目录',
  route: '能力路由',
  template: '制作模板',
  task: 'AI制作任务',
  usage: '用量台账',
};
const contribution: ModuleFrontendContribution = {
  moduleKey: 'custom.ai-assistant',
  routes: [
    {
      path: '/ai-assistant',
      name: 'AiAssistantRoot',
      component: DEFAULT_LAYOUT,
      meta: {
        requiresAuth: true,
        tenantModuleKey: 'custom.ai-assistant',
        requiredPermissions: 'custom.ai-assistant.view',
      },
      children: Object.keys(names).map((kind) => ({
        path: kind,
        name: 'AiAssistant' + kind[0].toUpperCase() + kind.slice(1),
        component: () => import('./Workspace.vue'),
        meta: {
          title: names[kind],
          kind,
          requiresAuth: true,
          tenantModuleKey: 'custom.ai-assistant',
          requiredPermissions: 'custom.ai-assistant.view',
        },
      })),
    },
  ],
};
export default contribution;
