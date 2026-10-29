import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { defineComponent, h } from 'vue';
import runtimeContribution from './runtime/contribution';

const WorkflowDesignerLoadError = defineComponent({
  name: 'WorkflowDesignerLoadError',
  setup() {
    return () =>
      h('section', { role: 'alert', style: 'padding: 24px' }, [
        h('h2', '流程设计器加载失败'),
        h(
          'p',
          '流程设计器的页面或样式资源未能加载。请检查网络连接后刷新重试；若问题持续，请联系管理员检查资源部署。'
        ),
        h('button', { type: 'button', onClick: () => window.location.reload() }, '刷新重试'),
      ]);
  },
});

/** Full-mode entry. Runtime products select runtime/contribution.ts at assembly. */
const fullContribution: PluginFrontendContribution = {
  moduleKey: 'peanut.workflow',
  routes: runtimeContribution.routes.map((route) => ({
    ...route,
    children: [
      ...(route.children || []),
      {
        path: 'designer',
        name: 'WorkflowDesigner',
        component: () =>
          import('./designer/WorkflowDesignerPage.vue').catch((error: unknown) => {
            console.error('[peanut.workflow] 流程设计器资源加载失败', error);
            return WorkflowDesignerLoadError;
          }),
        meta: {
          requiresAuth: true,
          tenantModuleKey: 'peanut.workflow',
          requiredPermissions: 'peanut.workflow.definition.read',
          locale: '流程设计',
        },
      },
    ],
  })),
};
export default fullContribution;
