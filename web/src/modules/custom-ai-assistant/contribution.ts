import type { ModuleFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const pages: Record<string, { title: string; icon: string }> = {
  connection: { title: 'AI连接', icon: 'icon-mind-mapping' },
  model: { title: '模型目录', icon: 'icon-apps' },
  route: { title: '能力路由', icon: 'icon-share-alt' },
  template: { title: '制作模板', icon: 'icon-file' },
  task: { title: 'AI制作任务', icon: 'icon-thunderbolt' },
  usage: { title: '用量台账', icon: 'icon-bar-chart' },
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
      children: Object.entries(pages).map(([kind, page]) => ({
        path: kind,
        name: `AiAssistant${kind[0].toUpperCase()}${kind.slice(1)}`,
        component: () => import('./Workspace.vue'),
        meta: {
          title: page.title,
          icon: page.icon,
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
