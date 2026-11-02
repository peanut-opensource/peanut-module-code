# Content Tools

`peanut.content-tools` owns trusted format registrations and `ContentViewer` / `ContentEditor`. File and Document remain responsible for current object permissions, access URLs, immutable versions and persistence.

The application calls `createLockedContentToolRegistry()` from the `./surface` export. This collects the same `virtual:peanut-plugin-contributions` objects already generated from `plugins.lock`; no remote scripts or second manifest are accepted. A frontend provider adds a typed `contentAdapters` array to its normal contribution and declares its dependency on this module. IDs are selected explicitly by application configuration and returned by the backend capability query. Missing/incompatible providers never silently select another implementation.

The application ModuleComposition collects typed ContentToolContributor registrations from compiled, selected Module providers, validates owner/duplicate identities before binding, and installs one complete registry. RichText contributes rich-text.tiptap; Content Tools never imports the concrete RichText provider. No provider instantiation side effect or registration-order selection is used.

Backend callers create `AuthorizedContentContext` only after current Document/File authorization and MIME verification, then call `ContentCapabilitiesService::resolve(context, intent, viewerId, editorId)`. It intersects provider availability with that authorization snapshot. It does not authenticate users or authorize objects and has no user-writable HTTP DTO. Access and save calls must authorize again.

Frontend callers pass the descriptor, parsed capabilities, owner identity partition `contextKey`, allowed operations, selected registry and controlled callbacks to `ContentViewer` / `ContentEditor`. `parseSurfaceCapabilities()` validates incoming capability response bytes. For File previews, `resolvePreview` must return a freshly authorized, expiring preview access object bound to the file key and immutable source hash. When original download is not allowed, the returned bytes must be a derivative; disabling browser download controls is only presentation and cannot protect original bytes. `dispose()` releases the temporary authorization, including when the response arrives after cancellation. Callers must honor `AbortSignal` and recheck permissions server-side.

The builtins are real browser image, PDF object from authorized attachment preview bytes (bounded to 32 MiB), audio and video viewers. No Office converter/editor is installed; unsupported/unknown formats display an unavailable state. An independently authorized `downloadOriginal` callback is optional and never becomes a preview fallback. Browser PDF/media decoding and File permission revocation still require integration evidence.

Providers emit `ready`, `dirty-change`, `save-request` and `error`. The surface emits `capabilities-changed` as the owner-supplied capabilities change. `save-request` is a request, not a persisted receipt; Document owns `save-state`. Identity, content identity, capability revision and operation changes destroy the old adapter session. Providers must destroy their editor/subscriptions on unmount.

## Consumer integration

Use the public `@peanut-admin/peanut-content-tools/surface` export. The existing File consumer is `web/src/modules/peanut-file/views/FileContentViewer.vue`, mounted by the File management page. It supplies File-authorized metadata and callbacks; this module does not add a separate administration page or persistence API.

```vue
<script setup lang="ts">
import {
  ContentViewer,
  type ContentSurfaceProps,
} from '@peanut-admin/peanut-content-tools/surface';

// The owning business supplies parsed capabilities and currently authorized operations.
const props = defineProps<ContentSurfaceProps>();
const emit = defineEmits<{ error: [code: string] }>();
</script>

<template>
  <ContentViewer v-bind="props" @error="emit('error', $event)" />
</template>
```

For a custom editor wrapper, forward the same `ContentSurfaceProps` and every
event. The host supplies the callbacks and the registry once; the wrapper does
not fetch capabilities or create a second editor/save service:

```vue
<script setup lang="ts">
import {
  ContentEditor,
  type ContentSurfaceProps,
  type SurfaceCapabilities,
} from '@peanut-admin/peanut-content-tools/surface';

const props = defineProps<ContentSurfaceProps>();
const emit = defineEmits<{
  ready: [];
  'capabilities-changed': [value: SurfaceCapabilities];
  'dirty-change': [value: boolean];
  'save-request': [value: unknown];
  error: [code: string];
}>();
</script>

<template>
  <ContentEditor
    v-bind="props"
    @ready="emit('ready')"
    @capabilities-changed="emit('capabilities-changed', $event)"
    @dirty-change="emit('dirty-change', $event)"
    @save-request="emit('save-request', $event)"
    @error="emit('error', $event)"
  />
</template>
```

Use the same forwarding for `ContentViewer` when its owner needs those events.
The existing surface selects the registered adapter for its fixed `view`/`edit`
intent; replacing the surrounding layout does not require registering another
adapter. A genuinely different format implementation contributes the existing
typed `contentAdapters` registration through its selected module provider.
The owning Document workflow supplies `callbacks.save` and interprets save
receipts. Do not persist again from a forwarded `save-request` when that workflow
already handles saving.

For a refusal example, pass the owner's parsed `permission_denied` capabilities,
or omit `edit` from `allowedOperations`: the editor displays the unavailable
authorization state instead of mounting an editable adapter. Keep that state;
do not add `edit`, swap an adapter or download original bytes to work around it.
Missing/incompatible adapters similarly remain unavailable. A callback failure
reports its existing error code; displaying `ready` or receiving `save-request`
does not establish persistence success.

The owner partitions `contextKey` by its current user/tenant/object context and creates the registry from the application's locked contributions. No URL, credentials or tenant selection is accepted from content JSON. `content` identifies the immutable source; `capabilities` is the parsed backend snapshot; `allowedOperations` further restricts that snapshot. Events report readiness, edits, save requests and error codes, never persisted success.

If a builtin preview fails or its access expires, **重新获取预览** calls `resolvePreview(content, signal)` again. It releases the previous access, aborts the old request and obtains new access through the same owner callback. There is no automatic retry, expired URL reuse or fallback to original bytes. Permission/provider changes still belong to the owner and may require refreshed capabilities. Original downloads show a busy label and an inline failure message while preserving the `CONTENT_DOWNLOAD_FAILED` event; changing the surface session clears that message. Builtin preview failures retain `CONTENT_PREVIEW_UNAVAILABLE`.
