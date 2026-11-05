# Workflow Web public entry points

The package is `@peanut-modules/workflow-web`. Its default export contributes
the designer and runtime routes. `./runtime` contributes only runtime routes;
it does not import the designer or Vue Flow. These entries are consumed by the
application's locked frontend contribution loader. Do not register both.

## Embed a workflow designer in a business configuration page

`./designer-view` exports the same Vue component used by the standalone
`WorkflowDesigner` route. It includes the wizard, canvas, assignment editor,
validation, simulation, local draft recovery, CAS save, publication and explicit
activation of a published version. The host supplies its own page and navigation:

```vue
<script setup lang="ts">
import { ref } from 'vue';
import WorkflowDesignerView from '@peanut-modules/workflow-web/designer-view';

const editing = ref(false);
</script>

<template>
  <section>
    <h2>稿件审核流程配置</h2>
    <button v-if="!editing" type="button" @click="editing = true">配置审核流程</button>
    <WorkflowDesignerView
      v-else
      module-key="custom.newsroom"
      workflow-key="article-review"
      embedded
      @close="editing = false"
    />
  </section>
</template>
```

| Input / event | Contract |
| --- | --- |
| `moduleKey?: string` | Fixed business module key for this mounted editor. Omit to allow input. |
| `workflowKey?: string` | Fixed workflow purpose key for this mounted editor. Omit to allow input. |
| `embedded?: boolean` | Show a return button which confirms discarding unsaved changes before emitting `close`. Default false. |
| `close` | The return action was confirmed; the host can unmount the editor or navigate back. No automatic redirect. |
| `dirty-change(boolean)` | Reports changes to unsaved editor state; hosts with their own tabs/dialog controls must protect those unmount paths. |
| `definition(WorkflowDefinition)` | Latest successfully read server definition after load/save/publish/activate; not a business authorization grant. |

Keys are captured at mount. Use a new keyed instance when changing the business
binding, after handling unsaved changes; changing props alone does not retarget
an existing draft. Either key may be fixed independently. Fixed inputs cannot be
edited; switching definitions preserves fixed keys. Loading remains an explicit
"读取服务器定义" action, including for fixed keys. A missing definition may be
created by saving a new graph through the existing authorized draft endpoint;
other read failures remain visible and never masquerade as an empty definition.

The component requires the normal Web host (Vue Router, Pinia/user session,
Element Plus, permission evaluator and configured API base URL). Its containing
business page must declare its own access and action bindings through the normal
module contracts. Read requires `peanut.workflow.definition.read`; editing,
validation and simulation require `peanut.workflow.definition.write`;
publication/activation require `peanut.workflow.definition.publish`. The current
page's normal action binding evaluation still applies, and every request remains
subject to server permissions, tenant module enablement, scenario validation and
CAS checks. Embedding does not grant these permissions or expose design endpoints
in a runtime-only backend artifact. Use an installed full Workflow module and a
registered business scenario for the selected keys.

Publication activates its resulting immutable version through the existing
server contract. Explicit activation selects a server-listed published version.
Both refresh the server definition and CAS revision; if that read fails after
the command succeeded, the success notice remains and further writes stay blocked
until the user reloads. Conflicts retain local content for local stash, server
reload and manual merge. Failed commands are never automatically resubmitted.
Logout, identity changes and unmount reuse the existing editor cleanup. Omit all
props on the standalone route to keep its editable-key behavior.

This source export is consumed through the existing locked module package and
normal conflict-aware source/module upgrade flow. Do not copy the editor into a
generated business application or edit managed baselines to adopt it. This entry
does not register a media page, enable a tenant, install a module or migrate data.

## Embed a business task form

`./runtime-view` exports the existing runtime page as a default Vue component.
`./forms` exports `provideWorkflowTaskForm` and the types
`WorkflowTaskFormContext`, `WorkflowTaskFormResult`, and
`WorkflowTaskFormProvider`. Register the provider in an ancestor's `setup`;
render the runtime view as its child:

```vue
<script setup lang="ts">
import WorkflowRuntimeView from '@peanut-modules/workflow-web/runtime-view';
import { provideWorkflowTaskForm } from '@peanut-modules/workflow-web/forms';
import type { WorkflowTaskFormProvider } from '@peanut-modules/workflow-web/forms';

// saveAndConfirm is supplied by this business page's authorized save UI.
const props = defineProps<{ instanceKey: string; saveAndConfirm: WorkflowTaskFormProvider }>();
provideWorkflowTaskForm((context) => props.saveAndConfirm(context));
</script>

<template>
  <WorkflowRuntimeView :key="instanceKey" :instance-key="instanceKey" />
</template>
```

The provider receives `{ action, workItem, instance }` for `complete` or
`resubmit` only. Save and confirm business material through its owning module,
then return `{ attachment_file_keys, subject_revision_key?, input?, comment? }`.
Return `null` when the user cancels; throw an error to display a failure without
submitting the Workflow command. The provider must enforce its own authorized
business save contract. It must not treat a Workflow button as business access.
No provider means completion and resubmission stay disabled; claim and approval
do not invoke the business save UI.

The view accepts an optional `instanceKey` (omit it for the inbox) and emits
`instance` with the latest `WorkflowInstance`. Key the embedded view by business
object/instance so local form state is replaced when that object changes.
It uses the host's session and configured API base URL. Logout, tenant/member
changes and unmount dispose requests, timers, subscriptions and local data.
An asynchronous form result for a different selected instance is ignored;
the business form remains responsible for cancelling its own save UI.

Server `available_actions`, tenant enablement, object authorization and revision
checks remain authoritative. Errors are shown to the user; failed commands are
not automatically resubmitted. `./start` exports the existing start button:
pass `input: StartInput` and `availableActions`, handle `started(instance)`.
It sends a confirmed business revision and reads the current definition revision
before starting; it does not save a business draft.

## Existing consumers and limits

`runtime/contribution.ts` routes to `WorkflowRuntimePage.vue`, which already
consumes the form injection contract and the host Workflow API. This is the real
runtime consumer of the shared implementation. There is currently no registered
business form provider in another production module, so the example above is an
integration contract, not a claim of a second deployed consumer. Adding these
exports does not enable a tenant, register a scenario, install/migrate data or
change the host's module selection. Backend scenario providers and public
Query/Command exports remain in `server/app/modules/peanut/workflow/module.json`.
