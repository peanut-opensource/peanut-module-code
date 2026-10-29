# Workflow graph and HTTP contract

The only graph protocol is `contract_version: 2`. Member IDs, definition IDs and business keys are JSON strings. Revisions, sequence numbers and protocol versions are JSON integers. HTTP payloads reject undeclared fields. All persistent writes require `Idempotency-Key`; PUT draft and publish use the same rule. Replaying a key with a different body conflicts; replay still checks current access. Responses use `{data,meta:{request_id}}`; lists add `page,page_size,total` to meta. The admin prefix is `/adminapi/api/v1/workflow`.

The trusted Module registry selects scenarios by module/workflow, checks the business object, current owner and department, and supplies typed fields and local actions. No HTTP value selects a class. Graph values do not grant permissions. Layout is separate and excluded from the execution hash.

## Complete minimal all approval graph

```json
{
  "contract_version": 2,
  "scenario_contract_version": 1,
  "subject_resource_key": "peanut.artifact-revision.artifact",
  "subject_read_operation": "read",
  "subject_start_operation": "submit",
  "start_permission_keys": ["peanut.artifact-revision.artifact.submit"],
  "required_capabilities": ["workflow.approval", "workflow.all_approve"],
  "nodes": [
    {"key":"start","label":"Start","type":"start","config":{}},
    {"key":"review","label":"Review","type":"approval","config":{
      "allocation_mode":"multi","aggregation_policy":"all_approve",
      "assignments":[
        {"kind":"member","key":"20","required":true,"department_scope":null},
        {"kind":"member","key":"21","required":true,"department_scope":null}
      ],
      "participant_rules":[],"watcher_rules":[],
      "allow_self_approval":false,"opinion_required":false
    }},
    {"key":"approved","label":"Approved","type":"end","config":{"result_code":"approved"}},
    {"key":"rejected","label":"Rejected","type":"end","config":{"result_code":"rejected"}}
  ],
  "transitions":[
    {"key":"start-review","from":"start","to":"review","action":"advance","operation":"submit","permission_keys":["peanut.artifact-revision.artifact.submit"],"return_edge":false,"max_traversals":null},
    {"key":"approve","from":"review","to":"approved","action":"approve","operation":"approve","permission_keys":["peanut.artifact-revision.artifact.approve"],"return_edge":false,"max_traversals":null},
    {"key":"reject","from":"review","to":"rejected","action":"reject","operation":"reject","permission_keys":["peanut.artifact-revision.artifact.reject"],"return_edge":false,"max_traversals":null}
  ]
}
```

Identifiers in this example must be registered by the installed business Module; the example itself grants no capability. Assignment kinds are member, role_members, department_members, department_leaders, subject_owner, initiator and previous_actor. The last three use `key:null`. Required empty sources block; optional empty sources do not erase required ones. Duplicate members produce one responsibility with all source bindings preserved. Direct requires one person. Claim creates one shared work item and frozen candidates. Multi creates one work item per frozen person and requires all_approve or any_approve; first_response is explicitly unsupported. Direct/claim use aggregation_policy:null. All approve rejects immediately on a reject; any approve waits after a reject and accepts on the first approve, rejecting only when all frozen seats explicitly rejected. Cancelled seats do not count as approval.

Gateway config is `{branches:[{transition_key,condition}],default_transition_key}`. Condition groups use `{all:[...]}` or `{any:[...]}`; leaves use `{field,operator,value}`. Operators are eq/ne/gt/gte/lt/lte/in/not_in/is_null/is_not_null. Registered types are boolean/integer/string/enum/datetime/decimal; decimal values are exact decimal strings and datetime values are UTC ISO strings. Missing fields and invalid types block. Branch order is significant. Service config is `{action_key,contract_version,input}`; input maps parameter keys to `{source:"literal",value:JSON}`, `{source:"instance",field:"subject_key"|"subject_revision_key"|"instance_key"|"cycle_no"}` or `{source:"node_input",field:registered_field_key}`. Entry freezes the resolved parameters and validates the registered JSON schema; actions require a trusted registry and the official Task publisher/handler. End config is `{result_code}`. Return edges target a prior human node with `action:return,return_edge:true,max_traversals:1..10`. A human correction node has resubmit as its sole forward completion action; other human tasks have complete. Graph limits are 100 nodes, 200 edges, 50 automatic steps and 100 resolved people; conditions allow depth 3 and 50 leaves. The graph declares every used capability.

## Definitions (full artifact only)

