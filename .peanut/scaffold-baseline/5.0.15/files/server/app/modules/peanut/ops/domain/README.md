# Platform operations domain

The platform application owns status, maintenance windows, structured safe-log
queries, backup/restore provider contracts, and operation-task contracts.
These types do not execute arbitrary commands or paths and do not own a queue.
Host adapters remain under `app/platform/infrastructure/ops` and use the
official task runtime where persistent execution is required.
