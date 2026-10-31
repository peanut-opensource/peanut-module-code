# Workflow development baseline

## Business adapters and presentation

`module.json` exports these PHP contracts. Resolve them through the selected
Module provider and host composition, without reading Workflow private Models.

| Contract | Existing implementation / consumer | Responsibility |
| --- | --- | --- |
| `Adapter\WorkflowScenarioProvider` | `Integration\WorkflowScenarioRegistry` → definition validation and commands | Trusted fields/results/actions, department/subject owner, prepared immutable snapshot, completion validation and local action |
| `Adapter\WorkflowAssignmentResolver` | `IdentityWorkflowAssignmentResolver` → validation and commands | Resolve each required rule separately, preserve source/binding revisions; `eligible` rechecks current organization and owner |
| `Adapter\WorkflowAuthorizationResolver` | `ScenarioWorkflowAuthorizationResolver` → commands/queries | Delegate to the business `WorkflowScenarioAccess`; apply visible SQL scopes before pagination/count; return current object authorization |
| `Adapter\WorkflowSubjectRevisionResolver` | Optional `ArtifactWorkflowSubjectRevisionResolver` | Read the already-authorized fixed artifact snapshot and return `{ revision_key, sha256 }`; no body save |

A selected business provider implements `WorkflowScenarioContributor` and calls
`WorkflowScenarioRegistry::register(moduleKey, workflowKey, resourceKey, provider,
access, actionKeys)` inside `registerWorkflowScenarios`. The composition root
collects contributors and constructs the registry. Keys/classes are source-defined,
never request input. The optional artifact adapter is not an automatically
registered business scenario. The scenario owns `prepareSubjectSnapshot` before
the intent transaction, then revalidates/locks in `snapshot` on the same connection
when `forUpdate=true`. `validateCompletion` has no irreversible effects;
`executeAction` performs local same-database writes with its durable effect key in
the outer transaction. Do not place external side effects in that local contract.

Current production source has no independent business scenario registration;
the optional receipt fixture is not a deployed business consumer. For UI reuse,
the existing [Web form provider](../../../../../web/src/modules/peanut-workflow/README.md)
handles complete/resubmit business saves through `provideWorkflowTaskForm`.
It is an injection contract, not an unverified generic form-slot API.

## Errors and deliberate retry

| Result | Consumer response |
| --- | --- |
| `WORKFLOW_DEFINITION_INVALID`, `CAPABILITY_UNSUPPORTED`, `ASSIGNEE_UNRESOLVED` (422) | Fix scenario/graph/input or required assignments; retain `validationIssue()` path/message |
| `WORKFLOW_ASSIGNMENT_DENIED` (403), `WORKFLOW_SUBJECT_NOT_FOUND` (404) | Recheck current object/actor visibility; do not expose an old receipt after access withdrawal |
| `WORKFLOW_PRECONDITION_REQUIRED` (428) | Supply the revision precondition from the authorized current read |
| Definition/instance/subject-revision conflicts, `TASK_ALREADY_COMPLETED`, `ACTION_BLOCKED` (409) | Read current state and available actions; retain unsaved business input for an explicit new choice |
| `WORKFLOW_PROVIDER_UNAVAILABLE` (503), `INTERNAL_ERROR` (500) | Surface failure and restore the actual provider/environment; no empty-success fallback |

For example, on `WORKFLOW_INSTANCE_CONFLICT`, reload the same authorized instance,
show its new revision and stop the rejected command. Do not silently apply its
decision to the new revision. A timeout is unknown: inspect instance/event state
before a deliberate retry. The transaction binds its one-day idempotency receipt
to actor, operation, target and canonical request hash; replay reauthorizes reads.
Keep the exact key/input for the same unknown attempt; a changed decision is a
new action. `IDEMPOTENCY_REQUEST_PROCESSING` is not completion. Notification
recovery uses diagnostics and does not repeat the Workflow decision.

The development module requires the fixed Core source capability that validates selected `optional_dependencies`. The published Core v5.0.1 source does not yet provide that capability; a local development source must have its real Git identity and independent development lock, without changing the public package identity. Unqualified generated applications must not be described as compatible with the published lock.

Service-task definitions require an available `peanut.task` module and a registered trusted publisher. Notification definitions require the actual Notification producer and Task capabilities; absent capabilities reject publication. No replacement notification implementation is supplied here. Artifact revisions are accessed through the selected scenario's exported query contract.

The optional `fixture.workflow-business` package contributes only the `receipt` scenario and requires Task for its receipt worker. It is excluded from generated product applications; selecting it in a development source does not enable a tenant or create data.

This baseline includes runtime and design API routing, managed definitions, native schema declarations and upgrade preflight. Existing databases are not migrated by source integration. Source checks and a client build do not qualify business execution, installation, upgrade or production use.

The source-development Plugin lifecycle binds the four immutable Workflow SQL stages to native scoped backup, same-connection preservation assertions and accepted stage receipts. Initial installation requires all seven Workflow tables to be absent; existing unrecorded tables require explicit review. Additive stages preserve original rows and columns, while the V2 stage requires empty original Workflow tables and retains the SQL empty-data guard. No graph, vote or responsibility history is converted. The recorded source-development plan, current native authority, maintenance window and recovery coverage remain required; these declarations are not evidence that a database has been migrated or restored.

Before replacing Notification execution source in an existing APP, the native upgrader requires a fresh `module:check peanut.notification --runtime-data` report from that APP. Missing query support, unavailable state or a pending legacy outbox rejects the switch before managed files change. The maintenance owner must first establish the existing exclusive runtime-safe window; this read-only guard does not stop services or drain jobs. The later migration guard is an additional check. No historical actor/session rows are synthesized and no compatibility publisher is supplied.
