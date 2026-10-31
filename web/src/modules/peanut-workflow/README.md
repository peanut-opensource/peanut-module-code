# Workflow Web public entry points

The package is `@peanut-modules/workflow-web`. Its default export contributes
the designer and runtime routes. `./runtime` contributes only runtime routes;
it does not import the designer or Vue Flow. These entries are consumed by the
application's locked frontend contribution loader. Do not register both.

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
