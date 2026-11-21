// @vitest-environment happy-dom

// @vue/test-utils and Vitest are provided by the locked quality toolchain.
// eslint-disable-next-line import/no-unresolved
import { mount } from '@vue/test-utils';
import { ElButton } from 'element-plus';
import { createRouter, createMemoryHistory } from 'vue-router';
import { defineComponent, h, withDirectives } from 'vue';
// eslint-disable-next-line import/no-unresolved
import { describe, expect, it, vi } from 'vitest';
import { hasPermission } from '@/hooks/permission';
import permission from './index';

const { user, appRouter } = vi.hoisted(() => ({
  user: { permissions: ['peanut.settings.manage'], action_bindings: [] },
  appRouter: { currentRoute: { value: { path: '' } } },
}));

vi.mock('@/store', () => ({
  useUserStore: () => user,
  useAppStore: () => ({}),
}));
vi.mock('@/router', () => ({ default: appRouter }));

describe('permission directive', () => {
  it.each([
    { permissions: ['peanut.settings.manage'], visible: true },
    { permissions: [], visible: false },
  ])(
    'uses the current page path with permissions $permissions',
    async ({ permissions, visible }) => {
      user.permissions = permissions;
      const router = createRouter({
        history: createMemoryHistory(),
        routes: [
          {
            path: '/app-setting/website',
            component: { render: () => h('div') },
          },
        ],
      });
      await router.push('/app-setting/website');
      await router.isReady();
      appRouter.currentRoute.value.path = router.currentRoute.value.path;
      expect(
        hasPermission(
          ['peanut.settings.manage'],
          router.currentRoute.value.path
        )
      ).toBe(visible);

      const component = defineComponent({
        setup(_, { expose }) {
          expose({});
          return () =>
            h('div', [
              withDirectives(
                h(ElButton, { type: 'primary' }, { default: () => 'Save' }),
                [[permission, ['peanut.settings.manage']]]
              ),
            ]);
        },
      });
      const wrapper = mount(component, {
        global: { plugins: [router] },
      });

      expect(wrapper.find('button').exists()).toBe(visible);
      if (visible) expect(wrapper.get('button').text()).toBe('Save');
      wrapper.unmount();
    }
  );
});
