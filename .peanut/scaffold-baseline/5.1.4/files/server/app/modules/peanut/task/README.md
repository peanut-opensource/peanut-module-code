# Task installation data

Task owns schedules and the trusted job, attempt and event records. Installation
creates their empty tables; it does not create diagnostic schedules or example
jobs. Business modules may register their own required schedules through the
Task contracts, including Payment's refund reconciliation.

Version 1.0.28 retires the host's `crontab:demo` diagnostic command. Its forward
migration matches the command byte for byte and joins execution records by
tenant, schedule payload, native task type and native handler. It removes only
succeeded jobs with a completion timestamp, their events and attempts, then the
schedule. A schedule with any active or unrecognized associated job is retained
with its entire execution graph for explicit review. Other schedules and jobs
are unchanged. The native migration transaction and existing foreign keys remain
in force; rollback requires the pre-upgrade backup.

Source review and syntax checks do not establish that a migration has run.

## Fixed-source authorization bindings

Version 1.0.30 exports `TaskSourceAuthorizations` and `SourceTaskBinding`.
`assertSourceSubmission` requires an existing queued member job in the caller's
current submission transaction, matching the original tenant/member, consumer
module, resource, operation and handler.

`sourceActor` accepts only the actual `JobExecution` associated with this
runtime's current System execution root. It rechecks the signed authorization,
current original session and native lease. Constructing a job execution, copying
a binding or reusing a binding in another worker does not grant source access.
Document and File policy remain owned by those modules; Task does not import them.

Consumers recovering a failed, never-sent task use the existing public
`TaskJobService::detailForRecovery` in their current transaction. It locks the
terminal job and requires the real revision, completion timestamp, cleared lease
fields and no running attempt. A state-conflict exception alone is not proof that
a reservation can be released. Other permission, storage and program errors
must retain their original failure.
