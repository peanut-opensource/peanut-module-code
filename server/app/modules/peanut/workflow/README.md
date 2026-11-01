# Workflow development baseline

## Business adapters and presentation

`module.json` exports these PHP contracts. Resolve them through the selected
Module provider and host composition, without reading Workflow private Models.

| Contract | Existing implementation / consumer | Responsibility |
| --- | --- | --- |
| `Adapter\WorkflowScenarioProvider` | `Integration\WorkflowScenarioRegistry` → definition validation and commands | Trusted fields/results/actions, department/subject owner, prepared immutable snapshot, completion validation and local action |
| `Adapter\WorkflowAssignmentResolver` | `IdentityWorkflowAssignmentResolver` → validation and commands | Resolve each required rule separately, preserve source/binding revisions; `eligible` rechecks current organization and owner |
| `Adapter\WorkflowAssignmentValidator` | Optional registered business provider via `WorkflowScenarioRegistry` → commands | Accept or reject the actual resolved incoming handlers before responsibility is written; never filter a frozen pool |
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

## Optional business assignment validation (4.0.0-rc.42)

A registered scenario provider may also implement the exported
`Adapter\WorkflowAssignmentValidator` interface. Its method is:

```php
public function validateAssignments(
    AuthorizedOperationContext $context,
    string $moduleKey,
    string $workflowKey,
    array $assignment,
): void;
```

This is an optional interface, not a new required method on
`WorkflowScenarioProvider`. `WorkflowScenarioRegistry` finds the provider by the
exact registered module/workflow pair and, for an opted-in provider, verifies the
authorized resource and assignment subject type against that registration.
An existing provider that does not implement the interface receives no new
callback and keeps the native allocation behavior. Direct host composition also
checks the optional interface; it does not require all providers to change.

The callback receives the same authorized business context used for the native
command. Its actor and tenant come from that context, not from the candidate list.
All keys in the following projection are required and supplied by Workflow;
nullable keys are present with `null`, never omitted. Strings identify native
records/configuration, not editable labels or ORM records.

| Field | Type and meaning |
| --- | --- |
| `phase` | `enter`, `claim` or `reassign`; the actual responsibility-write boundary |
| `subject_type`, `subject_key`, `subject_revision_key` | Non-empty strings for the authorized business resource, object and instance's fixed business revision |
| `instance_key` | Non-empty native instance key; available even during initial start, but not durable if that transaction rolls back |
| `definition_version` | Positive integer for the immutable definition version already selected by the instance |
| `cycle_no` | Positive integer for this node execution's cycle |
| `node_key`, `node_execution_key` | Non-empty configured node key and native execution key |
| `node_type` | `approval` or `human_task`; no callback for gateways, service tasks, start or end nodes |
| `allocation_mode` | `direct`, `claim` or `multi` from the immutable node configuration |
| `aggregation_policy` | `all_approve` or `any_approve` for multi-approval; `null` for direct or claim |
| `allow_self_approval` | Boolean native initiator policy; `true` does not override the business validator's own conflict-of-interest rule |
| `initiator_member_id` | Positive integer of the instance initiator, not the current actor or a presumed author |
| `business_department_key` | Non-empty string or `null`, as resolved by the business provider |
| `previous_actor_member_id` | Positive integer or `null`, from the node execution; null when no single previous actor exists |
| `work_item_key`, `seat_no` | Existing non-empty work-item key and positive integer seat for claim/reassign; both `null` for enter |
| `previous_assignee_member_id` | Previous positive integer assignee for reassign, or `null` if unassigned; always null for enter/claim |
| `members` | Non-empty list of `{ member_id: positive int, sources: list<source> }`; exact phase semantics below |
| `members[].sources[]` | `{ source_kind: string, source_key: non-empty string, binding_revision: non-empty string }`, retained from native resolution or the seat/candidate's frozen binding |

`source_kind` is one of the supported assignment rule kinds:
`member`, `role_members`, `department_members`, `department_leaders`,
`subject_owner`, `initiator`, `previous_actor`. At enter, native resolution
deduplicates members and bindings, orders members by numeric ID, enforces
required sources and the 100-member limit, and rechecks each source through
Identity. Each entered member has at least one source. Claim/reassign pass the
frozen source list that native `eligible` just checked; no new source membership
or replacement pool is inferred. This hook does not make an ineligible or
empty-source assignment eligible.

Invocation order and coverage:

1. Native authorization, revision preconditions, subject snapshot/locking and
   the existing command transaction run first.
2. At **enter**, Workflow resolves required assignments, initiator exclusion and
   current Identity eligibility, checks the pool/direct cardinality, and resolves
   participants/watchers. The callback receives the **whole** proposed handler
   pool, including every candidate for claim and every seat for multi-approval.
   It runs before freezing `people_json` and before any work item or candidate,
   participant or watcher link for that node is written.
3. At **claim**, the candidate must already have a frozen candidate link and pass
   current native eligibility. The callback receives only that proposed assignee
   and that link's frozen sources, before the unassigned work item changes.
4. At **reassign**, native permission, pending-task revision, duplicate-seat,
   initiator and eligibility checks run first. The callback receives only the
   proposed replacement and the existing seat's frozen sources, before changing
   the assignee or automatic owner. It does not rebuild the node's frozen pool.

The method returns `void`. Accept the supplied list or reject it with the
exported `WorkflowException::unresolved('safe business reason')`; do not filter,
replace, persist or mutate Workflow's people, seats, votes, links or graph.
In particular, a conflicting author in `all_approve` must not disappear from
the required group. The provider should consult its own authorized, locked
business data and frozen revision policy. It must not read Workflow private
tables or perform irreversible/external effects. Reasons become native errors or
blocked-state diagnostics; do not put private candidate details in them.
The validator adds a business veto, not candidate authorization or a substitute
for `prepareSubjectSnapshot`, `snapshot` and decision-time business checks.

Failure and recovery retain the existing command semantics:

- Rejection during **initial start** escapes the transaction: no instance, node,
  work item, links or successful start receipt from that attempt are committed.
- Rejection when entering a **later node** is caught by the native
  `ASSIGNEE_UNRESOLVED` path. The completed predecessor remains completed; the
  new node and instance become `blocked` with no frozen people or work items for
  that rejected node. The command can therefore return a blocked instance, not a
  successful downstream assignment. An authorized explicit `retry` resolves
  current sources and invokes the validator again on the same pinned definition.
  If the conflict remains, it remains blocked. Editing a draft/template does not
  alter an in-flight instance's definition.
- Rejection of **claim/reassign** aborts that command transaction and keeps the
  previous assignment intact. It does not erase existing seats or silently
  change the approval count. Native retry still refuses to rebuild already
  frozen responsibility; use the authorized explicit recovery operation.
- A provider implementation failure is not converted into an empty-success pool.
  Other native exception codes keep their existing error/blocked semantics.

All currently implemented writes of a new handler are enter, claim and reassign.
Release clears an assignee and does not call this veto; decisions recheck their
existing business/Identity access, and reaching another human node calls enter
again. This change adds no delegation, proxy, additional-signature operation,
observer-role policy, service action or business condition fields.

The source package retains its declared `multi-tenant` edition. This optional
contract does not qualify standalone, install an application dependency, migrate
a database, or publish a package. A consuming application must adopt the exact
source/package through its native module lifecycle and explicitly implement the
business validator. Syntax or isolated contract checks do not prove transactional
database rollback, retry, tenant isolation or live business acceptance.

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
