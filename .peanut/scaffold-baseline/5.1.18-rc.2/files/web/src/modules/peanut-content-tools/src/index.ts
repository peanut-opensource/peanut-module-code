export { default as ContentViewer } from './ContentViewer.vue';
export { default as ContentEditor } from './ContentEditor.vue';
export { default as ContentToolRegistry } from './registry';
export { default as createLockedContentToolRegistry } from './contributions';
export { default as parseSurfaceCapabilities } from './capabilities';
export type {
  AuthorizedPreviewAccess,
  ContentDescriptor,
  ContentCollaborationBinding,
  ContentKind,
  ContentSurfaceProps,
  ContentToolsContribution,
  FileResourceContent,
  RichTextContent,
  SurfaceAdapterRegistration,
  SurfaceCallbacks,
  StableMediaReference,
  ResolvedMediaSurface,
  SurfaceCapabilities,
  SurfaceIntent,
  SurfaceState,
} from './contracts';
