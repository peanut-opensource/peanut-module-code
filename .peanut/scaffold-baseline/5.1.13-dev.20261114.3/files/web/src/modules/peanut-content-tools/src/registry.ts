import type {
  ContentDescriptor,
  ContentToolsContribution,
  SurfaceAdapterRegistration,
  SurfaceIntent,
} from './contracts';
/** Collected from the existing locked contribution array, never a URL/script loader. */
export default class ContentToolRegistry {
  private readonly adapters = new Map<string, SurfaceAdapterRegistration>();

  constructor(contributions: readonly ContentToolsContribution[]) {
    contributions.forEach((contribution) => {
      (contribution.contentAdapters ?? []).forEach((registration) => {
        if (
          registration.providerModuleKey !== contribution.moduleKey ||
          !/^[a-z][a-z0-9.-]+$/.test(registration.id) ||
          !/^\d+\.\d+\.\d+$/.test(registration.adapterVersion) ||
          registration.protocolVersion !== 'content-surface/1' ||
          registration.contentKinds.length === 0 ||
          registration.intents.length === 0 ||
          registration.environments.length === 0 ||
          registration.contentKinds.some(
            (kind) => kind !== 'rich_text' && kind !== 'file_resource'
          ) ||
          registration.intents.some(
            (intent) => intent !== 'view' && intent !== 'edit'
          ) ||
          registration.mimeTypes.some(
            (mime) => !/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/.test(mime)
          ) ||
          (registration.contentKinds.includes('file_resource') &&
            registration.mimeTypes.length === 0) ||
          (typeof registration.component !== 'object' &&
            typeof registration.component !== 'function') ||
          registration.component === null
        ) {
          throw new Error('CONTENT_ADAPTER_INVALID');
        }
        if (this.adapters.has(registration.id))
          throw new Error('CONTENT_ADAPTER_DUPLICATE');
        this.adapters.set(registration.id, registration);
      });
    });
  }

  selected(
    id: string | null,
    content: ContentDescriptor,
    intent: SurfaceIntent
  ): SurfaceAdapterRegistration | null {
    const adapter = id === null ? undefined : this.adapters.get(id);
    if (
      !adapter ||
      adapter.unavailableReason !== null ||
      !adapter.environments.includes('browser') ||
      !adapter.contentKinds.includes(content.kind) ||
      !adapter.intents.includes(intent) ||
      (content.kind === 'file_resource' &&
        !adapter.mimeTypes.includes(content.verifiedMimeType))
    )
      return null;
    return adapter;
  }
}
