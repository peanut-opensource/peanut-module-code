# Document Collaboration

This optional module supplies native Hocuspocus 4.6 protocol 2 authentication,
connection authorization windows, special File reservations and batch custody.
Document owns durable content, immutable explicit save targets, versions and
committed receipts. Ordinary updates merge/broadcast before batch persistence;
a broadcast or native SyncStatus is not a database commit.

## Service authentication

The managed application configuration supplies the fixed instance/service/key,
origins, secret and CA reference. Explicit HTTP/WS is accepted only when the
application's APP_ENV is development; HTTPS/WSS keeps normal CA verification.
No browser selects these values. Internal
calls reject ambient human/Task identity and authenticate only the service.

Transport envelope version is 2. Request HMAC-SHA256 input is UTF-8 lines
separated by LF, without a final LF:

```
peanut.collaboration.request.v2
instance_id
service_instance_id
key_id
holder_key
unix_timestamp
nonce
UPPERCASE_METHOD
exact_absolute_path_without_query
lowercase_sha256_of_exact_raw_body
```

Nonce/signature are 64 lowercase hexadecimal characters. The native unique nonce
is committed before business transactions; retries use a fresh nonce and the same
business/capture ID. Response domain is peanut.collaboration.response.v2, followed
by instance, service, key, holder, request nonce, method, path, request body SHA, response
timestamp, HTTP status and SHA of exact returned bytes. Both errors and successes
are rendered before signing. The original ServiceRequest object is process-local
proof and does not grant human authority.

The holder is the actual Node process UUIDv4, independently of stable deployment
service identity. Room/grant requests require the exact recorded holder. A new
process must explicitly recover after the previous Room and write grants expire.
Authorize returns signed RECOVERY_REQUIRED binding details only after current
native session and full actor authorization. Native recovery
retries the same token once and loads the actual baseline; protocol 1 requires
a separately reviewed stopped/drained migration. Recovery never unlocks unknown work.

## Authorization and batch persistence

The authorized transport endpoint returns only the configured WS/WSS URL and document context.
The browser supplies its existing native user token to Hocuspocus, which forwards
`{token,document_name}` to the authenticated backend authorize API. Both fields
are untrusted: configured instance/edition, native TenantAuthService session/member
validation, tenant equality and actual Document authorization establish the binding.
No browser collaboration credential is minted. The existing historical ticket
table holds server-only session references for connection/grant and revocation joins;
its digest is an unrelated random identifier, never a hash of the user token.
Authorize/check revalidate the real Identity session, current function/Document
owner permission and module state. Connection grants record immutable identity,
room, epoch, revision and issued/expires milliseconds. Renewal cannot turn a read
presence check into an unverified write grant. Content state_seq is a persistence
CAS counter; it is excluded from authorization_revision.

Native beforeSync checks the cached short write grant before merging. A frozen
batch carries the exact source connection/revision and actual acceptance time,
sampled after special File authorization. Store validates those recorded windows
and signed service custody; it does not impersonate or reauthorize contributors
whose already-lawful changes are held by the service. Document/epoch/status/CAS,
projection, binary and File reference checks remain transaction-bound.

The internal routes under /adminapi/internal/collaboration/v1 are authorize,
check, load, store, receipt, references, target/prepare, target, recovery and revoke. Node listens directly after local configuration validation; no global
runtime registration, prepare/activate, process-admission lease, or runtime table
is consulted. Room holder/epoch/lease and business barriers still fence writes.
There is no production per-update admission/journal
write or journal recovery replay. Existing journal/history tables remain intact.

Load returns only an actual locked durable baseline. Existing Yjs states lacking
a protocol 2 receipt receive a metadata-only immutable baseline receipt; no draft
rewrite occurs. Initial Yjs bytes must commit before a room joins. Store uses a
fixed capture ID and full tuple, commits content/File references/receipt together,
and returns only after the transaction commits. Receipt returns found:false or
the exact committed receipt. A new capture never replaces an unknown original.

