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
