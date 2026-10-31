# Notification reuse

The module already supplies scene/channel administration, delivery logs and a
member-owned inbox. Reuse these entries rather than adding another message store
or a second delivery queue. The frontend source entry below ships with this
application; it is not a separately published npm package.

## Inbox UI and runtime

`optional-runtime/src/index.ts` exports `NotificationInboxPage`,
`createNotificationRuntime`, `createNotificationFetchTransport`,
`notificationRuntimeKey`, `useNotificationRuntime` and their types. The existing
`NotificationInboxPage.vue` host is a real consumer of this entry and is mounted
by `contribution.ts` at `/notice/inbox`.

Minimal host setup (inside this module):

```ts
import { onBeforeUnmount, provide } from 'vue';
import {
  NotificationInboxPage,
  createNotificationRuntime,
  createNotificationFetchTransport,
  notificationRuntimeKey,
} from './optional-runtime/src';

const runtime = createNotificationRuntime({
  transport: createNotificationFetchTransport({ baseUrl, fetch: tenantFetch }),
  canRead: () => permissions.has('peanut.notification.inbox.read'),
  canManage: () => permissions.has('peanut.notification.inbox.manage'),
});
provide(notificationRuntimeKey, runtime);
onBeforeUnmount(runtime.dispose);
// Render <NotificationInboxPage /> below this provider.
```

`baseUrl`, `tenantFetch` and `permissions` come from the authenticated host; the
module does not create a session or choose a tenant. The application host uses
`tenantSessionFetch` and the current route permission check. The host supplies
Vue I18n with the `notificationInbox.*` messages from `views/log/locale/` (already
included by this module's frontend contribution). The page uses Element Plus.

`runtime.load()` fills reactive `state` with messages, pagination, selection and
errors. Filters are `all`, `unread`, `read`, `archived`; page sizes are 10/20/50.
`markRead`, `archive` and `bulk` return a boolean after refreshing the list.
The runtime uses the generated module SDK through its fetch transport, with
credentials and abort signals. Mark-read supplies the message revision as
`If-Match`; bulk operations send up to the current selection. No method sends SMS.

Each authenticated member sees their own inbox in the server-selected tenant;
client permissions are UI controls, and the server remains authoritative. A
read-only member can open details but cannot change state. Failures expose
`state.error` with message/messageKey, HTTP status and request ID when available;
the page provides refresh/retry. The host must dispose and recreate the runtime
when changing tenant/member, and dispose on unmount to abort requests and erase
items/selection. Do not share one runtime across identities. The optional module
contribution also registers its disposal with the host's tenant lifecycle.

Attachments here are metadata only; this page does not supply a download route.
Use the File module's authorized public API for an actual download integration.

## Existing administration and server consumers

`api.ts` supplies scene/channel/log requests used by `views/template`,
`views/channel` and `views/log`. Saving a scene updates only its provider template
ID, content and enabled status. The scene page preserves input on a failed save,
locks controls during the request and allows a deliberate retry. Saving settings
does not prove that a provider is configured or that delivery occurred.

Backend exports are declared in
`server/app/modules/peanut/notification/module.json`: `NotificationCommands` and
`NotificationQueries` provide administration; `VerificationCodeCommands` owns
verification codes; `Delivery/Application/NotificationService` owns template
snapshots, inbox/outbox and registered producers. Obtain these through the bound
module provider and use the established trusted context and permissions. Do not
import persistence Models or write notification tables from another module.

The real cross-module consumer is Workflow's
`Application/WorkflowNotificationDelivery.php`, which calls `publishProduced`
for committed immutable facts via a registered producer. Delivery returns message
and outbox records; queued acceptance is not delivery success. Unavailable
modules, missing schema, invalid context, stale revisions and missing recipients
remain explicit errors. See the backend `delivery/README.md`; configured channels,
applied migrations and worker adoption must be confirmed for each application.
No real channel operation is authorized by this integration example.
