# Optional notification delivery engine

This first-party implementation was moved from PHP Core into
`peanut.notification`. It supplies inbox/outbox, rendered template snapshot,
attachment reference, SMS provider, rate-limit, and task-handler behavior.

The current ModuleProvider binds the delivery repository, tenant-member recipient
resolver, optional File attachment resolver and official Task dispatcher/worker.
These source bindings do not prove that a particular instance has applied the
module migrations, enabled its producers or adopted the matching worker.

The provider explicitly chooses LocalDevSmsProvider only in the development
environment and DisabledSmsProvider otherwise; it does not authorize a real SMS
channel. Existing verification-code ledgers and failure limits remain separate.
Use the module's versioned migration and readiness checks before operation. Missing
schema, provider configuration or trusted execution context must not be replaced
by a second ledger or a successful-looking empty result.