| Method / suffix | Request | data |
| --- | --- | --- |
| GET definitions | page=1, page_size=20 | `{items:DefinitionSummary[]}` |
| GET definitions/:module/:workflow | none | `DefinitionSummary & {graph,layout,versions:[{version,graph_sha256,published_at}]}` |
| GET definitions/:module/:workflow/scenario | none | `{scenario_contract_version,fields,result_codes,capabilities,actions:[{action_key,contract_version,input_schema}]}` |
| PUT definitions/:module/:workflow/draft | `{expected_revision:int|null,graph,layout}` | `{definition_id,draft_revision,issues}` |
| POST definitions/validate | `{graph}` | `{issues}` |
| POST definitions/preview | `{graph,basis:{initiator_member_id:string,business_department_key:string|null,previous_actor_member_id:string|null,subject_type,subject_key}}` | `{simulated:true,nodes:[{node_key,members:[{member_id:string,source_kind,source_key,binding_revision}]}],issues}` |
| POST definitions/:module/:workflow/publish | `{expected_revision:int}` | `{definition_id,version,active_version,draft_revision}` |
| POST definitions/:module/:workflow/activate | `{expected_revision:int,version:int}` | `{definition_id,draft_revision,active_version,status}` |
| POST definitions/:module/:workflow/retire | `{expected_revision:int}` | `{definition_id,draft_revision,active_version:null,status:"retired"}` |

DefinitionSummary is `{definition_id:string,module_key,workflow_key,status:draft|active|retired,draft_revision:int,latest_version:int,active_version:int|null}`. Issues are `{code,path:string,message}`. Layout is `{nodes:[{key,x,y}],viewport:{x,y,zoom}}`. Preview is simulated and cannot replace actual node-entry resolution or current qualification checks. Scenario fields are a map to `{type,nullable,values?}`; public actions omit internal Task registrations. Definition permissions are peanut.workflow.definition.read/write/publish, with validate/preview requiring write. Definition DTOs do not return available_actions.

## Instances and work items

POST `instances` body:
`{module_key,workflow_key,subject_type,subject_key,subject_revision_key,expected_revision:int,business_department_key:string|null,attachment_file_keys:string[]}`.
`expected_revision` is the selected definition draft_revision. Start requires peanut.workflow.instance.start plus graph start permissions and the current business start operation.

GET `instances/:instance_key` and every runtime write return Instance:
`{instance_key,status:running|blocked|completed|cancelled|terminated,result_code:string|null,revision:int,subject_revision_key,active_nodes:ActiveNode[],own_work_items:WorkItem[],reassign_work_items:ReassignWorkItem[],available_actions:string[],event_cursor:int,blocker:null|{code,message,node_execution_key}}`.
ActiveNode is `{node_execution_key,node_key,label,type,status:pending|active|blocked,revision:int,cycle_no:int,task_termination_evidence_required:boolean}`. End results do not directly mutate business status.
ReassignWorkItem is `{work_item_key,label,revision:int}`. Only current object-authorized holders of `peanut.workflow.diagnostics.reassign` receive pending items from active/blocked nodes; other callers receive an empty list. This is a maintenance selection, not a personal responsibility or permission to act for an assignee. The command rechecks the instance/task revisions, original sources, self-approval and duplicate seats. Termination requests Task diagnostics only when an active node's `task_termination_evidence_required` is true: effect key exists, its receipt is absent, and a Task job exists. Pure human nodes and blocked service nodes with no job need no Task diagnostics; outstanding effects retain their Task terminal-evidence and management gates.

WorkItem is `{work_item_key,instance_key,node_execution_key,node_key,label,type,revision:int,instance_revision:int,node_revision:int,owner_member_id:string|null,assignee_member_id:string|null,assignment_state:unassigned|assigned,status:pending|completed|cancelled,decision:string|null,relations:string[],available_actions:string[]}`. Available actions come from current qualification, assignment and authorization. Clients send actions, never a transition key.

GET `inbox?filter=assigned|claimable|completed|initiated|participant|watcher&page=1&page_size=20` returns `{items}`. All except initiated use flat InboxWorkItems: the WorkItem projection without `available_actions`. List rows describe read-visible responsibilities; action qualification is not evaluated for each row. Fetch instance detail for current-authorized operations, then use its WorkItems and revisions. The missing list field does not mean an action was denied. Initiated uses an instance summary with empty active_nodes/own_work_items/reassign_work_items/available_actions. Native SQL visibility applies before count and pagination; listing does not expand business-object read or operation permissions.
GET `instances/:key/events?after_sequence=0&page_size=20` returns `{items:[{sequence_no:int,event_key,node_execution_key:string|null,actor_member_id:string|null,comment:string|null,metadata:object,occurred_at:UTC}]}`. Cursors are monotonic, scoped to one instance.
The existing `tenant.workflow.reassign` event links to the actual reassigned node execution. Its metadata is `{work_item_key,node_key,cycle_no:int,seat_no:int,old_assignee_member_id:string|null,new_assignee_member_id:string,old_owner_member_id:string|null,new_owner_member_id:string|null,owner_source:string,frozen_sources:[{source_kind,source_key,binding_revision}]}`. These are the retained seat and sources, with the real member IDs before and after the update; an automatic owner follows the assignee, while another owner source is retained. The event, required reason and reassignment commit together. Completed opinions and other event metadata are unchanged; credentials and private session or File access references are not part of this audit.
GET `instances/:key/materials` requires current instance read and current owning-business read/scope. It returns `{revision_key,sha256,fields:object,reference:object}` for the frozen submission version, with at most 64 KiB of registered material fields and stable references. It prepares the owner-native immutable value before the read transaction, then rechecks the current owner permissions and persistent version under native locks. It does not expose private notification facts, document bodies or file URLs. The instance frozen version must still match at the final lock.

