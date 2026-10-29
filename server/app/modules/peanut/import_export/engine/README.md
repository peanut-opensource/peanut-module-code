# Official import/export engine

`peanut.import-export` owns the operation and row-error ledgers. Public callers
select registered provider keys and fixed CSV operations; they cannot submit a
class, SQL, table, filesystem path, or executable command.

Operation creation and task publication join the same transaction. Worker
attempt, progress, cancellation, and completion writes are fenced by operation,
task key, and attempt. Import rows use stable business idempotency keys, while
the task lease is renewed before each row or export batch and rechecked after
side effects.
