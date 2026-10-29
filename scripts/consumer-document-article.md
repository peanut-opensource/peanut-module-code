# Ordinary Article / Document consumer

Run `python3 scripts/consumer-document-article --contract` for the entry. This is a
bounded consumer of an **existing, explicitly owned, unpublished solo Article**.
It does not create a tenant, Article, file, service, lease or backup, or log in.
It reuses the existing reviewed solo save/history/restore/archive evidence;
only missing owner-slot and negative CAS consumers are executed here.

```sh
python3 scripts/consumer-document-article --input /absolute/private-input.json
# After independent review and separate live resource admission only:
python3 scripts/consumer-document-article --input /absolute/private-input.json --execute
```

The first command reads local files and Git only. `--execute` verifies the existing
native resource lease, waits up to 60 seconds for a TCP listener at the exact
independently admitted `endpoint`, and rechecks the grant before creating its
journal. It repeats those checks before each request. A missing input, rejected
initial grant, or initial listener timeout produces no journal or business request.
If the later check before the first request fails, the journal records
`stopped_reconcile_original_requests` with `requests: 0` and no `started` event.
After a completed request, a later failure preserves the recorded request count;
the consumer does not replay earlier calls. TCP readiness only confirms a listener at the admitted origin;
it does not prove PHP identity, HTTP health, or business success.
The journal flushes and fsyncs request-start before constructing the connection or
sending HTTP. Each POST records its exact `request_id`, canonical full-input
SHA-256, tenant/Article/Document IDs, expected sequence when applicable, and HTTP
correlation ID. It contains no tokens or content bodies. Existing output is refused. Any unknown result
stops; reconcile the original request IDs and actual rows instead of rerunning or
changing the prefix. There is no automatic retry, redirect, cleanup mutation or
restore. A successful run leaves its own current content at the new CAS seed A.

Supply a mode-0600 canonical input JSON with exactly these fields:

| Field | Required actual input |
| --- | --- |
| `runtime_root`, `source_commit` | Canonical clean Git product runtime checkout and exact full current runtime commit; matches native lease candidate/repository. This is not the historical source commit. The separately reviewed consumer may be invoked by its independently fixed absolute path and byte digest; its source commit need not replace the product runtime/template commit. The execution owner must bind that exact consumer version in its independent admission inputs. |
| `endpoint`, `host` | Exact HTTP origin and tenant Host; HTTP allowed only on loopback, otherwise HTTPS with default TLS verification. No guessed ports or remote localhost. |
| `owner`, `thread`, `lease` | Current actual owner/thread and existing native lease ID. Native `show --verify-locks` must match these and ACTIVE status. No historical identity reuse. |
| `registry` | `{"path":"/absolute/current-registry.json","sha256":"actual hash"}`; also bound by native lease. |
| `login_response` | `{"path":"/absolute/original-private-receipt.json","sha256":"actual receipt hash","received_at":actual_original_receive_unix_seconds}`. The canonical owner-controlled, single-link mode-0600 observation receipt must have integer `status=200` and string `body_utf8`; that body is current Identity JSON `{data,meta}`. Require `data.state=authenticated`, `token_type=Bearer`, nonempty safe `access_token`, positive integer `expires_in` no greater than current producer's 900 seconds, and actual `context` string IDs with audience `tenant`. `meta.request_id` must exist but is not required to echo a client trace. Original receive time plus lifetime must still be unexpired; future times are rejected. No old `code=20000` fallback. The reference/hash/time must be bound by independent protection admission. Tokens and body stay in memory, never copied or printed. |
| `tenant_id`, `account_id`, `member_id` | Positive actual integer identity IDs matching that login context. |
| `article_id`, `document_id`, `expected_state_seq` | Existing owned unpublished Article/body and admitted current sequence. No historical foreign rows or fabricated fixture. First editor read verifies exact owner/body, active solo state and unpublished metadata. |
| `request_prefix` | Unique previously unused admitted operation prefix, at most 96 characters from letters, digits, colon, underscore or hyphen. Unknown previous results must be reconciled first. |
| `history` | `revision_id` plus `expected` containing exactly `structured_content`, `source_state_seq`, `digest`, `references`, `confirmed`. These come from an existing independently accepted immutable revision of this same document; `confirmed` must be true. |
| `protection` | Pinned canonical JSON reference (`path`, `sha256`) to the separately admitted protection binding described below. It is evidence input, not new authority. |
| `scope_resource` | Exact native `mysql-access=...` resource string, including `complete=true`, full current tables/rows and transaction closure. It must be present byte-for-byte in native verified resources; the script never claims/renews or escalates to schema exclusive. |
| `budget` | `{"remaining": actual remaining request budget, "max_started":9}`. Original budgets and unknowns carry forward; this input cannot reset them. |
| `output` | Absolute absent journal file inside an existing canonical, owner-controlled directory, outside the clean runtime checkout. No previous effect journal may be replaced. |

