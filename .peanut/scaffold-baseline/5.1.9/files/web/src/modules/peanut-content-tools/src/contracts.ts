import type { Component } from 'vue';
import type { ModuleFrontendContribution } from '@/module-contributions';

export type ContentKind = 'rich_text' | 'file_resource';
export type SurfaceIntent = 'view' | 'edit';
export type SurfaceState =
  | 'available'
  | 'read_only'
  | 'preview_unavailable'
  | 'edit_unsupported'
  | 'service_unavailable'
  | 'permission_denied';

export interface RichTextContent {
  kind: 'rich_text';
  identity: string;
  value: unknown;
}
export interface FileResourceContent {
  kind: 'file_resource';
  identity: string;
  fileKey: string;
  verifiedMimeType: string;
  sourceSha256: string;
  displayName: string;
}
export type ContentDescriptor = RichTextContent | FileResourceContent;

export interface SurfaceCapabilities {
  contentKind: ContentKind;
  verifiedMimeType: string | null;
  viewerAdapterId: string | null;
  editorAdapterId: string | null;
  canView: boolean;
  canEdit: boolean;
  canDownloadOriginal: boolean;
  state: SurfaceState;
  unavailableReason: string | null;
  capabilityRevision: string;
}

/** Access is freshly authorized by File/Document; never stored in content JSON. */
export interface AuthorizedPreviewAccess {
  url: string;
  fileKey: string;
  sourceSha256: string;
  mimeType: string;
  purpose: 'preview';
  bytes: 'derivative' | 'original';
  expiresAt: number;
  dispose: () => void;
}
export interface StableMediaReference {
  fileKey: string;
  kind: 'image' | 'video' | 'audio' | 'file';
  variantKey?: string;
  alt?: string;
  title?: string;
}
export interface SurfaceCallbacks {
  /** Owner/File picker returns stable references, never delivery URLs. */
  pickMedia?: (signal: AbortSignal) => Promise<StableMediaReference | null>;
  /** Owner supplies freshly authorized metadata/capabilities; uses File callbacks for bytes. */
  resolveMedia?: (
    reference: StableMediaReference,
    signal: AbortSignal
  ) => Promise<{
    content: FileResourceContent;
    capabilities: SurfaceCapabilities;
    allowedOperations: readonly ('view' | 'edit' | 'download_original')[];
    callbacks: SurfaceCallbacks;
  }>;
  resolvePreview?: (
    content: FileResourceContent,
    signal: AbortSignal
  ) => Promise<AuthorizedPreviewAccess>;
  downloadOriginal?: (
    content: FileResourceContent,
    signal: AbortSignal
  ) => Promise<void>;
  /** The owner persists and returns its own receipt; tools only request saves. */
  save?: (value: unknown, signal: AbortSignal) => Promise<void>;
}
export type ResolvedMediaSurface = Awaited<
  ReturnType<NonNullable<SurfaceCallbacks['resolveMedia']>>
>;
export interface SurfaceAdapterRegistration {
  id: string;
  providerModuleKey: string;
  adapterVersion: string;
  protocolVersion: 'content-surface/1';
  contentKinds: readonly ContentKind[];
  mimeTypes: readonly string[];
  intents: readonly SurfaceIntent[];
  environments: readonly string[];
  serviceDependencies: readonly string[];
  supportsCollaboration: boolean;
  unavailableReason: string | null;
  component: Component;
}
export interface ContentToolsContribution extends ModuleFrontendContribution {
  contentAdapters?: readonly SurfaceAdapterRegistration[];
}
export interface ContentCollaborationBinding {
  protocol: 'yjs/1';
  document: unknown;
  generation: string;
  ready: boolean;
  /** Local edit permission; a save target may temporarily hold the initiating editor. */
  writable?: boolean;
  /** Disconnected local state retained for explicit export; never treated as synced. */
  frozen?: boolean;
  saved: boolean;
}

export interface ContentSurfaceProps {
  content: ContentDescriptor;
  capabilities: SurfaceCapabilities;
  /** Owner-controlled identity partition; changing it destroys the old session. */
  contextKey: string;
  allowedOperations: readonly ('view' | 'edit' | 'download_original')[];
  callbacks: SurfaceCallbacks;
  registry: import('./registry').default;
  /** Owner owns the CRDT and its lifetime. Providers validate the document type. */
  collaborationBinding?: ContentCollaborationBinding;
}
