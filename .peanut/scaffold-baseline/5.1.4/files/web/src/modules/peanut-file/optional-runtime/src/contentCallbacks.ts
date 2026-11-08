import { getToken } from '@/utils/auth';
import parseSurfaceCapabilities from '@/modules/peanut-content-tools/src/capabilities';
import type {
  FileResourceContent,
  SurfaceCallbacks,
  AuthorizedPreviewAccess,
} from '@/modules/peanut-content-tools/src';

export interface FileContentCallbackOptions {
  readonly documentId?: number;
  /** A fixed derivative selection, verified against the server's actual selected record. */
  readonly variantKey?: string | null;
  readonly viewerAdapterId: string;
  readonly canDownloadOriginal: () => boolean;
}

const record = (value: unknown): Record<string, unknown> => {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new Error('FILE_ACCESS_RESPONSE_INVALID');
  return value as Record<string, unknown>;
};

const deliveryUrl = (value: unknown): string => {
  if (typeof value !== 'string' || /\s|\\/.test(value))
    throw new Error('FILE_ACCESS_RESPONSE_INVALID');
  const url = new URL(value, window.location.origin);
  if (
    url.origin !== window.location.origin ||
    url.pathname !== '/api/storage/delivery' ||
    url.username !== '' ||
    url.password !== '' ||
    url.hash !== ''
  )
    throw new Error('FILE_ACCESS_RESPONSE_INVALID');
  return url.href;
};

/** File/Document supplies the identity and explicit tool choice; callbacks never store URLs. */
export const createFileContentCallbacks = (
  options: FileContentCallbackOptions
): SurfaceCallbacks => {
  if (
    options.variantKey != null &&
    !/^[a-z][a-z0-9-]{0,31}$/.test(options.variantKey)
  )
    throw new Error('FILE_VARIANT_KEY_INVALID');
  const resolve = async (
    content: FileResourceContent,
    operation: 'preview' | 'download_original',
    signal: AbortSignal
  ): Promise<Record<string, unknown>> => {
    const token = getToken();
    const response = await fetch('/adminapi/api/v1/files/resolve', {
      method: 'POST',
      credentials: 'same-origin',
      signal,
      headers: {
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({
        file_key: content.fileKey,
        operation,
        ...(operation === 'preview'
          ? {
              viewer_adapter_id: options.viewerAdapterId,
              ...(options.variantKey == null
                ? {}
                : { variant_key: options.variantKey }),
              ...(options.documentId === undefined
                ? {}
                : { document_id: options.documentId }),
            }
          : {}),
      }),
    });
    const envelope = record(await response.json());
    if (!response.ok || envelope.code !== 20000)
      throw new Error('FILE_ACCESS_DENIED');
    const data = record(envelope.data);
    if (data.state !== 'available') throw new Error('FILE_PREVIEW_UNAVAILABLE');
    if (
      data.file_key !== content.fileKey ||
      data.source_sha256 !== content.sourceSha256 ||
      data.purpose !== operation ||
      typeof data.media_type !== 'string' ||
      typeof data.expires_at !== 'number' ||
      !Number.isSafeInteger(data.expires_at) ||
      data.expires_at <= Date.now() ||
      !['original', 'derivative'].includes(String(data.bytes))
    )
      throw new Error('FILE_ACCESS_RESPONSE_INVALID');
    if (operation === 'preview') {
      const capability = parseSurfaceCapabilities(data.capabilities);
      if (
        capability.contentKind !== 'file_resource' ||
        !capability.canView ||
        !['available', 'read_only'].includes(capability.state) ||
        capability.viewerAdapterId !== options.viewerAdapterId ||
        capability.verifiedMimeType !== data.media_type ||
        (data.bytes === 'original' &&
          (data.media_type !== content.verifiedMimeType ||
            data.delivery_file_key !== content.fileKey ||
            data.variant_key !== null)) ||
        (data.bytes === 'derivative' &&
          (typeof data.variant_key !== 'string' ||
            !/^[a-z][a-z0-9-]{0,31}$/.test(data.variant_key) ||
            typeof data.delivery_file_key !== 'string' ||
            !/^file_[0-9a-f]{32}$/.test(data.delivery_file_key) ||
            data.delivery_file_key === content.fileKey)) ||
        (options.variantKey != null &&
          (data.variant_key !== options.variantKey ||
            data.bytes !== 'derivative'))
      )
        throw new Error('FILE_VARIANT_RECEIPT_INVALID');
    }
    if (
      operation === 'download_original' &&
      (data.bytes !== 'original' ||
        data.can_download_original !== true ||
        data.delivery_file_key !== content.fileKey)
    )
      throw new Error('FILE_ACCESS_RESPONSE_INVALID');
    deliveryUrl(data.url);
    return data;
  };

  return {
    resolvePreview: async (
      content,
      signal
    ): Promise<AuthorizedPreviewAccess> => {
      const data = await resolve(content, 'preview', signal);
      if (!options.canDownloadOriginal() && data.bytes !== 'derivative')
        throw new Error('FILE_PREVIEW_ORIGINAL_FORBIDDEN');
      return {
        url: deliveryUrl(data.url),
        fileKey: content.fileKey,
        sourceSha256: content.sourceSha256,
        mimeType: data.media_type as string,
        purpose: 'preview',
        bytes: data.bytes as 'original' | 'derivative',
        expiresAt: data.expires_at as number,
        dispose: () => {},
      };
    },
    downloadOriginal: async (content, signal): Promise<void> => {
      if (!options.canDownloadOriginal())
        throw new Error('FILE_DOWNLOAD_ORIGINAL_FORBIDDEN');
      const data = await resolve(content, 'download_original', signal);
      const response = await fetch(deliveryUrl(data.url), {
        credentials: 'same-origin',
        signal,
      });
      if (!response.ok) throw new Error('FILE_DOWNLOAD_ORIGINAL_DENIED');
      const blob = await response.blob();
      if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = content.displayName;
      anchor.click();
      setTimeout(() => URL.revokeObjectURL(url), 0);
    },
  };
};
