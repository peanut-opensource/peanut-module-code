# Workflow transition HTTP consumer

`workflow-transition-consumer` runs **one** fixed, granted case against existing
Workflow and `fixture.workflow-business` HTTP APIs. It does not create actors,
objects, graphs, versions, databases or apps. It does not log in, acquire/renew
leases, run a worker, execute SQL, retry a request or follow redirects.

Validation is the default and performs no network requests or output writes:

```sh
python3 scripts/workflow-transition-consumer \
  --fixture /absolute/private-fixture.json --fixture-sha256 FIXTURE_SHA256 \
  --grant /absolute/private-grant.json --grant-sha256 GRANT_SHA256
```

Both JSON inputs must be canonical absolute regular files, owned by the caller,
mode `0600`, with one link and at most 256 KiB. Digests are lowercase SHA-256.
The caller supplies actual reviewed inputs, never the placeholder strings here.
All references have exactly `{ "path": absolutePath, "sha256": digest }`.
Referenced files are hashed, not executed. No credentials belong in these files.

Only an explicitly authorized runtime owner may add:

```sh
python3 scripts/workflow-transition-consumer \
  --fixture /absolute/private-fixture.json --fixture-sha256 FIXTURE_SHA256 \
  --grant /absolute/private-grant.json --grant-sha256 GRANT_SHA256 \
  --execute --output /absolute/leased-output/unique-receipt.jsonl
```

The grant and its exact native lease must contain **exactly one** canonical
`output-dir`. The selected output parent must equal it; multiple output directories
are rejected before execution. The output must not exist. The durable
`.workflow-transition-RUN_ID.started` marker is fixed to that native directory,
independent of the selected filename. It binds run, fixture/grant/driver/registry,
owner/thread, lease, source and the existing runtime task authorization receipt.
The marker file and its directory entry are fsynced before any request. This reuses
the existing native grant and authorization facts, without another task table or
authority service. Keep the marker after failure, timeout, unknown effects or
partial completion. Reconcile actual state and original budget before any later
owner-authorized continuation; changing filenames or run IDs is not permission
to repeat an operation.

## Fixture schema, version 1

The top-level keys below are required and exhaustive. Nulls are allowed only where
specified. Identifiers are JSON strings matching `[A-Za-z0-9_-]{1,160}`; revisions
are positive JSON integers (booleans are rejected).

| Key | Required value |
| --- | --- |
| `schema_version` | Integer `1` |
| `source` | Exactly `root`, `commit`, `workflow_tree`, `fixture_tree`; canonical existing Git root, full 40-hex commit, Git tree IDs for `server/app/modules/peanut/workflow` and `server/app/modules/fixture/workflow_business`. Runtime root must be clean and at this commit. |
| `registry` | Reference to the actual versioned resource registry |
| `endpoint` | Exactly `url`, `host_pointer`, `port_pointer`; origin-only HTTP(S) URL without credentials/query/fragment. JSON Pointers select the exact host and port in that registry, and must equal the URL. No proxy environment or ambient URL is used. |
| `scope` | Exactly `tenant_id`, `instance_key`, `subject_key`, `subject_revision_key`, `scene`, `native_baseline`, `mysql_access_resource`. Scene is `application`, `purchase` or `receipt`; baseline is a reference; access is the exact native `mysql-access=JSON` declaration with `operation:transaction`, `complete:true`, and nonempty tables. It must cover the real transactions, locks, row/FK/unique effects, including any insert range. |
| `actors` | Map of actor aliases to exactly `{member_id: string, token_env: string}`. Environment names match `[A-Z][A-Z0-9_]{1,100}`. Only `--execute` loads current `pa_tat_` token values from those variables. No login/reset/token copy is performed. |
| `case` | Exactly the fields in the next table |

