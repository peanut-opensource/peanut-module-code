# Optional notification delivery engine

## Workflow producer composition

`Contract\NotificationProducerRegistry` belongs to the host composition root.
With Workflow selected, the root collects selected `WorkflowScenarioContributor`
providers; their optional `notificationProducers(App)` method returns
`NotificationProducer` instances. Registration rejects duplicate keys. Each
producer fixes resource/operation/task/handler, authorizes publication and reads,
and resolves its own committed fact into template, frozen recipients, variables
and file keys. Do not bind a competing registry or accept caller-selected content.

The real consumer `Workflow\Application\WorkflowNotificationDelivery` runs
`dispatchCommitted` after the actual Workflow commit. It reads the event fact,
builds `NotificationPublicationIdentity(producer_key, event_key, business_key)`
and calls `NotificationService::publishProduced`. That service rejects an active
outer transaction, resolves the producer, reauthorizes, then stores rendered
template snapshots, inbox messages and outbox records in the native transaction.
Workflow records the outbox keys and dispatches through the existing Task worker.
Queued acceptance is not delivered state.

This producer path accepts exactly `['inbox']`. The generic template engine
supports inbox/SMS and rejects missing, extra or malformed template variables;
that does not turn the producer path into an SMS/Push API. The recipient resolver
is `TenantMemberRecipientResolver`; attachment bytes remain File-owned. Use the
[existing inbox runtime](../../../../../../web/src/modules/peanut-notification/README.md)
for presentation, filtering, revisions and identity disposal.

Channel administration uses `NoticeChannelService` and its
`ExternalTenantResolutionService` to resolve the tenant's configured binding.
This is separate from inbox-only producer delivery. The delivery engine binds
`UnavailableSmsRecipientResolver` and the LocalDev/Disabled provider described
below; no real SMS/Push adapter is demonstrated or added here.

Current production module providers do not register a concrete
`NotificationProducer`. The Workflow delivery consumer exists, but an actual
business event still requires its own selected authorized producer. An empty
registry is not evidence of a delivered Workflow notification.

## Errors and recovery

Failed materialization retains `NOTIFICATION_DELIVERY_PENDING`; diagnostics
reports the original event/outbox/Task state. Use only returned
`available_actions`: `retry_original` or `reauthorize`, with the original outbox
and expected job revision. Recovery rechecks source, tenant, Workflow diagnostics
and Task management access, records audit and uses its own idempotency key. Keep
exact key/input for an unknown attempt; do not republish a new Workflow decision.
Missing producers/templates/recipients and invalid contexts remain errors.
External channel delivery is NOT_RUN without real configuration and authorization.

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
