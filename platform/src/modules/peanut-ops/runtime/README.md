# Peanut Admin Ops Console Web

This development-only package provides exact platform Ops Console response
parsers, a fixed transport, isolated state, and the reusable console page.
It exports no route or shell contribution. Host registration and server-owned
provider, restore-target, maintenance-reason, and log-source options belong to
the application's platform host and registered operations providers. A private
maintainer task number is not a public configuration or dependency.

The client never accepts handlers, commands, paths, SQL, DSNs, credentials,
stack traces, or raw log lines. Restore always selects an allowlisted new target
and the UI never renders server problem details.

## Host integration

The module entry is `platform/src/modules/peanut-ops/contribution.ts`. It exports
`createOpsConsoleRuntime`, `opsConsoleRuntimeKey`, `OpsConsolePage`, and the
`OpsConsoleTransport`, `OpsTransportResult`, and `MaintenanceScheduleInput` types.
The existing consumer is `platform/src/App.vue`: it provides one runtime and
renders the page with backup, upgrade, diagnostic, and provider slots. Its
`createPlatformOpsTransport` adapter in `platform/src/api/platform.ts` uses the
authenticated platform client; reusing the page does not create another session
or HTTP client.

```ts
import { onBeforeUnmount, onMounted, provide } from 'vue';
import {
  createOpsConsoleRuntime,
  opsConsoleRuntimeKey,
} from './modules/peanut-ops/contribution';
import { createPlatformOpsTransport } from './api/platform';

// providers, maintenanceReasons and logSources must match the server registry.
// can(permission) is the host's current platform permission lookup.
const runtime = createOpsConsoleRuntime({
  transport: createPlatformOpsTransport(),
  providers,
  maintenanceReasons,
  logSources,
  canRead: () => can('platform.ops.read'),
  canBackup: () => can('platform.ops.backup.manage'),
  canRestore: () => can('platform.ops.restore.manage'),
  canMaintain: () => can('platform.ops.maintenance.manage'),
  canReadLogs: () => can('platform.ops.logs.read'),
});
provide(opsConsoleRuntimeKey, runtime);
onMounted(() => runtime.load());
onBeforeUnmount(() => runtime.dispose());
```

Render `OpsConsolePage` beneath that provider. A shell that survives navigation
must call `dispose()` when changing identity or being destroyed, as the existing
host does. A page-scoped provider disposes on page unmount. Disposal aborts
outstanding reads and clears the instance state;
it does not cancel an already accepted server task. Call `load()` when entering
again. The page loads permitted runtime events on mount; the host owns overview
loading and the extra slot data.

## Inputs, results and boundaries

- Transport methods receive an `AbortSignal` and return `{ status, headers,
  body }`; the existing adapter converts the application envelope into the
  runtime's parsed response contract. Do not pass raw Axios responses.
- `runtime.state` exposes overview, maintenance, submitted tasks, event pages,
  loading/mutation flags and separate safe errors. Permission functions are live
  host lookups; the server still authorizes every operation. This console is
  instance/platform scoped, not a tenant admin page. Do not share its runtime
  between identities or requests.
- `scheduleMaintenance({ reasonKey, startsAt, endsAt })` takes UTC ISO strings
  with milliseconds, such as `2026-10-31T02:00:00.000Z`. The reusable page keeps
  picker values as local `Date` objects and converts them with `toISOString()`
  at submission. Do not format a local wall clock with a literal `Z`. End must
  follow start, with a maximum 24-hour duration; the server enforces the same
  rule. Existing window values are displayed as returned UTC strings.
- Maintenance writes use the loaded revision (`If-Match`) and a per-operation
  idempotency key. A revision conflict requires reloading current state before
  retrying. Backup and restore submissions return accepted task state; refresh
  through `refreshTask(task)` to observe completion. Acceptance is not proof
  of a successful backup or recovery.
- Provider and target options only constrain client selection; they do not
  configure server adapters or authorize a target. Restore requires an existing
  backup reference and an explicitly registered isolated target. Source identity,
  provider availability and server-side protection remain prerequisites.
- Errors expose an allowlisted message, status, stable code and optional request
  ID. Render these safe fields, never server problem details. Event filters use
  `setLogFilter(source, severity)`; cursor changes require reapplying the filter.
  The runtime isolates stale event responses and `dispose()` invalidates pending
  responses for the host lifecycle.

## Existing slots and refusal example

`OpsConsolePage` has five unscoped slots: `refresh`, `backup`, `upgrade`,
`diagnostics` and `providers`. The existing provider and runtime remain in the
host; slots replace presentation only. For example, beneath that same provider:

```vue
<OpsConsolePage>
  <template #refresh>
    <button
      type="button"
      :disabled="runtime.state.loading || runtime.state.mutating"
      @click="runtime.load()"
    >Refresh operations</button>
  </template>
  <template #upgrade>
    <p>Application upgrade controls supplied by this platform host.</p>
  </template>
</OpsConsolePage>
```

The custom refresh uses the existing runtime; it does not also load from a new
HTTP client or create another provider. Slot content receives no implicit
runtime, task or permission props. Keep the host's existing upgrade/backup
controls and their authorized operations when replacing the illustrative text.
No Web tenant layout is an alternative platform authorization source.

For a refusal example, `canRead: () => false` makes `load()` record
`OPS_PERMISSION_DENIED` with status 403. Display the existing forbidden state;
a custom refresh button cannot turn it into a successful empty overview.
Mutation callbacks retain their independent permission checks. For conflicts,
load current state and obtain a new reviewed operation; do not silently resubmit
a restore or maintenance command. `refreshTask(task)` observes an accepted task;
it does not submit that task again. Slots must keep those state and action
semantics, including queued/running versus succeeded/dead/cancelled.