Special newly introduced File keys require fresh original actor permission and
a real File-owned reservation before broadcast. A batch consumes only matching
source reservations whose complete key set appears in that captured projection.
Later, absent and partially captured grants remain pending until a real epoch
fence. Reservations do not grant preview URLs; only persisted Document references
can inherit normal non-sensitive preview. File retains sensitive/status checks.

## Explicit business targets and controls

An explicit save freezes click-time bytes/projection. Native saveMutex queues
target/prepare first, pinning its real current or before-broadcast File evidence,
then persists a covering capture and confirms target. The signed native service
attests delete-aware yjs.snapshotContainsUpdate/13.6.32 coverage; PHP verifies the
actual receipt, bounded hashes/projection, original actor and pinned File evidence.
Registering a target never rewrites the draft. The request ID, frozen business
fields and original member are immutable; a fresh connection of that same person
may continue them. A later autosave cannot change the explicit target.

Document version.create consumes that same request ID into an immutable version.
It does not drain/release the room or advance its epoch. Public target query
reports prepared/confirmed/consumed/canceled without exposing content or session
references. Explicit cancellation releases only that target's pin and refuses
any already-started formal operation. Pending targets and grants apply bounded
backpressure; TTLs do not erase unknown explicit operations.

Archive/rollback/recovery fence writes, persist native fixed captures where
online, and advance the actual Document/Room epoch. PHP control uses registered
HTTPS /internal/collaboration/control/v1/business-barrier with protocol 2
drain/release identity (holder_key,operation_id,input_sha256,room_key,lease_epoch). HMAC domains
are peanut.collaboration.control.request.v2 and response.v2. Their canonical order
adds holder after instance/service/key, as does the service transport. Success
data must name the same holder; CONTROL_HOLDER_CHANGED is a rejection and cannot
supply an offline drain. No SQL lock spans
network I/O. Offline drain is accepted only against PHP's real locked baseline.
Release closes old connections; rollback creates a fresh Y.Doc, never merges old
bytes into the live document.

Only restore of an actually archived Document with a closed, expired Room, expired
write grants and no pending business uses the local closed_persisted plan. The
same-PDO preparation fences its real baseline; finalization/release remain in PHP
and never claim a Node drain. It advances epoch without extending the old lease.
The next authorized join must claim its own holder through explicit recovery.

Protocol 2 crash recovery requires expired Room/all write grants and no unknown
business barrier. It preserves the actual committed baseline and exact request
receipt, advances epochs and closes old connections. Uncommitted broadcasts may
be lost on a crash. Explicit target pins remain independently queryable.

An expired closed Room with an archived Document may claim a new technical holder
only with the same no-pending-work guards. It remains closed/archived and grants
no join/edit permission. Ordinary authorization does not invoke this archived path.

Existing protocol-1 rooms remain protected historical data. This direct-listen
implementation does not automatically migrate or replay them; authorization fails
closed until a separately reviewed data cutover satisfies room and pending-work
fences. Historical SQL migrations and ledger identities are retained.

Process shutdown uses Node's local signal drain: stop new frames, join existing
loading/pending message work, and commit fixed captures through the same saveMutex.
A failed or unknown capture prevents successful shutdown. Business room barriers,
explicit target pins and actual database receipts remain authoritative; stopping
never invents a commit or unlocks unknown work. Application deployment manages
only its own composition and preserves its writer-hold and recovery checks; it
does not stop the independent Node service or require a Node shutdown receipt.
Backend downtime leaves Node listening and protects its unconfirmed captures.
The independent service owner remains responsible for Node's actual safe drain.
There is no global operator endpoint or PHP lifecycle CLI.

Source, syntax and metadata alone do not prove SQL migrations, real WSS, storage,
multi-user operation, installation/recovery qualification or production readiness.
