# File composition

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
