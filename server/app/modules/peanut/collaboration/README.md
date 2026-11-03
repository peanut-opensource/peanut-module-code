# Document Collaboration

This optional module supplies native Hocuspocus 4.6 protocol 2 authentication,
connection authorization windows, special File reservations and batch custody.
Document owns durable content, immutable explicit save targets, versions and
committed receipts. Ordinary updates merge/broadcast before batch persistence;
a broadcast or native SyncStatus is not a database commit.

## Embed in a business module

This is a backend capability module with no menu or standalone editor. Reuse the
existing public entry points rather than creating another editing page:

| Responsibility | Public entry point | Current consumer |
| --- | --- | --- |
| Authorize a business-owned transport | `CollaborationTransports::ownerTransport` | Article `ArticleBodyService::transport` |
| Embed the authorized business document | `@peanut-admin/peanut-document/editor` → `DocumentEditor` | Article `ArticleBodyEditor.vue` |
| Own a Yjs connection in a custom content surface | `@peanut-admin/peanut-rich-text/editor` → `createCollaborationProvider` | Document `DocumentSurface.vue` |
| Pass the owner-held CRDT into a content editor | `@peanut-admin/peanut-content-tools/surface` → `ContentCollaborationBinding` | Document surface → RichText editor |

The business service resolves its own object, checks its edit permission, obtains
the document through Document's owner contract, and calls `ownerTransport` with
the exact `DocumentOwnerReference`. Article's existing implementation is in
[`ArticleBodyService`](../article/src/Service/ArticleBodyService.php). Inject the
exported `CollaborationTransports` contract; do not instantiate this module's
internal services or read its Room/Ticket tables from another module.

For the usual business editor, supply `DocumentEditor` with the owner,
`metadataResolver` and `resolveTransport` from that business module. The resolver
maps `document_id`, `owner`, `authorization_revision`, `mode`, `wss_url` and
`document_name` into `AuthorizedDocumentTransport`, together with the selected
content `capabilityRevision`. Bind `contextKey` to the current business object and
identity partition. Abort stale requests and destroy the old surface when either
changes. Do not cache a resolved transport as a grant or synthesize a room name.

The generic `transport(context, documentId, mode)` also serves authorized direct
Document read/write resolution. Read resolution does not enable collaboration.
Socket authorization independently derives current read/write permission from
the native session and Document owner; selecting a UI mode does not grant it.
The current `DocumentEditor` owner transport callback is edit-purpose and expects
`mode: 'write'`; a read-only content preview is not proof of live read-presence.

Custom surfaces may use the existing provider and binding directly. The surface
owner creates and destroys the provider; the content adapter consumes the same
Y.Doc and must not open a second connection. `ready` requires both initial sync
and a committed proof. `writable` follows the authenticated scope; `saved` follows
the PHP-backed committed snapshot. Stop input on recovery/disconnect, retain an
explicitly labeled local draft if available, and resolve fresh authorization
before reopening. Never replay the disconnected document automatically.

Explicit business save uses the embedded editor's `prepareSaveTarget(requestId)`
and the business module's existing Document target/version operation. Keep the
same request ID while its outcome is unknown. The owner decides business success
from that operation's PHP receipt, separately from provider autosave and socket
sync. See [Document's module contract](../document/README.md) for its operations.

Only the rich-text provider currently supplies this Yjs collaboration path;
other content formats retain their declared adapter capabilities. The authenticated
online-member roster is available; remote carets and selection sharing are not
currently supported. Article is the
existing end-to-end business consumer. Document surface and RichText are shared
assembly layers, not a second independent business consumer. A second business
embedding and actual two-member save/reopen confirmation must be recorded when
performed; the presence of these entry points does not prove either result.

## Custom Tiptap provider composition

Prefer `DocumentEditor` for a business document. A custom owner surface can use
the same public provider below. Call once for a freshly authorized owner transport
and current native session token; pass the returned binding to `RichTextEditor`'s
`collaboration-binding` prop. This is the existing DocumentSurface assembly, not a
second provider to attach alongside DocumentEditor.

```ts
import { shallowRef } from 'vue';
import {
  createCollaborationProvider,
  type CollaborationBinding,
  type ProviderOptions,
} from '@peanut-admin/peanut-rich-text/editor';

function openAuthorizedSurface(
  options: Pick<ProviderOptions, 'url' | 'documentId' | 'documentName' | 'token'>,
  showRecovery: (code: string) => void,
) {
  const binding = shallowRef<CollaborationBinding>();
  const update = (patch: Partial<CollaborationBinding>) => {
    if (binding.value) binding.value = { ...binding.value, ...patch };
  };
  const session = createCollaborationProvider({
    ...options,
    onReadyChanged: ready => update({ ready }),
    onWritableChanged: writable => update({ writable }),
    onSavedChanged: saved => update({ saved }),
    onRecoveryRequired: code => {
      update({ ready: false, writable: false, saved: false, frozen: true });
      showRecovery(code);
    },
  });
  binding.value = {
    protocol: 'yjs/1',
    document: session.document,
    generation: session.tracker.generation,
    ready: session.ready,
    writable: session.writable,
    saved: false,
  };
  return {
    binding,
    session,
    dispose: () => {
      binding.value = undefined;
      session.destroy();
    },
  };
}
```

The owner calls `dispose()` on unmount or account/member/tenant/object changes,
and reauthorizes before opening another session. Keep recovery feedback visible;
do not reconnect or discard pending business targets automatically. The provider
creates the only Y.Doc; no JSON initialization or second JSON save is allowed in
collaboration mode. Its `requestSaveTarget(requestId)` returns a committed proof,
not an Article business-save receipt. Existing Document/Article target operations
still own version creation and business binding. `onPresenceChanged` can display
the authenticated member roster; native `awareness: null` means remote cursor
and selection sharing remain unavailable. This example adds no awareness channel.

## Service authentication

The managed application configuration supplies the fixed instance/service/key,
origins and secret. HTTPS uses the existing Guzzle transport's system trust by
default. A private CA is selected by the paired managed CA file and SHA-256;
invalid or incomplete nonempty references fail instead of selecting system trust.
Explicit HTTP/WS is accepted only when the application's APP_ENV is development;
HTTPS/WSS keeps normal certificate verification.
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
Its `/collaboration` path matches the current ingress. Default ports, explicit
ports 1–65535, DNS case and IPv6 URL hosts do not require a different permission.
Credentials, query/fragment, control characters and other schemes remain invalid;
the application configuration supplies the URL, never a browser request.
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

## Installation data and retired schema

Installation creates the seven declared collaboration protocol tables and no
sample rooms, tickets, grants, admissions or document content. Version 1.0.17
retires `pa_collaboration_runtime`, the unused global process-coordination ledger.
The forward migration removes that table on both a complete fresh installation
and an existing module upgrade; the original initial migration remains unchanged.
The independent Hocuspocus process uses the signed PHP API and its own signal
drain, and does not read this retired SQL ledger. Active Room, connection, grant,
barrier and Document save/recovery records remain owned by their existing paths.

Source, syntax and metadata alone do not prove SQL migrations, real WSS, storage,
multi-user operation, installation/recovery qualification or production readiness.
