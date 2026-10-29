# Business documents

`DocumentAcceptedCommitQuery` owns persisted collaboration baselines, original
store receipts and unfinished business checks. Consumers receive immutable arrays
within the original tenant transaction, not private Document Models. Instance
custody checks use a bounded explicit tenant/document pair without locking Doc or
entering a nested tenant Scope; business row locks retain Doc/State/operation order.

`peanut.document` owns business-bound document identities, solo projections,
committed versions and controlled references. A selected business module declares
its dependency on Document and contributes a `DocumentOwnerPolicy` through its
compiled provider. Article contributes only its existing `article/body` slot;
its publication and approval behavior remains owned by Article.

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