| Case field | Type / meaning |
| --- | --- |
| `kind` | One of the seven kinds below |
| `actor`, `other_actor` | Existing aliases in `actors`; real roles and current object permissions come from the authorized fixture |
| `item`, `other_item` | Existing item identifiers. `other_item` is the old item for the late vote; return/resubmit discovers the new correction item through current authorized detail, and does not guess its generated ID. Unused fields still require an actual owned identifier. |
| `base` | Exactly `node_execution_key`, `expected_revision`, `expected_node_revision`, `expected_task_revision` |
| `action` | Exactly `suffix`, `body`. Suffix is `decisions`, `completions`, `return` or `resubmit`, with the exact native payload below. |
| `expected_conflict_state` | `null` or `visible`, used by `stale_conflict` |
| `changed_body` | Different valid action payload for `idempotent`; otherwise null |
| `resubmit_revision` | Actual immutable correction revision for `return_resubmit`; otherwise null |
| `prelude` | `claim` or `comment` for `withdraw_window`; otherwise null |

Native action bodies are exhaustive:

- `decisions`: `{decision: "approve"|"reject", comment: string|null, attachment_file_keys: []}`.
- `completions`: `{comment: string|null, attachment_file_keys: [], input: object}`.
- `return`: `{comment: nonemptyString, attachment_file_keys: []}`.
- `resubmit`: `{comment: string|null, attachment_file_keys: [], subject_revision_key: string}`.

Reject requires a nonempty reason. Comments are at most 2000 characters. Attachments
are deliberately empty to avoid adding a File write/read scope to this consumer.

The native baseline is an existing real scoped observation normalized by its
runtime owner. Its required fields are `source_commit`, `tenant_id`, `instance_key`,
`subject_key`, `subject_revision_key`, `actors` (alias to actual member ID), `kind`,
`no_service_nodes:true`, and `preconditions:object`. The baseline must refer to a
manual-only immutable graph, so no command in this consumer enqueues an async
effect. This consumer checks the summary's bindings and digest; the independent
runtime review must verify its actual native provenance and graph/data identity.
Hashes alone are not an authority, backup qualification or proof of current data.

## Grant schema, version 1

The grant has exactly these fields:

| Field | Value |
| --- | --- |
| `schema_version` | Integer `1` |
| `run_id`, `lease` | Native-safe identifiers; run ID is at most 80 characters, unique and never reused |
| `owner`, `thread` | Same actual native owner/thread; executing `CODEX_THREAD_ID` must match |
| `gate` | Exact gate in the existing native lease |
| `expires_at` | Future Unix seconds, integer; does not renew native permissions |
| `fixture_sha256`, `driver_sha256`, `registry_sha256` | Actual bytes of the fixture, this executable, and registry |
| `source_commit` | Same full fixed commit as the fixture |
| `resources` | Exact set of `type=value` native resources, including the complete `mysql-access`, endpoint `port`, and exactly one canonical existing `output-dir`; its directory holds both run marker and receipt |
| `protection`, `review`, `runtime_authorization` | References to the actual current normal protection, uninvolved review and runtime authorization receipts; supplying a file does not create those decisions |
| `budget` | Exactly `history_ref` (original budget reference), `remaining_http` and `remaining_writes` (nonnegative integers, enough for the selected case) |

Before sending each request, the executable invokes the existing
`scripts/project-resource-lease show --lease ID --verify-locks` in the fixed runtime
root with the explicit registry. It compares owner, thread, candidate, gate,
worktree and the **entire** resource set. Rejection stops this path. It never
claims a lease, edits its proof, borrows an owner's Session, or ignores a refusal.
The operator still obtains all normal native execution permissions and the
current reviewed data/protection grant before starting; this tool is not a new
authorization service or a replacement for those gates.
The lease must have at least 60 seconds remaining before each request reservation;
the consumer never extends it. Race participants wait at most 30 seconds before
sending, and recheck the grant expiry immediately before the send.

## Cases and maximum reserved budget

Each case is selected separately; this is not a full acceptance matrix runner.
The executor must select only genuinely missing assertions, retain the historical
failures and budgets, and skip unchanged accepted cases.

