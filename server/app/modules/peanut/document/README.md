# Business documents

`DocumentAcceptedCommitQuery` owns persisted collaboration baselines, original
store receipts and unfinished business checks. Consumers receive immutable arrays
within the original tenant transaction, not private Document Models. Instance
custody checks use a bounded explicit tenant/document pair without locking Doc or
entering a nested tenant Scope; business row locks retain Doc/State/operation order.

`DocumentCollaborativeWrites::loadAuthorizedCollaboration` returns both
`authorization` metadata and `content` from one current authorization and locked
Document/State read. Collaboration compares that metadata with its ticket before
returning content; the service binding and subsequent room/connection locks remain
inside the same transaction. This result is not an authorization shortcut across
RPC, request or transaction boundaries.

`peanut.document` owns business-bound document identities, solo projections,
committed versions and controlled references. A selected business module declares
its dependency on Document and contributes a `DocumentOwnerPolicy` through its
compiled provider. Article contributes only its existing `article/body` slot;
its publication and approval behavior remains owned by Article.

## Reusing the existing entry points

| Entry | Input and output | Existing consumer |
| --- | --- | --- |
| PHP `DocumentCommands` / `DocumentQueries` (declared in `contracts.exports`) | Current `TenantContext`, fixed `DocumentOwnerReference`, document ID and action-specific CAS/request ID; metadata, committed snapshot or durable receipt, never Models | Article's body service and compiled owner policy |
| `@peanut-admin/peanut-document/editor` | `DocumentEditor` and `DocumentSurfaceOptions`; emits load, capabilities, save-state, operation-state and error; exposes `prepareSaveTarget` / `releaseSaveTargetHold` | `peanut-article/views/list/ArticleBodyEditor.vue` uses the same component source |
| `@peanut-admin/peanut-document/viewer` | `DocumentViewer`, document ID, fixed owner and optional immutable revision ID; read-only content and load/error events | `peanut-article/views/list/ArticleBodyHistory.vue` uses the same component source |
| `@peanut-admin/peanut-document/api` | Existing typed HTTP queries/commands with an `AbortSignal`; Axios response containing metadata, versions or receipts | Article editor save-target recovery and body history pagination |
| `@peanut-admin/peanut-document/operations` | Existing `parseSaveTarget`, operation/result parsers and command types; invalid server results throw their `DOCUMENT_*` code | Article editor parses the original save-target response |

These package subpaths map to the existing module sources; they do not install a
second client or register routes. In a generated application, use its native
module package registration. The current Article consumer imports the corresponding
application source paths. No Article source or application assembly is changed by
these exports.

For an owner-authorized read surface:

```vue
<script setup lang="ts">
import { ref } from 'vue';
import { DocumentViewer } from '@peanut-admin/peanut-document/viewer';

const props = defineProps<{ documentId: number; articleId: number }>();
const error = ref('');
</script>

<template>
  <DocumentViewer
    :document-id="props.documentId"
    :owner="{ module_key: 'peanut.article', object_type: 'article', object_id: String(props.articleId), slot: 'body' }"
    :allow-downloads="false"
    @error="code => { error = code; }"
  />
  <p v-if="error" role="alert">{{ error }}</p>
</template>
```

The owner must already exist and be contributed by its selected provider. IDs and
frontend `businessActions` are not authority: the server checks the current actor,
tenant, owner and action again. View and edit-purpose metadata are distinct. The
editor entry also exports `DocumentOwner`, `DocumentDetail` and
`DocumentCapabilities` for typed owner metadata resolvers. Dispose caller-owned
HTTP requests with their `AbortController`; components abort their own requests
and unload collaboration on unmount or identity/context changes. Surface errors
must remain visible; unavailable collaboration is not an empty document.

Version creation requires a nonblank reason of at most 255 Unicode characters;
version restore requires a positive safe-integer revision ID. The operations UI
checks these fields before allocating a request ID or preparing a collaborative
target. Once submitted, an unknown result retains the original ID and exact input
for explicit recovery. Creating a version is not publishing the owning business.

