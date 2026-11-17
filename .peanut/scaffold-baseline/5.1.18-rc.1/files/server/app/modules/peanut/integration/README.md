# Official integration module

This protected core module owns Tenant machine credentials, external channel
bindings, outbound Webhook lifecycle, delivery evidence, and self-scoped Tenant
session device controls. Technical OAuth/Webhook transports, destination safety,
and secret cryptography remain in `peanut-admin/core`; this module owns the
business records, schema, Provider bindings, and lifecycle.

## Reuse entry points and current consumers

Cross-module PHP consumers depend on the exports in `module.json`, not the
repository or `Service` implementations. `ModuleProvider` binds the contracts
through the ThinkPHP container; callers inject them through constructors.

| Entry | Input and result | Current consumer |
| --- | --- | --- |
| `Contract\ExternalChannelBindings::config` | Trusted `TenantContext`, `ExternalProvider` key → provider configuration array; absent configuration returns `[]` | OAuth `OpenPlatformApplicationService::getConfig` |
| `ExternalChannelBindings::update` / `mutate` | Trusted context, provider, configuration/atomic mutator, external identity → persisted Tenant binding | OAuth configuration services; Notification `NoticeChannelService` |
| `Contract\ExternalTenantResolutionService::verifiedCallback` | Provider, opaque callback key, operation/id and provider verifier → binding, verified value and Tenant system context | Application `OfficialAccountApplicationService` and `PaymentCallbackApplicationService` |
| `ExternalTenantResolutionService::bindingForCallbackReference` | Existing trusted OAuth state/ticket Tenant and binding reference → unverified binding candidate | OAuth `ThinkPhpOAuthCallbackLocator` |

OAuth and Payment business services belong to their external modules in the
module-development application. Integration remains the Code-owned source of
the shared binding contracts. For example, an already authorized OAuth use case
can read its configuration without implementing a second binding ledger:

```php
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;

final readonly class OpenPlatformConfiguration
{
    public function __construct(private ExternalChannelBindings $bindings) {}

    public function read(TenantContext $context): array
    {
        return $this->bindings->config($context, ExternalProvider::WECHAT_OPEN_PLATFORM);
    }
}
```

The calling use case owns its feature permission and request validation. Never
construct a Tenant context from a client Tenant id. Configuration can contain
secrets: redact them in the consumer's HTTP projection, as the existing OAuth
service does. Configuration reads may include disabled bindings for management;
they do not authorize callbacks. Callback resolution rejects missing, ambiguous,
inactive or invalid ownership with `ExternalTenantResolutionException`
(`EXTERNAL_CALLBACK_REJECTED`). The verifier must perform the provider's actual
verification; callback candidates alone do not prove authenticity. OAuth state
expiry/one-time checks and payment validation remain with their business owners.
`mutate` holds the adapter's row lock for its configuration change; it is not a
transaction around an external network action.

The Web entry is `web/src/modules/peanut-integration/contribution.ts` →
`RuntimePage.vue` → `runtime/IntegrationSecurityPage.vue`. The existing
`runtime/index.ts` exports the typed transport, response parsers, runtime and
page. `RuntimePage.vue` is their real consumer: it supplies `tenantSessionFetch`,
current permissions and the current-session logout callback, provides
`integrationSecurityRuntimeKey`, and calls `dispose` on unmount. Reusing the
page requires that same provider lifecycle; no independent token store or URL
assembly is needed. The runtime validates responses, exposes independent
loading/error states, suppresses superseded requests and clears one-time
credentials on disposal. Attempt-detail loading clears prior rows before
requesting another delivery, so errors cannot leave another delivery's evidence
under the selected key.

### Custom security page composition

In a source host, reuse the existing relative `runtime/index.ts` barrel (the
package root exports the route contribution; it does not export a `/runtime`
package subpath). For example, a sibling custom page can provide the runtime:

```ts
import { onBeforeUnmount, provide } from 'vue';
import {
  createIntegrationSecurityFetchTransport,
  createIntegrationSecurityRuntime,
  integrationSecurityRuntimeKey,
} from './runtime';

// sessionFetch, permissions and logoutCurrentSession belong to this host.
const runtime = createIntegrationSecurityRuntime({
  transport: createIntegrationSecurityFetchTransport({ baseUrl, fetch: sessionFetch }),
  permissions,
  onCurrentSessionRevoked: logoutCurrentSession,
});
provide(integrationSecurityRuntimeKey, runtime);
onBeforeUnmount(runtime.dispose);
```

Render the existing `IntegrationSecurityPage` under the provider, or render a
custom view from `runtime.state` and call its load methods. The built-in page
already calls `load()` on mount; do not call it again from a parent mount hook.
The seven live `permissions` callbacks are shown in `RuntimePage.vue` and map to
the permission table below. Recreate this runtime when identity/tenant changes;
the logout callback must retain the host's current-session generation guard.
One-time token/secret disclosure belongs to this instance, is explicitly cleared
by `clearDisclosure()`, and must not enter general page state or a persistent
store.