| Kind | Missing consumer / exact operation | Max HTTP / writes |
| --- | --- | --- |
| `any_final` | WF-G13: baseline has exactly two rejected votes, three frozen seats, pending third `item`, zero successors. `preconditions` requires `completed_rejections:2`, `frozen_seats:3`, `pending_items:[item]`, `successor_count:0`. Third actor approves to a manual approved end, then late old-seat reject must conflict; fresh authorized detail remains at identical approved terminal revision. | 4 / 2 |
| `withdrawal_race` | WF-C11: initiator withdrawal vs `other_actor` first substantive decision/completion. Two separately admitted HTTP connections rendezvous immediately before sending the same expected instance revision. One 200 and one 409 required, then current state is read. Both original SQL/lock/CAS observations remain a separate native obligation. | 3 / 2 |
| `withdraw_window` | WF-C11: `other_actor` claim or ordinary fixture comment; initiator detail still offers withdraw; explicit withdraw returns cancelled. No arbitrary collaboration kind or content injection. | 4 / 2 |
| `stale_conflict` | WF-C07: fixed old-cycle/revision command requires 409 `WORKFLOW_INSTANCE_CONFLICT`; current_state is null or scoped visible as explicitly authorized. No query-fault injection. | 1 / 1 |
| `return_resubmit` | WF-C10/C07 direct contract consumer when actually needed: authorized return, current correction detail from `other_actor`, exactly one own resubmittable item at `preconditions.correction_node_key`, new execution key, then explicit resubmit with the real new immutable revision. Final approvals and native old-opinion isolation are separate. | 4 / 2 |
| `idempotent` | WF-C06 direct protocol consumer when actually needed: same native action with identical key/body twice must return equal receipt; changed valid body under that key must conflict. No retry loop. | 3 / 3 |
| `gateway_wrong_type` | WF-D02: an existing trusted provider must already produce the wrong runtime type. `preconditions.wrong_type_proven:true` and exact `expected_blocker:{code,message,node_execution_key}` are required. One granted action must return that precise blocked projection; current state stays blocked. An invalid HTTP form rejected before the gateway is not this case. | 3 / 1 |

The consumer never directly measures SQL locks, physical successor counts, atomic
opinion/event commits, or business effects. HTTP receipts deliberately record
`native_SQL_CAS:NOT_OBSERVED`, `physical_successor_count:NOT_OBSERVED` and
`business_accepted:false`. Runtime owner must join these receipts to the existing
real native scoped observer before claiming those requirements. The source does
not count a mocked/native-looking baseline as new runtime evidence.

WF-C04 browser display and WF-C12 termination/worker races remain outside this
HTTP/manual scope; their original browser authority, async fixture and budgets
are preserved. The old queued jobs are never consumed. D02 cannot execute without
its actual registered provider input; the executable adds no test provider.

## Local checks

```sh
python3 -c 'import ast,pathlib; ast.parse(pathlib.Path("scripts/workflow-transition-consumer").read_text())'
python3 scripts/workflow-transition-consumer --self-check
python3 scripts/workflow-transition-consumer --journal-self-check
```

The isolated self-check denies sockets and checks only the driver's input
rejections. The separate journal self-check covers only the request journal and
native-directory replay guard with temporary local files and a synthetic driver
transport; it denies sockets and never validates/acquires a real lease. Neither
is a product, HTTP, database or concurrency assertion. Do not rerun the unrelated
input checks when qualifying only a journal change.

Each request gets a unique protocol-valid `X-Request-Id` derived from its fixed
run/input/grant/actor/target and monotonic run sequence. Before any send, the
journal fsyncs that ID together with sequence, native lease and task authorization
receipt hash, actor/member, tenant/subject/version/instance, actual origin/method/
path, exact idempotency key, exact encoded-body SHA-256 and selected target/revision
fields. This includes a correction work item and revisions discovered dynamically
from authorized detail. The same request ID and idempotency key go on the wire;
the receipt does not retain tokens, comments, input content or material bodies.

Responses verify every returned `X-Request-Id` and `meta.request_id` against the
reserved ID, with at least one native carrier required. Matching responses retain
status, response hash/timing, returned-ID hashes, exact error code and bounded
native assertion projections (IDs/revisions/status/result/current_state/actions,
own task selectors, blocker code and message hash). Unrestricted response bodies
are never stored. Missing or mismatching correlation records
`STOP_CORRELATION_UNKNOWN` and prevents assertion success or replay. For example,
the current fixture collaboration success response exposes an empty meta object;
without an actual native response header it cannot be accepted as a correlated
comment-window result. A later runtime owner must establish that native return
carrier before selecting that operation; this consumer does not modify the API.
Send or response failure consumes the durable reservation and produces
STOP/UNKNOWN. There is no automatic network replay or fixture cleanup.
