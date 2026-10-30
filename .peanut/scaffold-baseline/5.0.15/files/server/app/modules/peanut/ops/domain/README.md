# Platform operations domain

The platform application owns status, maintenance windows, structured safe-log
queries, backup/restore provider contracts, and operation-task contracts.
These types do not execute arbitrary commands or paths and do not own a queue.
Host adapters remain under `app/platform/infrastructure/ops` and use the
official task runtime where persistent execution is required.

`scripts/ops-restore-worker` counts the restored database's native migration
ledger using the target APP's configured connection prefix and
`database.migration_table` (default `migrations`). The evidence field remains
`schema_migration_count`, and the backup center displays it as `migration_count`.
Existing evidence is preserved; the worker does not require or create the old
`pa_schema_migration` table.
