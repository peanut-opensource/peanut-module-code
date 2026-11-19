# Official task execution ledger

`peanut.task` owns the `pa_task_job`, `pa_task_job_attempt`, and
`pa_task_job_event` tables and the local persistent worker. Core owns only the
signed async envelope and authorization revalidation protocol.

Handlers receive `JobExecution`. They must call `checkpoint()` before every
bounded batch or side effect and stop when it throws. After an external call,
they must call `assertLeaseOwned()` before recording a local result. External
effects use `jobKey` (or a stable value derived from it) as their idempotency
key. The worker also fences completion by tenant, attempt, token, and unexpired
lease; an old holder cannot report success or failure after recovery.

Only registered submission providers can build handler payloads. Public HTTP
inputs never select handler classes or private payloads.

The native Runtime establishes a fresh System execution root for each signed,
reauthorized claim. The first handler checkpoint, all handler transactions and
the final worker fence share that lifetime; failure and recovery release it in
finally, and the next claim creates another root. LocalWorker requires the
explicit host lifecycle callback; there is no default callback. Module-aware
handlers recheck Task and their owner module inside the existing job root.
The opaque root object grants no permissions and cannot upgrade System to Admin.

The `tenant-task:work` and `crontab` commands delegate identity establishment to
the Runtime and scheduler instead of enclosing a worker loop in Instance context.
Runtime `runTenant` and scheduler `runDue`/`start` reject any ambient execution
context before claiming or enqueueing. Ordinary nested audience/tenant guards
are unchanged. A Crontab job still dispatches its registered native command with
the original System job metadata and trusted scheduled tenant. Recursively
starting another scheduler or worker from that command is explicitly unsupported
and fails before queue mutation.
