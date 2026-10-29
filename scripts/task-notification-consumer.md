# Controlled Task/Notification consumer

Python 3.9+ standard library; one selected existing tenant HTTP request, no worker,
provider, fault injection, identity creation, login/refresh, redirect, proxy or HTTP
retry. Source preparation is not runtime permission or business acceptance.

## Exact inputs and commands

`task-notification-consumer.schema.json` defines the `fixture`, `admission` and
private `credentials` JSON objects. Select the corresponding `$defs` definition.
No default tenant, rows, PHP, origin, credentials or authority is supplied.

```sh
python3 scripts/task-notification-consumer plan \
  --step diagnostics --fixture /approved/fixture.json \
  --admission /approved/admission.json --admission-sha256 <approved-sha256>

python3 scripts/task-notification-consumer execute \
  --step diagnostics --fixture /approved/fixture.json \
  --admission /approved/admission.json --admission-sha256 <approved-sha256> \
  --credentials /private/session.json
```

`plan` reads local evidence only and creates no journal. `execute` additionally
calls the existing, hash-pinned `scripts/project-resource-lease show --lease ID
--verify-locks`; it never calls claim/renew, another verifier or a worker. Missing
or rejected native grant stops before HTTP. Actual ACTIVE owner/thread/session,
candidate, registry SHA and complete mysql-access table/PK scope must match the
binding and fixture. Server middleware independently checks the exact session token
and current object/RBAC authorization. The normal registered resource environment
must already exist; this driver does not repair it.

The source SHA map contains exactly the ten paths in `SOURCE_FILES`, including
Composer lock, native lease verifier and direct producer/consumer code. Stable PHP
means an absolute existing PHP 8.3.x executable, binary SHA/version, and the normal
service owner's runtime receipt/SHA. The binary SHA also matches the native binding.
PHP is not executed/started or found through PATH. Origin/Host must be the registered
tenant entry; HTTP is loopback only and HTTPS keeps certificate validation.

## Existing evidence, never a new grant

Hashes prevent substitution; they do not authorize. A positive number, readiness
flag or `execution_admitted: true` never grants permission. Consume these existing
issuer records or refuse missing fields; do not synthesize them for this driver.

- `resource_permission_receipt` is the actual `SourceDevelopmentMaintenanceAdmission`
  binding: source, registry/database and authority owner/thread/session/lease,
  candidate, creation/expiry, native operation. Match the current native lease,
  never transplant a released capture owner's authority.
- Actual RW08 `row_scope_receipt` has `binding`, exact `principal`, `tenant_id`,
  `step`, `request_identity`, and the exact native `mysql_access` object covering
  reads/writes/locking reads, FK/audit/session effects. It is fixture configuration,
  not a permission issuer. Match that object to the verified native grant. A
  preparation result or null `authorized_fixture_inputs` is insufficient.
- `authentication_receipt` is a caller-owned 0600 original successful native
  Tenant login/select response. Its real `data.context` tenant/account/member and
  token SHA bind the principal. Credentials contain only that same access token.
  Neither response nor token is copied to the journal.
- `protection_receipt` is a real native source-plugin/application backup manifest,
  not an author summary: captured protocol/status, identical source/DB/authority
  binding, capture within its lifetime, every selected native table, payload/restore
  hashes and all three successful native process/output receipts are required.
  The historical task table must be in the preimage for a Task-reading or recovery
  step; unrelated inbox/mark-read does not inherit that dependency. The catalog-only
  snapshot is insufficient for business scope. No restore qualification is granted.
- `dispatch_control` is current `TASKS/dispatch/tasks.json`, pinned by SHA. The
  path is also verified in the existing Project Git repository. The selected package has `stage=live_consumer`,
  `state=running_native_task_started`, and the exact owner. Its original-owner
  `runtime_budget` has the same `case_id`, positive integer
  `remaining_http_requests`, `original_ref`, and `allocations`. `original_ref` has
  `path`, `sha256`, `case_pointer`, `remaining_pointer` selecting the original
  actual case and numeric allowance. Unknown/zero/string historical budgets do
  not become a new allowance.

