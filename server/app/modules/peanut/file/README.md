# File composition

## Private business evidence

`FileBusinessEvidence` is the public capability for private evidence whose owner is a
real business command or immutable financial fact, rather than a Document. It is
implemented by File's existing `StorageService` and exported/bound by this module.
Existing File APIs retain their contracts. This capability does not restore the
removed `FilePrivateEvidence` API; consumers must explicitly adopt the new contract.

The concrete consumer is DCS settlement: supplier payment upload, report,
verification, reversal and evidence amendment. DCS owns business purpose, accounting
scope, target ownership, financial permission, command input/basis digests and its
fact/outbox persistence. File owns inspected name/MIME/size/SHA-256, durable object
identity, private storage, current session and independent File permissions, and
reference protection. A callback is trusted application code, never an HTTP input or
a substitute for authorizing a persisted business record. Module/owner/key strings
alone confer no authority. Document and File cannot be impersonated as business owners.

1. After its business precheck, the consumer calls `inspectEvidence(UploadFile)` and
   incorporates the returned metadata into its command digest. HTTP size, MIME and
   extension fields are not trusted. File applies the existing generic-file extension
   allowlist and actual byte limit; DCS must impose any narrower evidence rules.
2. DCS prepares its business command with that digest in its own transaction.
   `storeEvidence(actor, command, file, authorize, bind, publish)` runs outside that
   transaction and re-inspects the bytes. `authorize()` must lock/revalidate the
   business command, digest, actor, purpose, target and current accounting scope.
   It runs in each short reservation/publication transaction and before the PUT.
3. File atomically reserves one random `file_key` and object path per
   `(tenant, module, command)`, with the digest and initiating account/member/client.
   `bind(reservedSnapshot)` must CAS the persisted business `prepared` record to
   `reserved` with exactly that key/metadata. Reservation, binding, the protective
   upload-root reference and audit succeed or roll back together, before provider I/O.
4. Only the reservation creator attempts PUT, once. File verifies the original
   stored bytes, size and content MIME. `publish(publishedSnapshot)` must CAS the
   business reservation and persist its result/outbox on the default ThinkPHP
   connection. Those writes and File's `ready` state commit in the same transaction.
   Callbacks must throw on denial, stale revision or any failed CAS; returning false
   is not cancellation. No provider I/O runs inside these business transactions.

`observeEvidence(actor, command, authorize, publish)` uses the original reservation
and current authority. A null result means no committed reservation; `reserved`
means the result is still unknown; `published` means File and business publication
committed together. A provider exception or missing object leaves the original key
reserved, without deleting or rewriting it. Absence does not prove that an earlier
writer cannot complete. Observation verifies the real original bytes and can finish
publication after a lost acknowledgement or rolled-back publication. An already
published result does not call `publish` again or require provider I/O. Repeated
store calls cannot allocate another key or repeat PUT. A changed digest, actor,
name or byte metadata is rejected. Native DB serialization/deadlock errors still
propagate: after rollback, reconcile the same command with `observeEvidence`; do
not invent a new command/key or blindly repeat the write. A reservation interrupted
before PUT can remain unknown/absent; this contract adds no automatic retry,
expiry, compensation, deletion or operator reconciliation workflow.

`retainEvidence(actor, BusinessEvidenceFact, authorizeFact)` requires the caller's
existing business transaction and nonempty, unique file keys. The callback receives
File-produced snapshots, locks and authorizes the persisted financial fact, and
must check its exact file metadata, basis digest, ownership and current scope.
The callback takes business locks before File locks. File then locks the sorted
objects, requires published private evidence uploaded by that same module, and
independently checks preview and original-download permissions. The fact header
uniquely binds `(tenant, module, owner type, owner key)` to its basis digest and exact
snapshot set. Identical calls are idempotent; changing either digest or files is
rejected. Any error must roll back the financial transaction. The existing File
reference index protects uploads and retained facts against deletion; there is no
public release/replacement operation. Amendments/reversals retain their own immutable
fact identity. Business access does not grant File preview/original-download rights,
and this contract introduces no contextual download inheritance or delivery URL.

