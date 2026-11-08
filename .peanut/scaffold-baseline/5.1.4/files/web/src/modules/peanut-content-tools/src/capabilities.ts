import type { SurfaceCapabilities, SurfaceState } from './contracts';

/** Decode actual API bytes; generated TypeScript alone does not validate a response. */
const parseSurfaceCapabilities = (value: unknown): SurfaceCapabilities => {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new Error('CONTENT_CAPABILITIES_INVALID');
  const item = value as Record<string, unknown>;
  const nullableText = (candidate: unknown): candidate is string | null =>
    candidate === null ||
    (typeof candidate === 'string' && candidate.length > 0);
  const { state } = item;
  const validState = (candidate: unknown): candidate is SurfaceState =>
    candidate === 'available' ||
    candidate === 'read_only' ||
    candidate === 'preview_unavailable' ||
    candidate === 'edit_unsupported' ||
    candidate === 'service_unavailable' ||
    candidate === 'permission_denied';
  if (
    (item.contentKind !== 'rich_text' &&
      item.contentKind !== 'file_resource') ||
    !nullableText(item.verifiedMimeType) ||
    !nullableText(item.viewerAdapterId) ||
    !nullableText(item.editorAdapterId) ||
    !nullableText(item.unavailableReason) ||
    typeof item.canView !== 'boolean' ||
    typeof item.canEdit !== 'boolean' ||
    typeof item.canDownloadOriginal !== 'boolean' ||
    !validState(state) ||
    typeof item.capabilityRevision !== 'string' ||
    item.capabilityRevision.length === 0 ||
    (item.contentKind === 'file_resource' &&
      (item.verifiedMimeType === null ||
        !/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/.test(
          item.verifiedMimeType
        )))
  )
    throw new Error('CONTENT_CAPABILITIES_INVALID');
  return {
    contentKind: item.contentKind,
    verifiedMimeType: item.verifiedMimeType,
    viewerAdapterId: item.viewerAdapterId,
    editorAdapterId: item.editorAdapterId,
    canView: item.canView,
    canEdit: item.canEdit,
    canDownloadOriginal: item.canDownloadOriginal,
    state,
    unavailableReason: item.unavailableReason,
    capabilityRevision: item.capabilityRevision,
  };
};

export default parseSurfaceCapabilities;