For a refusal example, `canReadWebhooks: () => false` makes `loadWebhooks()`
clear that surface and report `INTEGRATION_PERMISSION_DENIED` without transport.
Keep independent machine/session/delivery errors visible; a failed section must
not become a successful empty result. Replacing a tab or button does not bypass
server revision checks, exact permissions or current-session revocation rules.

### Channel, callback and Webhook boundaries

Use the existing `ExternalChannelBindings` example above for channel config.
For callbacks, inject `ExternalTenantResolutionService` and call
`verifiedCallback($provider, $callbackKey, $operation, $operationId, $verifier)`
with the owning provider's real verifier. The verifier receives the selected
binding configuration and returns its verified value (or rejects/returns false).
The result contains `context`, `binding` and `verifiedValue`; only after successful
verification may the owner continue its own callback workflow. Never substitute
an always-true verifier or accept a request tenant ID. A missing/inactive binding
or rejected verification stays `EXTERNAL_CALLBACK_REJECTED` with existing audit.

The security page's `createWebhook({ name, url, events })`, `rotateWebhook` and
`disableWebhook` use its existing authenticated runtime and server validation.
These are Webhook endpoint bindings, not `ExternalChannelBindings` records.
Trusted business producers use the existing `Webhook/TrustedWebhookPublisher`
and typed `TrustedWebhookEvent` within the established application composition;
HTTP clients cannot choose arbitrary producers or private payloads. Endpoint
creation, enqueue acceptance and successful external delivery are distinct
results. A custom page reads delivery state and `loadAttempts(deliveryKey)`;
it must not dispatch a delivery as a preview, configuration check or UI retry.
Actual external-channel verification/delivery requires configured provider
credentials and its separately authorized operation; these composition examples
do not establish that a channel has been configured or verified.

## Authorization and public boundary

Every user-facing method accepts a Kernel `AuthorizedOperationContext`, checks
the exact `peanut.integration` resource and operation, and derives the
Tenant, account, member, current session, and request from the validated Tenant
context. Platform-audience and machine-audience credentials cannot construct
that context. The Application binds these operations to dedicated permissions:

| Operation | Permission |
| --- | --- |
| `machine-read` | `peanut.integration.machine.read` |
| `machine-manage` | `peanut.integration.machine.manage` |
| `webhook-read` | `peanut.integration.webhook.read` |
| `webhook-manage` | `peanut.integration.webhook.manage` |
| `delivery-read` | `peanut.integration.delivery.read` |
| `session-read` | `peanut.integration.session.read` |
| `session-revoke` | `peanut.integration.session.revoke` |

Session operations are always self-scoped. No account, member, Tenant, handler,
worker, signing secret, token digest, or arbitrary destination address is
accepted from an HTTP client. A trusted application producer may enqueue a
typed Webhook event through `TrustedWebhookPublisher`; it cannot select a
handler or secret.

The target HTTP adapter exposes only these Tenant-audience operations under `/api/v1`:

- `GET|POST /integration-security/machine-identities`,
  `POST /integration-security/machine-identities/{identity_key}/rotate`, and
  `DELETE /integration-security/machine-identities/{identity_key}`;
- `GET|POST /integration-security/webhooks`,
  `POST /integration-security/webhooks/{endpoint_key}/rotate-secret`, and
  `DELETE /integration-security/webhooks/{endpoint_key}`;
- `GET /integration-security/deliveries` and
  `GET /integration-security/deliveries/{delivery_key}/attempts`;
- `GET /integration-security/sessions` and
  `POST /integration-security/sessions/{session_key}/revoke`.

Create and rotate bodies contain only names, scopes, expiry, URL, and event
subscriptions as applicable. Tenant/account/member/handler/secret fields and
undeclared fields are rejected. Machine tokens and Webhook signing secrets are
returned only by the original successful create or rotate response; ordinary
reads never disclose them.
The Admin route uses `peanut.integration.access`; machine, Webhook,
delivery, and session data then load independently under their own read
permissions, so denial or failure of one surface cannot block the others.

## Schema and lifecycle

The module declares six owned InnoDB tables. Its versioned migrations own their
schema changes; the five machine/Webhook/audit tables below are distinct from
the existing `pa_external_channel_binding` provider-binding ledger. Table presence
or source declarations do not prove a particular instance has adopted a migration.

- `pa_integration_machine_identity`: Tenant key, server-generated identity key,
  name, sorted unique scope JSON, status, token prefix/digest/last four, family,
  expiry, last use, rotation/revocation timestamps, creator, revision, and
  timestamps. `(tenant_id, identity_key)` and `token_digest` are unique. Active
  identities may become `rotated` or `revoked`; terminal states never reactivate.
- `pa_integration_webhook_endpoint`: Tenant key, server-generated endpoint key,
  HTTPS URL, sorted unique event keys, encrypted signing secret plus key id,
  active/disabled status, creator, revision, and timestamps. Secrets are
  server-generated, disclosed only in the initial/rotation result, encrypted
  with AES-256-GCM at rest using the Tenant/endpoint key as authenticated
  associated data, and absent from ordinary records and audit.