The embedded surface renders its existing save-state events as Chinese status
text and shows the session member count with wrapping member chips. It retains
the original error information and frozen-content export. This is a display of
the existing lifecycle, not a new connection or save state machine. Remote cursors
and selections are not supported by the current provider (`awareness: null`).
There is no generic reconnect/reset button: use the host's existing recovery
entry only after resolving pending targets/business operations and unconfirmed
drafts. The surface does not clear them to recover a connection.

## Composition and authorization

### Component inputs and events

`DocumentSurfaceOptions` is the shared input contract. Pass `documentId`, the
server-fixed `owner`, and a `contextKey` partitioned by current account/member,
tenant and object. Optional `callbacks` are ContentTools `SurfaceCallbacks`;
`allowDownloads` defaults to false. `businessActions` only selects controls.
`metadataResolver(signal)` returns current `{ detail, capabilities }` from the
owner's edit-purpose endpoint; `resolveTransport(detail, capabilities, signal)`
returns the matching `AuthorizedDocumentTransport`. Neither callback grants access.

| Component | Additional inputs | Events and exposed operations |
| --- | --- | --- |
| `DocumentEditor` | `mode` defaults to `edit`; optional `readOnly`, `initialRevisionId` | `ready`, `load(DocumentDetail)`, `capabilities(SurfaceCapabilities)`, `save-state(DocumentSaveState)`, `operation-state(DocumentOperationFeedback)`, `error(code)`; exposes `prepareSaveTarget(requestId)` and `releaseSaveTargetHold()` |
| `DocumentViewer` | Optional fixed `revisionId`; always read-only | `ready`, `load`, `capabilities`, `error`; no save command |
| Internal `DocumentSurface` | Required `mode`, optional `readOnly`, `revisionId` | Same events as Editor; owns content adapter selection, solo save and the authorized provider lifetime |

