import { defineAsyncComponent } from 'vue';
import type { ContentToolsContribution } from '@peanut-admin/peanut-content-tools/surface';

const contribution: ContentToolsContribution = {
  moduleKey: 'peanut.rich-text',
  routes: [],
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
};

export default contribution;