The module adds only `pa_file_evidence_command` and `pa_file_evidence_fact`; byte
metadata and deletion protection reuse `pa_file_object` and `pa_file_reference`.
The incremental module migration must be applied through the native application
upgrade entry before adoption; changing module source does not apply it.

Focused, isolated verification (no existing project database or service):

```sh
php server/vendor/bin/phpunit --no-configuration --bootstrap server/vendor/autoload.php \
  --do-not-cache-result server/tests/Modules/PeanutFile/BusinessEvidenceTest.php
```

These tests exercise this source, real ThinkORM transactions, File authorization and
LocalStorageDriver bytes with a temporary SQLite database. Identity/module/RBAC
queries and audit persistence are synthetic boundaries; provider faults are injected
around the native local driver. Forked workers cover competing reservation and
observation during an active write. SQLite does not qualify MySQL row/gap locks,
MySQL migration execution, cloud providers or DCS adoption/business acceptance.

## Original audio uploads

The existing generic file type `30` accepts original `mp3`, `wav` and `ogg` files through `FileUploads::file` and the normal `peanut.file.upload.file` endpoint. `Contract/UploadExtensions::FILE` owns this allowlist; the host `FileEnum` delegates to it. The existing 50 MiB limit, tenant scope, upload permission, storage and verified media handling remain in force. This does not add transcoding, audio workflow rules or a new database file type.

The File page and host file picker both consume `fileUploadAccept` from this module's `api.ts`; custom selectors may reuse it as a browser hint. Server validation remains authoritative. Media applications should adopt this module and its host bridge instead of retaining private extension arrays in managed `FileEnum` or `file-picker` files.


## Reuse entries

The management page `/system/file` uses the module's `web/src/modules/peanut-file/api.ts`
for paginated files, category management and upload. File and category loading
have separate error states and explicit retry actions; a failed category request
does not prevent browsing files. Loading a different filter or page clears the
previous result and selection. Cancellation and superseded responses do not show
a failure for the current request.

The frontend package exports `@peanut-admin/peanut-file/optional-runtime`:

- `FileAssetSelector` takes `items: readonly AssetCandidate[]`, optional
  `selectedFileKey`, `loading`, `error` and `disabled`; it emits `select(asset)`
  and `retry`. It performs no HTTP or persistence itself.
- `createFileAssetRuntime({ canRead, request? })` exposes reactive `state`,
  `load(page?)`, `select(asset)` and `dispose()`. Its default request reads
  `GET /adminapi/api/v1/files/assets?page_no=1&page_size=24` with the current
  token. The canonical response is `{ data: { items }, meta: { request_id,
  page, page_size, total } }`, validated by `parseAssetList`. A custom request
  receives `(page, pageSize, signal)` and returns a native `Response`.
- `createFileContentCallbacks` provides authorized preview/original-download
  callbacks for the selected Content Tools viewer. It resolves current delivery
  on demand; preview permission does not imply original-download permission.

`FileMediaPage` is the actual selector/runtime consumer registered at
`/system/file/assets`; `views/FileContentViewer.vue` consumes content callbacks.
For a selector embedded in an existing authorized page:

```vue
<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import { FileAssetSelector, createFileAssetRuntime } from '@peanut-admin/peanut-file/optional-runtime';

const props = defineProps<{ canRead: boolean }>();
const emit = defineEmits<{ select: [fileKey: string] }>();
const runtime = createFileAssetRuntime({ canRead: () => props.canRead });
onMounted(() => runtime.load());
onBeforeUnmount(runtime.dispose);
</script>

<template>
  <FileAssetSelector
    :items="runtime.state.items"
    :selected-file-key="runtime.state.selectedFileKey"
    :loading="runtime.state.loading"
    :error="runtime.state.error?.message ?? null"
    :disabled="!props.canRead"
    @retry="runtime.load()"
    @select="runtime.select($event); emit('select', $event.fileKey)"
  />
</template>
```