Use the public Editor/Viewer wrappers for embedding. `DocumentSurface` is their
implementation, not an additional package subpath or a replaceable global
renderer. Custom content presentation goes through the selected ContentTools
adapter and existing callbacks. In templates, listen with kebab-case event names
as above. A `load` event may also report a later durable `state_seq`; it does not
change the owner's metadata baseline. `saved` confirms the current content only;
an explicit business Save, immutable version and publication are separate steps.
Article's editor/history show the complete existing composition; see
[Article's guide](../article/README.md#embedding-article-editing-and-history).

The public `DocumentOwnerRegistry` is an application-composition contract. The
composition root constructs it from the selected, compiled providers' owner
policy declarations and registers that instance in the native ThinkPHP container.
Request fields cannot contribute policy classes. Registry lookup selects the
policy; the policy and Document still perform current authorization, owner and
slot validation. This export does not expose other Document services or Models.

Every operation revalidates the durable Identity session, installation and tenant
module state, the current business owner and the requested action. Unknown owners
and slots are rejected. Creation and update metadata do not grant an actor ACL.
Document's HTTP entry exposes contextual commands and queries; it has no anonymous
or owner-free create endpoint. Article's `peanut.article.document` command uses
the server-fixed owner and body slot and does not convert the legacy Article body.

Solo rich text accepts the public `RichTextProjection` schema owned by RichText.
It stores a validated JSON projection with CAS and durable request receipts. It
does not construct Yjs state. Once collaboration is enabled, solo save fails closed.
Explicit version creation consumes a confirmed click-time target by the same
request ID; it does not drain the room or rewrite the current draft. Version
restore and lifecycle commands use the native provider's business barrier.
Its typed plan is a wire binding, not authorization.

## Business barriers

`DocumentAcceptedWriteProvider` owns five technical steps: prepare, drain,
lockDrained, consume and release. Document owns the business decision and state.
Prepare locks owner, Doc/state/operation, then Room/barrier; it records the original
actor and action/input hash and commits before any RPC. Drain fences native writes
and joins Node's saveMutex to persist the fixed merged capture. Previously lawful
grant-window updates may commit under service custody after revocation. Durable does not
mean a frozen version or an archived owner.

Before final mutation, the real current Session/owner decision is repeated between
Identity read-fence snapshots. The final same-PDO transaction locks those native
authorization/lifecycle inputs, owner and Doc/state/operation, then consumes the
provider's original drained target. Versions, current references, immutable
revision references, audit and operation result commit together. No SQL/owner lock
is held while waiting for Node. `runOwnerLifecycle` lets the original compiled
owner service perform its native mutation in that transaction for every slot;
the callback is not an HTTP input or permission grant.

Restore copies the old verified immutable Yjs binary and its matching projection
into a new CAS state and appends a new version. It never edits the old version or
merges old binary into live memory. Archive/restore/rollback advance the epoch and
closes old connections; a fresh Y.Doc verifies the committed binary/projection,
and the old SDK document must unload before release permits fresh connections.
Release occurs after the outer COMMIT. It is confirmed separately from business
completion; transport uncertainty keeps the room restricted.

`peanut.document.operation` exposes only the original operation's safe progress
and result to its original currently authorized actor. Retry the same action/key/
input after an unknown result. A pending gate is not unlocked by a deadline or
generic Room recovery, and retries never create a replacement operation. Failed
authorization may deny diagnostic writes too; the original prepared gate remains.
Solo reads/commands do not require a configured control endpoint. Collaboration
commands require the registered HTTPS control ingress and initialized room;
missing readiness/configuration is an explicit failure, never an empty document.

`DocumentQueries::versionSnapshot()` returns only an already committed immutable
version and refuses an outer transaction. `confirmed` means committed; it does not
mean publication or approval. Rich-text version digests hash the JSON-encoded tuple
`[contentKind, sourceStateSeq, canonicalProjectionJson, yjsSnapshotSha256|null,
immutableFileKey|null, immutableFileSha256|null]`. The canonical projection is
produced by `RichTextProjection`, independently of MySQL's JSON formatting. Reading
a version returns its own references, not the current draft's reference set.
Restoring a version appends another version.

An owner that prepares a version outside its transaction and then locks its own
record may call `DocumentQueries::verifyPreparedVersion(context, snapshot,
expectedOwner)` inside that same connection's transaction. All three parameters
are required; `DocumentOwnerReference` includes module, object type, ID and slot.
The native container's shared Document service recognizes only the original DTO
returned in the same outer Admin execution lifetime. New, cloned or serialized
DTOs are data, not proof. The private PHP WeakMap binds all snapshot fields,
current actor/session/revision, owner and opaque root identity, with a 120-second
monotonic lifetime. No proof crosses requests, containers, jobs or processes.
Its issued snapshot contains readonly scalars and decoded JSON arrays; verifying
that same object reuses the recorded canonical digest. Verification still reads
and hashes the persisted revision and repeats live authorization in its business
transaction, so this reuse does not extend across a persistence or RPC boundary.
Ordinary reads without an external Admin root still work but do not mint proof.
The existing prohibition on preparing a version in an outer transaction remains.

Business Save consumers use the narrower public owner-purpose entry:
DocumentQueries::ownerEditor requires the owner's edit action and exact fixed
owner, while ordinary detail and versionSnapshot keep their view authorization.
DocumentQueries::ownerSaveSnapshot prepares a committed version with version.create
authorization and binds that purpose into the same private proof. Its final
verifyPreparedVersion additionally requires the live Document sequence to equal
the immutable version's source sequence. Editing a draft after freeze therefore
cannot silently change or supersede the business Save.

DocumentCommands::runOwnerVersionSave starts a short same-connection transaction
using the existing Identity current-read fence and original compiled owner policy.
Its callback receives the freshly authorized TenantContext and locked
DocumentOwnerState; it must neither wait for RPC nor commit/change the connection.
The owner persists its own request before external freeze, then prepares the
snapshot outside a transaction and calls this entry again for atomic owner
metadata/fixed binding/receipt. VersionSnapshot::binding is a tenant-bound complete
snapshot digest and immutable version reference, not a permission or publication
grant. Autosave and accepted durable ACKs do not invoke the owner's Save callback.

Verification may repeat in two transactions during that same action. It repeats
live session, owner, Document and File authorization and locks owner, Document,
immutable revision, then sorted File objects. It compares the complete persisted
snapshot and digest; current draft edits do not replace historical references.
File's read-only reference and claimed-snapshot checks issue no delivery, change
no index and perform no provider I/O. SHA/MIME metadata checks do not constitute
a fresh physical-storage integrity measurement. System Task contexts cannot
become Admin Document proof; this contract does not enable a Document worker.

File versions capture the independently authorized original into a new private
File object before the owner transaction. Capture binds the source revision and
SHA-256, verifies MIME and actual copied bytes, and refuses an existing outer
transaction. The owner transaction then repeats authorization/CAS, writes persistent
references and claims the capture on the same ThinkORM connection. File references
and deletion use the same object lock. Sensitive sources retain the File-owned
`document.snapshot-sensitive` purpose and cannot inherit Document preview permission.
Preview never grants original download or file management permission.

Same-request failure compensates only its unclaimed new capture. A process crash
can leave a durable pending object or an unclaimed capture; it is not reported as
cleaned up. File's management-only pending-snapshot query lists old recoverable
objects, and its recovery command retries the original account/space configuration.
Real provider failure leaves `cleanup_pending`. Claimed or referenced history is
protected from recovery and deletion. Document V1 retains versions and prevents an
owner's permanent deletion while its protected documents remain.

The compiled optional `DocumentAcceptedWriteProvider` verifies native batch custody
and control, separate from current human owner policy. `DocumentCollaborativeWrites`
locks the structural owner, Document/state/operation, then invokes the provider's
Room/grant proof before sorted File locks. Missing providers fail closed. New
reference reservations also require the original authenticated references body and
the actual locked Document owner/current edit revision; DTOs are not permission.
Batch commits use recorded real grant windows rather than minting a revoked
human session. `DocumentAcceptedCommitQuery::assertStagedAcceptedCommit` reads the
already locked records on the same default PDO transaction and checks the exact
operation, full receipt, binary/projection, state sequence and derived references
before the provider advances its watermark. It neither takes reverse owner locks
nor depends on Document writes, File or the provider. Its staged proof is not an
outer-transaction committed ACK. Business barriers separately verify the staged
operation/result on that same PDO before their exact target is consumed.

Apply the new Document and File snapshot migrations through the native module
installation/upgrade lifecycle. Existing RichText records, tables and migrations
are preserved. Single/Multi Edition qualification, collaborative Yjs persistence,
browser editors/viewers and external-storage migration are separate integration
checks; this module's solo persistence implementation does not prove those paths.

## Revocable fixed sources for native Task consumers

Version 1.0.17 exports `DocumentSourceGrants`. Ordinary Document reads and writes
remain available without Task; the optional native Task integration requires
Task 1.0.30 and File 1.0.44.

A consumer first checks source eligibility before creating preparation records or
reserving quota. It then publishes the real member-authorized native job, stores
its business-to-job binding and calls `issueTaskSource` in the same Admin database
transaction with the prepared `VersionSnapshot`, owner and `SourceTaskBinding`.
An arbitrary job key, copied member context or serialized binding is insufficient.

The durable grant fixes the tenant, original member/session, owner state,
Document authorization revision, committed version digest, consumer operation,
handler and actual native job. Its expiry is at most 24 hours. The original issuer
may revoke after losing source visibility; another member needs current owner
edit authority. Revocation and request replay records are retained in Document's
own source-grant table. Membership is checked through Identity's public session
contracts, without a foreign key or query against Identity's private tables.

Inside the actual native worker, `redeemTaskSource` returns the authoritative fixed
snapshot only after live Task, member, tenant, owner and source checks. It attaches
those checks to subsequent execution fences, including the consumer's final
authorization check before an external effect. File-backed sources also require
current original-download permission, a claimed immutable Document snapshot and
matching stored bytes. Text that contains File references currently fails with
`DOCUMENT_SOURCE_REFERENCES_UNSUPPORTED`; references are never silently omitted.

The first consumer is `custom.ai-assistant`. Its preparation, explicit retry and
cancellation retain their business request identities and native Task revisions.
A source grant does not authorize publication, acceptance, payment or a new
external request after an unknown result.

## Fixed revision comments

The public `DocumentRevisionComments` contract adds persistent threads, replies, resolution, and reopening on a committed immutable rich-text revision. Read a real version through `DocumentQueries` and pass its document/revision IDs to `threads()`. The service returns the fixed revision number, current owner, exact plain text, its profile/digest, and current write capability.

`threads()` and `thread()` require current owner view (the existing archive-read policy applies to archived documents). Every command, including request replay, independently requires current owner edit and view. Tenant/member/account identity comes from the native validated context. The service uses Identity's public read fence, locks the owner before Document and comment rows, and verifies the same native prepared version again before commit. It does not require Collaboration to be enabled.

After view authorization succeeds, the read response reports `can_write: false` when the separate edit-capability check returns a forbidden (403), edit-scope miss (404), or business-state conflict (409). This lets a currently viewable owner expose its fixed revision comments while editing is unavailable. The flag is current UI guidance, not an authorization grant. Initial, locked and final view checks remain independent; missing read documents, owners, revisions and threads still fail. Unauthenticated (401), server errors and unexpected exceptions also propagate. Create/reply/resolve/reopen and exact original-request replays keep their independent edit check and its original 404 or 409 rejection. Restoring edit scope or unlocking the owner restores the current write flag without changing the fixed revision or existing receipts.

The source text is the public RichText `plainText()` result for `peanut.richtext.plain-text/1`. Offsets use Unicode code points in a half-open `[start_offset,end_offset)` interval. Chinese and astral emoji each count as one; a combining mark counts separately. No Unicode normalization, trimming, or automatic movement to another revision occurs. The exact quote must equal that interval; quotes are limited to 4096 code points and comment bodies to 4000. File-backed documents are unsupported; referenced resources in rich text retain the existing public File read checks.

A create input contains `request_id`, `version_digest`, `text_profile`, `text_sha256`, `start_offset`, `end_offset`, `quote`, and `body`. Reply/resolve/reopen inputs carry the same source binding, a `thread_id`, and the actual `expected_thread_revision`; only reply includes `body`. Unknown fields, including actor/tenant overrides, are rejected. Replies and resolve require an open thread; reopen requires a resolved thread.

Each successful command atomically appends one immutable activity and one immutable request receipt. The thread revision increases by one. The same request and exact input from the same member/account returns its original receipt after fresh authorization; changed input/action/actor conflicts. A replay receipt describes that original write, so consumers must reload current thread state instead of applying the receipt as the latest revision. A failed transaction leaves no thread/message/receipt. Concurrent requests serialize on the existing Document/owner locks; this feature adds no background work.

The append-only migration owns `pa_document_comment_thread`, `pa_document_comment_message`, and `pa_document_comment_operation`. Its foreign keys refer only to Document-owned tables. Member existence/permissions are checked through current Identity contracts; there are no Identity private-table queries or new cross-module foreign keys. Comments never write the document body, invoke restore, or bypass the business save barrier.

### Public component

Import the independent component or opt into the existing viewer export:

```vue
<script setup lang="ts">
import { DocumentRevisionComments } from '@peanut-admin/peanut-document/viewer';
</script>

<template>
  <DocumentRevisionComments
    :document-id="documentId"
    :revision-id="fixedRevisionId"
    :context-key="currentTenantMemberContextKey"
    :owner="owner"
  />
</template>
```

`DocumentViewer` also accepts `comments`, an explicit `revisionId`, and a nonempty `contextKey`. The host changes the context key when the identity/authorization context changes; it is a UI invalidation key, never authorization evidence. The panel displays the fixed version, exact quote, member IDs, messages, status and thread revision, and uses the actual contextual HTTP endpoints. It renders all content as text.

The component uses the existing authenticated Axios client. It clears stale content after read failures or revocation, discards late reads, and retains immutable pending commands by context/revision for its lifetime. An unknown write retries the original request unchanged. A later denial cannot turn an earlier unknown outcome into a safe new request. Acknowledgements from a different context cannot select or overwrite its thread; successful writes reload current state. Pending client commands are in memory, not persisted across a full page reload or component destruction; server receipts remain durable and callers retaining the original input can recover them through the same command.

HTTP methods: GET `peanut.document.comments`, GET `peanut.document.comment.thread`; POST `peanut.document.comment.create`, `reply`, `resolve`, and `reopen`. All remain behind the native login/module/contextual access boundary. The host's versioned authenticated-route registry must contain those exact six method/path pairs.

This slice does not implement live editor annotations, moving anchors, suggestions, range authors, cursors, notifications, or a diff engine. The RichText projection still rejects nonempty editor annotations.
