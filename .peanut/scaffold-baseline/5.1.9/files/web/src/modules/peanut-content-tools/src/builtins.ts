import { defineAsyncComponent } from 'vue';
import type { SurfaceAdapterRegistration } from './contracts';

const component = defineAsyncComponent(() => import('./BasicFileViewer.vue'));
const builtinContentAdapters: readonly SurfaceAdapterRegistration[] =
  Object.entries({
    image: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
    pdf: ['application/pdf'],
    audio: ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/mp4'],
    video: ['video/mp4', 'video/webm', 'video/ogg'],
  }).map(([kind, mimeTypes]) => ({
    id: `builtin.${kind}`,
    providerModuleKey: 'peanut.content-tools',
    adapterVersion: '1.0.0',
    protocolVersion: 'content-surface/1',
    contentKinds: ['file_resource'],
    mimeTypes,
    intents: ['view'],
    environments: ['browser'],
    serviceDependencies: [],
    supportsCollaboration: false,
    unavailableReason: null,
    component,
  }));

export default builtinContentAdapters;