In that existing control record exactly one allocation may match the globally
unique `operation_id`. It binds owner, native session, tenant, case, step, fixture
SHA, entire `request_identity`, fixed `result_path`, `http_requests=1`, and
`state=allocated`. Allocated counts must fit the original remaining budget. The
normal dispatch writer allocates/reconciles these fields under the original
contract; this driver cannot populate them. No budget service, token or parallel
ledger is introduced. Without the original exact record, do not execute. Never
allocate another operation/path to replay an UNKNOWN, refusal or spent case.

Historical IDs 4–11 are always required in `protected_job_ids`, never fixture jobs,
and task write scope cannot be `*` or include those PKs. Recovery with an
unallocatable insert PK remains blocked until normal native scope exists; do not
broaden the claim. The historical table preimage is checked separately from write
authority. No old task is consumed or replayed.

## Four consumers and mandatory assertions

| Step | Existing request | Mandatory evidence |
| --- | --- | --- |
| `diagnostics` | GET `/adminapi/api/v1/workflow/instances/{instance_key}/notifications` | Exact event sequence/outbox/job key/revision/status, or actual pending-materialization state |
| `inbox` | GET `/adminapi/api/v1/notifications` | Exact own message key/status/revision occurs once |
| `mark-read` | POST `/adminapi/api/v1/notifications/{message_key}/read` | Exact own message, read status and correct post-If-Match revision |
| `recover` | POST `/adminapi/api/v1/workflow/instances/{instance_key}/notifications/recover` | Exact event acceptance plus prior successful real diagnostics for the same actor/tenant/instance/event/outbox/job/revision |

Each step binds actor/tenant to the authenticated response, native fixture, current
owner and allocated request. The APIs do not echo actor/tenant; no response fields
are invented. Recovery echoes only sequence/acceptance, so its request tuple and
preceding real diagnostics establish exact row identity; acceptance never proves
delivery. Concrete outbox requires a known dead job. Null outbox/task is only the
existing `reauthorize` committed-fact materialization path.

Denial/409 requires explicit expected status and `expected_problem_code`, matched
to actual `data.error_code`; missing/wrong code fails. Success requires null expected
problem code. Extra JSON Pointers can add checks but cannot replace these hardcoded
identity assertions with a generic success pointer.

## Durable UNKNOWN and stopping

Before send, reserve the exact allocated absent result with O_EXCL/0600 and fsync
both file and parent directory. UNKNOWN persists owner, original case/operation/step,
HTTP method/path, tenant/account/member and token SHA (never the token), instance/event sequence, message key/
revision/status, outbox, task ID/job key/revision/status, strategy, If-Match and
recovery idempotency key. No private business body or token is stored. The original
owner can identify the exact request from the journal without reopening the fixture.
One allocated HTTP is conservatively spent even if the process dies before send;
actual transmission may be unknown. Never reuse/delete its result to retry.

The completed after-receipt is fsynced separately and atomically replaces UNKNOWN;
an interrupted final write cannot truncate the original request identity. Failed
final persistence reports UNKNOWN, never a misleading before-HTTP refusal.
Timeout or failed assertion remains UNKNOWN for original-owner reconciliation.
Only `ASSERTIONS_PASSED_THIS_HTTP_ONLY` plus mandatory verified response identity
can succeed; this is not Workflow88, delivery or business PASS. Current dispatch
SHA is rechecked after native verification/before journaling; expiry is checked
again before transmission.

There is no worker stage here. Current `tenant-task:work` selects a whole tenant
and recovers its expired jobs. Native exact task/handler selection, expired-recovery
scope, bounded processing and owner/lease fences for this batch are missing. Only
that stage is deferred. Separately admitted synchronous steps do not need a worker.
Never invent CLI flags or invoke handlers directly to bypass the stop.
