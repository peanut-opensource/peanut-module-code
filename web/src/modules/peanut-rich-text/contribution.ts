import { defineAsyncComponent } from 'vue';
import type { ContentToolsContribution } from '@/modules/peanut-content-tools/src/contracts';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

import localeRichTextEn from './views/locale/en-US';
import localeRichTextCn from './views/locale/zh-CN';

const contribution: ContentToolsContribution = {
  moduleKey: 'peanut.rich-text',
  contentAdapters: [
    {
      id: 'rich-text.tiptap',
      providerModuleKey: 'peanut.rich-text',
      adapterVersion: '1.0.0',
      protocolVersion: 'content-surface/1',
      contentKinds: ['rich_text'],
      mimeTypes: [],
      intents: ['view', 'edit'],
      environments: ['browser'],
      serviceDependencies: [],
      supportsCollaboration: true,
      unavailableReason: null,
      component: defineAsyncComponent(
        () => import('./components/RichTextSurface.vue')
      ),
    },
  ],
  locales: {
    'en-US': { ...localeRichTextEn },
    'zh-CN': { ...localeRichTextCn },
  },
  routes: [
    {
      path: '/rich-text',
      name: 'richText',
      component: DEFAULT_LAYOUT,
      meta: {
        locale: 'menu.richText',
        requiresAuth: true,
        icon: 'icon-edit',
        order: 6,
        tenantModuleKey: 'peanut.rich-text',
        requiredPermissions: 'peanut.rich-text.document.list',
      },
      children: [
        {
          path: 'documents',
          name: 'RichTextDocuments',
          component: () => import('./views/index.vue'),
          meta: {
            locale: 'menu.richText.documents',
            requiresAuth: true,
            tenantModuleKey: 'peanut.rich-text',
            requiredPermissions: 'peanut.rich-text.document.list',
          },
        },
      ],
    },
  ],
};

export default contribution;