The separate protection binding must equal the input's `source_commit`,
`endpoint`, `tenant_id`, `article_id`, `document_id`, `expected_state_seq`, and
`scope_resource`. It also contains an unexpired Unix `expires_at`, nonempty
`backup_files` list of pinned file references, and a pinned `independent_review`
reference to the **actual independent operation-protection admission result**.
That existing result must explicitly contain:

- `execution_admitted: true`, `purpose: "ordinary_document_article_consumer"`,
  and an unexpired Unix `expires_at`;
- `reviewer.independent_of_change: true` and its actual nonempty `reviewer.thread`,
  different from the consumer thread;
- `applicable` exactly equal to the supplied `runtime_root`, `source_commit`,
  `endpoint`, `host`, `owner`, `thread`, `lease`, `tenant_id`, `account_id`,
  `member_id`, `article_id`, `document_id`, `expected_state_seq`, `scope_resource`,
  `request_prefix`, `budget`, `registry`, `history`, and `login_response` fields;
- `backup_files` exactly equal to the pinned protection binding's backup list.

The consumer rechecks these pins, explicit admission, expiry and exact objects
before each request, including the private login receipt hash, authenticated
context and token lifetime. `received_at` is the actual original receive time
from the runtime owner's admitted operation evidence, never file mtime, copying
time or the current invocation time. An expired session requires a new separately
authorized runtime-owner operation; this consumer never logs in or refreshes.
Capture success, source-review PASS, `complete=true`, table
names or a generic unrelated approval cannot satisfy this Gate. If the normal
independent protection result does not yet explicitly admit these objects, stop
and have that actual independent writer decide under its existing normal
protection contract; do not manufacture an approval or rewrite a failed result.
This is a narrow consumer of that result, not a backup/review/admission producer.
Actual table existence, FK/trigger/row closure and recoverability remain the
independent protection Gate's responsibility; local checks do not prove them.

The nine requests are editor read, historical detail read, two different ordinary
Article document-ensure requests, one normal CAS seed save, a stale-sequence save
(409 `DOCUMENT_STATE_SEQ_CONFLICT`), changed-content reuse of the original request
ID (409 `DOCUMENT_IDEMPOTENCY_CONFLICT`), editor read proving A/sequence/digest
unchanged by the rejected saves, and historical detail proving immutable fields
unchanged. The two ensure results must both be `created=false` with the same
document ID. Historical summary sequence may advance; immutable payload, source
sequence, digest, references and confirmation must remain exact.

This proves single-owner/body uniqueness only. Current Article policy permits
only `body`; it cannot prove multiple slots. Multi-slot acceptance requires a
genuinely approved existing business owner/consumer with two declared slots and
its ordinary public command entry, policy and real owned rows. Do not invent a
second Article slot, register a fake owner or bypass the public API. Cross-tenant
negative requests, collaboration/service barriers, permissions, File bytes,
Workflow material consumers and full APP readiness are not executed or passed.