The host owns identity/tenant lifecycle: dispose the previous runtime on identity
or tenant change and create/load a runtime for the new context. `FileMediaPage`
already clears/reloads on its current session/account/tenant/permission context.
`load` cancels the
previous request; `dispose` cancels pending work and clears assets, selection,
pagination, loading and error state.
`state.error` carries a message, HTTP status and request ID when available;
network/invalid-response failures have no HTTP status. This optional transport
uses native fetch; it does not provide the management Axios interceptor's token
refresh flow. Do not persist preview/delivery URLs. Persist only `fileKey`.

If the owner already loaded authorized `AssetCandidate[]`, pass that data and its
loading/error/selection state directly to `FileAssetSelector`; handle `select` and
`retry` in the owner. Do not also create/load a runtime for the same list. In the
example above, this owner performs the single initial load; do not additionally
mount `FileMediaPage`, which owns its own load.

For a refusal example, `canRead: () => false` makes `runtime.load()` set
`state.error.status` to 403 without issuing a request. Render that error and keep
the selector disabled. An HTTP 403 from the server is also an error, never an
empty successful result. The host must dispose the old runtime when permission or
identity changes; disabling a button alone does not revoke access to old data.

Choose the entry by the consumer's stored contract. The existing host
`web/src/components/file-picker/index.vue` serves user settings and PC/mobile/tabbar
decoration: it supports categories, upload, image/video/file types and multi-select,
and emits `select(urls, FileRecord[])`. Those consumers retain that native entry.
`FileMediaPage` and new stable-reference image-asset consumers use
`FileAssetSelector` and `fileKey`. These are different data contracts; do not mount
both pickers for one selection, convert a delivery URL into a stable reference, or
replace the host picker with an asset-only list that drops its required features.

Visibility changes remain the separate management workflow described below.
Selecting an asset never changes visibility; preview access never grants
`peanut.file.visibility.change`. Reuse `views/FileVisibilityDialog.vue` through
the existing File management page for preflight/confirmation/progress rather than
adding a visibility write to `select` or a second batch-submission path.

Backend consumers inject declared contracts from `PeanutAdmin\Modules\File\Contract`
through the compiled module's native provider bindings, rather than reading File
Models. `FileUploads::image/video/file` takes the authenticated context,
`Dto\UploadFile`, category and source and returns upload metadata;
The host's `server/app/api/controller/UploadController.php` already consumes
`FileUploads` for member uploads.
`FileAccess::resolve($context, $fileKey, 'preview')` returns authorized access
metadata or an unavailable result; missing permission/session/module or invalid
resource fails through the existing business error boundary. Tenant context must
come from the authenticated entry, never request-supplied tenant IDs. The
remaining sections specify the existing reference and visibility contracts.

The public `FileDocumentReferenceRegistry` is an application-composition contract.
The composition root constructs it from the selected, compiled provider's
`FileDocumentReferenceContributor` declaration and registers that instance in the
native ThinkPHP container. Requests cannot supply or replace its policy class.
An absent contribution rejects document-reference authorization; registry lookup
does not grant preview, original download or management permission. The selected
Document policy and File services retain their current authorization checks.

This export supports the existing host composition boundary. Other File services
and Models remain private unless separately declared as public module contracts.

Document's prepared-version verifier uses
`FileReferenceCommands::assertDocumentReferencesReadable` inside its native owner
transaction. This checks stable keys and the current actor, locks File objects in
sorted order and repeats the existing independent or Document-inherited preview
authorization. It neither replaces the reference index nor issues delivery.
`FileDocumentSnapshots::assertDocumentSnapshotReadable` additionally checks the
private immutable object's claimed Document, capture ledger and SHA/MIME metadata
under those locks. Sensitive snapshots retain their independent File permission
requirement. Neither method reads provider bytes or proves current physical
storage integrity; current session/module checks and deletion protection remain.