- `pa_integration_webhook_delivery`: one Tenant/endpoint/event idempotency row,
  canonical payload, digest, state, bounded attempt count, availability/lease,
  safe result code, payload expiry, and timestamps. States are
  `pending -> delivering -> delivered`, `delivering -> retryable -> delivering`,
  or `delivering -> permanent_failed`. An expired lease records a redacted
  `WEBHOOK_LEASE_EXPIRED` attempt in the same transaction; attempts below eight
  become retryable and attempt eight becomes terminal.
  Maximum attempts are eight. `(tenant_id, endpoint_id, event_key)` is unique.
- `pa_integration_webhook_attempt`: immutable redacted attempt evidence with
  outcome, HTTP status, safe error code, duration, and timestamp. It contains no
  URL, IP, header, body, secret, token, exception, or provider response.
- `pa_integration_security_event`: immutable redacted audit evidence. Target
  keys are SHA-256 digests and metadata is bounded scalar JSON.

Tables use Tenant-prefixed indexes and composite foreign keys. Tenant and
member parents are `RESTRICT`; endpoints and machine identities are retained
and disabled/revoked rather than deleted. Delivery payload is erased after
seven days; terminal delivery and attempt evidence is retained for 30 days and
then purged by an explicit maintenance call. Audit retention remains Host
policy and is never cascaded by feature deletes. Rollback is forward-only: a
code rollback leaves inert additive tables and does not restore credentials.

## Credential and Webhook security

Machine tokens contain 256 random bits. Plaintext is returned exactly once on
create or rotation; only a SHA-256 digest, non-secret prefix, and last four are
persisted. Authentication checks format, active state, expiry, Tenant-bound
machine audience, and every requested scope. Rotation atomically creates the
successor and terminally rotates the predecessor; revoke terminally invalidates
the digest. A trusted feature scope catalog rejects unknown scopes. The
Application reads the exact catalog from `INTEGRATION_MACHINE_SCOPES`; its
resolver returns it only for an already authorized `machine-manage` Tenant
operation. An absent or invalid catalog fails closed. Create and rotation both
re-evaluate the current catalog. Authentication revalidates every
persisted scope against the current trusted catalog before recording use or
creating a principal, so removed or stale scopes fail closed. Tokens and
secrets are never serializable through ordinary records.

Webhook secret encryption uses `INTEGRATION_WEBHOOK_SECRET_KEY_ID` and a
base64-encoded 32-byte `INTEGRATION_WEBHOOK_SECRET_KEY`. Missing or invalid
configuration leaves read-only Webhook listing available while operations that
seal or open a secret fail closed.

Webhook URLs require HTTPS on port 443, contain no userinfo or fragment, and
use an ASCII DNS name or IP literal. `localhost`, local/internal suffixes,
metadata hostnames, and every loopback, private, link-local, multicast,
unspecified, documentation, or otherwise reserved address fail closed. All DNS
answers must be public. A validated destination carries the approved IP set to
the transport; adapters must connect only to that set with the original Host
and TLS SNI, disable redirects, and perform no independent fallback lookup.
Every send revalidates DNS. A 3xx response is a permanent security failure.
Address classification uses explicit IPv4 and IPv6 CIDR deny ranges covering
loopback, private, link-local, metadata, carrier NAT, translation,
documentation, benchmark, multicast, reserved, and unspecified space. One
unacceptable DNS answer rejects the entire destination.

The signature input is `v1.<unix_timestamp>.<delivery_key>.<payload_sha256>` and
the header is `v1=<lowercase HMAC-SHA256>`. The delivery key is also the
idempotency header. Payloads are canonical JSON, at most 256 KiB, with a replay
timestamp window owned by the HTTP transport adapter. `408`, `425`, `429`, transport failure and `5xx`
are retryable with bounded backoff; other `4xx` and all redirect/security
failures are permanent. The registered `CurlWebhookTransport` performs real
network I/O; do not run dispatch as a configuration check. Synthetic test
transports are separate from this production binding.

## Errors and integration status

Stable package codes include `INTEGRATION_PERMISSION_DENIED`,
`INTEGRATION_INPUT_INVALID`, `MACHINE_IDENTITY_NOT_FOUND`,
`MACHINE_TOKEN_INVALID`, `MACHINE_TOKEN_EXPIRED`, `MACHINE_SCOPE_DENIED`,
`INTEGRATION_REVISION_CONFLICT`, `WEBHOOK_ENDPOINT_NOT_FOUND`,
`WEBHOOK_DESTINATION_DENIED`, `WEBHOOK_SECRET_INVALID`, and
`SESSION_DEVICE_NOT_FOUND`. Public adapters map these to generic Problem
Details without SQL, existence leaks, addresses, token material, or secrets.

The Module owns its manifest, migration, Provider, Admin controllers/routes,
permissions and menu contribution. Core owns cryptography, destination
validation, and transport protocols. Application code provides no default
secret, default machine scopes, or network transport fallback. The synthetic
database checks live under `server/tests/Modules/Official/Integration`; they
require the separately registered Integration database and never call an
external Webhook.