All work-item writes carry base fields `{node_execution_key,expected_revision:int,expected_node_revision:int,expected_task_revision:int}`:

| POST suffix | Additional fields | Permission |
| --- | --- | --- |
| tasks/:work_item_key/claim | none | peanut.workflow.task.claim |
| tasks/:work_item_key/release | none | peanut.workflow.task.release |
| tasks/:work_item_key/decisions | `{decision:approve|reject,comment:string|null,attachment_file_keys:string[]}` | peanut.workflow.task.decide |
| tasks/:work_item_key/completions | `{comment:string|null,attachment_file_keys:string[],input:object}` | peanut.workflow.task.complete |
| tasks/:work_item_key/return | `{comment:string,attachment_file_keys:string[]}` | peanut.workflow.task.return |
| tasks/:work_item_key/resubmit | `{comment:string|null,attachment_file_keys:string[],subject_revision_key}` | peanut.workflow.instance.resubmit |

Every write rechecks business operation/edge permissions. Reject and return require a reason; opinion_required applies otherwise. Comment length is at most 2000 characters. No arbitrary target_node_key, reason alias or operation_output is accepted. Claim/release have no comment field. Successful partial votes advance instance/node/item revisions. Release before a substantive vote returns the candidate item to unassigned; automatic owner follows assignee while explicit owner is preserved.

POST `instances/:key/withdraw|terminate|retry` carries `{expected_revision:int,comment:string}`. Reassign carries `{expected_revision:int,comment:string,work_item_key,assignee_member_id:string,expected_task_revision:int}`. Permissions are peanut.workflow.instance.withdraw/terminate and peanut.workflow.diagnostics.retry/reassign. Retry preserves the blocked node identity and frozen inputs. An outstanding Task effect requires original-job recovery; termination cannot guess its result.

Errors use the standard application problem envelope. Stable codes include WORKFLOW_DEFINITION_INVALID, CAPABILITY_UNSUPPORTED, ASSIGNEE_UNRESOLVED, WORKFLOW_DEFINITION_CONFLICT, WORKFLOW_INSTANCE_CONFLICT, TASK_ALREADY_COMPLETED, WORKFLOW_ASSIGNMENT_DENIED, WORKFLOW_SUBJECT_NOT_FOUND, WORKFLOW_SUBJECT_REVISION_CONFLICT and ACTION_BLOCKED. A revision conflict requires the client to reload the instance or definition.

`WORKFLOW_INSTANCE_CONFLICT` remains HTTP 409. Its problem data is `{error_code,current_state:Instance|null}`. After the command transaction has rolled back, the server obtains a separate current instance-read context and reads through the normal instance query, including current owning-business permission and object scope. The state contains the normal authorized projection and only the caller's own work items. Missing read permission or an invisible object returns `current_state:null`; an internal conflict locator is never independently returned. A conflict without an instance locator also returns null. Other query, dependency or database failures remain errors rather than being reported as an invisible state. The state is a fresh read, not a lock-time snapshot or permission to replay an opinion. Clients keep unsent input and use their existing authorized query refresh before a new command.

Definition validation issues retain exactly `{code,path,message}`. `path` is a JSON Pointer into the submitted request, using the original array indices before canonical sorting; an issue without a narrower location uses `/graph`. For example, unsupported first-response aggregation points to `/graph/nodes/1/config/aggregation_policy`, and an unsupported assignment kind to `/graph/nodes/1/config/assignments/0/kind`. A concrete unsupported node strategy is reported before an unsupported capability declaration for that strategy. Unsupported declared delegation, proportional or sequential capabilities point to their original `/graph/required_capabilities/index`. Validate returns `data.issues`; direct definition HTTP 422 errors from draft, preview or publish also include `issues` in the standard problem data. Unsupported strategies remain rejected and never map to supported execution policies. The Workflow transport preserves problem data as read-only error details; no request fields, permission keys or primary targets change.

## Transaction and worker boundary

Native lock order is idempotency record → business object → definition when starting → instance → node executions by ID → work items by ID. All effects, events, votes and idempotency receipt use one native database connection and transaction. Legal opinions persist when the next node cannot resolve people or evaluate registered input; initial unresolvable starts reject atomically. Each return creates a new cycle and newly resolved responsibility; blocked retry is not a new cycle.

Service tasks require the installed official Task publisher both when publishing a definition and starting its instance. The original job and effect key are persistent. A fenced native start-intent transaction records the first action start and closes ordinary withdrawal; subsequent failures preserve this fact. The separate effect transaction holds the official job renewal UPDATE lock through commit, and assertLeaseOwned runs before the business effect and before commit; a lost lease rolls back the local business effect, graph advance and receipt. Durable receipt replay does not execute the effect or traverse again. Both transactions recheck the same fixed payload, business revision and current action authority. The business provider must implement local same-connection idempotence; an external irreversible action is not accepted by this V1 contract. No separate Workflow queue exists.

The 20261012 migration preserves the 20260921 migration and rejects a nonempty legacy Workflow baseline. It does not infer old graphs, responsibility or historical business results. Existing data requires an explicit reviewed migration decision.