`FileAccess::attachmentSnapshot` returns only ready source metadata (stable key,
original name, verified MIME, size and SHA-256) under the current human Admin
session, File module and independent preview permission. It creates no URL,
delivery grant, audit entry or provider IO, and does not confer original-download
permission or Document prepared proof. System workers cannot use it to acquire
human File authority. Malformed registered metadata is rejected; this read is not
a fresh physical byte inspection.

Available preview delivery receipts include the actually selected `variant_key`
(null for original bytes). A fixed callback selection must match that key and a
different derivative File object. Delivery MIME is checked against the server's
authorized Content Tools capability, so a verified PNG derivative of a JPEG
source is allowed; an original still must match its source MIME. No original or
alternate variant is substituted when the requested derivative is unavailable.

Collaboration reserves candidate keys through `FileReferenceCommands` only after
Document's compiled policy proves the original authenticated references request,
real connection grant and live owner edit permission. `CollaborationReferenceInput`
is data, not authority; a digest alone cannot reserve an arbitrary File key.
Only non-sensitive current Document references may inherit preview. New,
historical-only and sensitive references require independent File preview permission.
File-owned `document-collaboration` rows protect deletion before broadcast.
Explicit clicked targets use separate `document-target` pins before awaiting store;
they consume only actual current references or verified source reservations.
Batch transfer and exact captured reservation release require original same-PDO
Document proofs and commit with content/receipt. Absent/pending references survive
until an actual epoch fence, and unknown targets retain pins until consumption or
explicit cancellation. Persisted history references protect consumed targets;
these methods create no delivery URL or original-download permission.

## Visibility batches

`FileVisibility` provides read-only preflight, whole-batch admission, durable
per-object progress and explicit recovery. Management additionally requires
`peanut.file.visibility.change`. The media page offers **设为私有** for selected
resources and **可见性任务** for existing batches. Preflight includes the connected
source/derivative closure, current revisions, storage locations and the digest
and count of registered references. Its five-minute confirmation is bound to the
current actor/session/authorization revision. Unknown external uses and already
downloaded or third-party cached copies are explicitly outside recall guarantees.

All objects must still match the confirmed plan before any batch is accepted.
The durable intent, restricted object states and audit commit together. Existing
delivery grants fail current status/revision checks immediately. Each object then
records its private copy, location switch, original-location closure and byte
verification separately; `file_key` and existing references stay unchanged. A
failed/unknown physical step remains restricted and never rolls back to public.
The same batch's explicit resume inspects the fixed original and target locations
before doing the next step. A timeout is followed by a status query, not another
batch submission. The ordinary upload purpose remains its default route, rather
than an override of an existing object's current visibility.

The current complete physical route is local `public/storage` to
`private/storage`, with controlled delivery and direct-storage denial in both
the PHP development ingress and deployment Nginx configuration. Private bytes
are verified before source deletion; interrupted copies are reconciled at their
fixed private target. Other origins reject preflight until their actual version,
Bucket-policy and CDN closure can be qualified; provider ACL acknowledgement
alone is insufficient. This source support does not claim object-storage/CDN
qualification or successful runtime acceptance.

Two fixed native migrations create the batch/item tables and then widen the
object status field/add its operation binding. They use the existing File row
preservation reader and native same-stage ledger capability. Every original row,
allocator and unrelated schema component is retained; the second stage accepts
only its fixed nullable column/status/CHECK delta. Legacy File adoption accepts
the extended shape only with both actual executed migration rows and its exact
schema. Backups cover the selected File recovery graph; restoring an old database
after normal business has resumed is not an automatic operation. Business recovery
continues the accepted private transition and does not republish an old source.

## Checking fixed Document sources in a native worker

Version 1.0.44 exports `FileDocumentSourceChecks`. Its
`assertBackgroundDocumentSnapshotReadable` operation verifies the current
member's independent original-download permission, tenant and Document binding,
claimed immutable snapshot, MIME type, SHA-256 and materialized source bytes.
It returns no bytes, URL, transferable grant or task authority.

Document must establish and recheck its native Task binding around this call.
File has no Task dependency and does not manufacture a member session for a
background operation. Normal previews, downloads and Document snapshot APIs
retain their existing authorization paths.
