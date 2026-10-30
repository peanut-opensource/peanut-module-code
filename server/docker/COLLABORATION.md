# Optional collaboration deployment

The default `compose.yaml` contains PHP, Nginx and MySQL. The optional
`compose.collaboration.yaml` overlay adds the application's verified HTTPS/WSS
ingress. Explicit `APP_ENV=development` can instead publish HTTP/WS as described
below. The independent `peanut-hocuspocus` repository owns the Node program,
its lockfile, build and root `compose.yaml`. Node reads its configuration and
listens directly. It does not prepare or activate PHP, invoke a PHP container,
or obtain a global process activation before starting.

Node uses the application's configured HTTP/HTTPS operation family at
`/adminapi/internal/collaboration/v1/`. Authorization sends
`{token, document_name}` with the user's original native token. PHP validates
the token, tenant, document and current permissions. Internal requests use the
configured HMAC service secret; HTTPS also verifies TLS. Per-room business barriers,
durable saves, receipts and recovery remain part of document operations; they
do not grant a global deployment activation.

Use `collaboration-configuration.example.json` as an input template. Its `secret`
is a directly configured private string of 32–4096 UTF-8 bytes, without newline,
carriage return or NUL. Store the completed input as an ordinary, single-link
0600 file; never commit it or print it. No service-secret file or ticket-secret
file is required. The same secret is published as `COLLABORATION_SECRET` to PHP
and `SERVICE_SECRET` to Node. Instance, service instance and key identities remain explicit.
PHP `.env` uses ThinkPHP's literal `INI_SCANNER_RAW` quoted UTF-8 values;
Compose/Node uses its own single-quoted encoding. JSON escape sequences are not
used to encode PHP secrets. The publisher reads these known flat formats and
rejects ambiguous hand-edited inline comments or Compose interpolation/double-quote escapes
rather than treating its own parse as proof of the runtime's value.
The current Compose encoding also rejects values ending in an odd number of
backslashes before publishing service, ingress or PHP configuration. This
includes the secret and preserved advanced service settings; no value is
rewritten to make it encodable.
The required `websocket_scheme` selects `wss` or, only for development, `ws`.
Both internal origins must use the matching `https` or `http` scheme; there is
no automatic downgrade or certificate-verification bypass.

Select the independent `service_root`, Compose project and registered interface
network. First copy that service's root `.env.example` to `.env` (0600).
Its `PORT` and `BIND_ADDRESS` select the host listener (defaults 1234 and
127.0.0.1); publication preserves those choices and other advanced settings.
Node's internal port remains 80. The publisher supplies `BASE_URL` with the
`/adminapi/internal/collaboration` base path, `SERVICE_SECRET`, `INSTANCE_ID`,
`SERVICE_INSTANCE_ID` and `KEY_ID`. The service's default API map supplies the
relative `v1/` operation paths. Its precise readonly mounts contain dist,
dependencies, package metadata and API configuration, with the public CA added
by an explicit overlay. There are no named volumes, application source mounts
or Node document copies. Persistence stays behind the PHP APIs.

For `websocket_scheme=wss`, provide the application's `server/docker/tls/certificate.pem` and `private-key.pem`.
The private key must be 0600. The certificate must chain to the clients' normal
trust stores, or the deployment must explicitly select a private CA as described below.
The certificate must cover the configured PHP/control names and WSS hostname.
Both private origins use HTTPS on container-only port 8443; do not expose it as
a host port. Publicly trusted HTTPS uses `ca_sha256: ""`, with no CA file required;
PHP uses Guzzle's system trust and the independent service uses Node's normal trust.
The service owner selects its normal Compose configuration without the private-CA
overlay for this mode. No existing CA file is deleted by changing the selection.

For private HTTPS, additionally provide `server/docker/tls/ca.pem` (0444 or 0644)
and record its exact SHA-256 as `ca_sha256`. Configuration publication validates
and copies that public bundle to the application's
`private/collaboration/service/ca.pem` and the service's `certs/ca.pem`, both 0644.
The normal application permission preparation retains PHP UID 33 access. The
Node container runs as UID 33 and only needs its public CA, with readable parent
directories. No command creates a CA, disables certificate verification or
changes host/browser trust. Clients must trust the selected CA normally.

```sh
python3 server/docker/scripts/configure-collaboration.py plan --app-root /absolute/app --configuration /absolute/private-configuration.json
python3 server/docker/scripts/configure-collaboration.py enable --app-root /absolute/app --configuration /absolute/private-configuration.json --expected-revision 0
python3 server/docker/scripts/configure-collaboration.py status --app-root /absolute/app
```

These commands publish or inspect configuration only. They do not start, stop,
reload, probe, build or pull services. The publisher's revision/digest fields protect
the application's configuration publication with compare-and-swap; Node has no
configuration revision/digest requirement. Deployment-only
`COLLABORATION_CONFIG_REVISION`, `COLLABORATION_CONFIG_SHA256`,
`COLLABORATION_SERVICE_ROOT` and `COLLABORATION_COMPOSE_PROJECT` live in the
existing private 0600 `server/docker/collaboration.env` projection, written only
by this publisher and read by plan/status/enable and application Compose selection.
They are not PHP business configuration. An already enabled configuration can
only be repeated unchanged. Interrupted publication is not runtime readiness.
Repeating an enabled configuration also verifies its current PHP, ingress,
independent root configuration and CA outputs without rewriting them; a
publication mismatch is rejected. Plan/status report
`runtime_state=not_observed`; errors never include secret values or captured
subprocess output.

The independent service uses ordinary owner-invoked Compose commands from its
repository root. This selected private-CA/cross-Compose integration explicitly
uses `docker compose -f compose.yaml -f docker/ca.example.yaml -f docker/network.example.yaml`;
the publisher supplies the paired `CA_SHA256`, `NETWORK` and
`COMPOSE_PROJECT_NAME` values. Append the normal `up -d`, `ps` or `logs`
operation. Ordinary service deployments need neither overlay unless selected.
Prepare its dependencies and build using its own locked
instructions before starting. The APP's existing `start.sh` starts only its
PHP/Nginx/MySQL composition and optional ingress. It does not start Node or make
Node's startup depend on an application deployment command.

Application stop, upgrade and recovery manage only the APP composition. Enabling
the external collaboration endpoint does not require stopping Node or obtaining
a Node shutdown receipt. The APP's writer hold, container/mount identity checks,
and database backup/recovery protections remain in force. Backend downtime leaves
Node listening: new connections require backend authorization, existing grants
expire or fail renewal, and unconfirmed content remains protected in memory.

Only the independent service owner stops or recreates Node. Its native signal
drain must join pending work and obtain actual save acknowledgments; failed or
unknown saves prevent successful drain. APP shutdown is not proof of Node drain,
and an environment flag never clears room fences or unresolved captures. There
is no APP command to stop or disable the independent Node process; configuration
publication and service lifecycle are separate responsibilities.

Existing unresolved instances retain their original owner, saved identities,
receipts and failure budgets. Do not adopt or restart an unknown process to
manufacture a new identity. Historical migrations, stored evidence and existing
recovery snapshots remain unchanged; source changes do not establish runtime
switching or release qualification.

## Development without certificates

Set the application's `APP_ENV=development` explicitly, select `websocket_scheme=ws`
and set `ca_sha256` to the empty string. Set `php_origin` and `control_origin`
to the actual registered HTTP routes. `wss_hostname`/`wss_port` are historical
field names for the public WS address; that port and `wss_bind` must match the
independent service's existing `PORT` and loopback `BIND_ADDRESS`. Publication
preserves the listener rather than moving it. The browser page must use HTTP;
HTTPS pages continue to require WSS. The collaboration editor uses
`crypto.getRandomValues()` for UUIDv4 IDs, including on HTTP hostnames where
the secure-context-only `crypto.randomUUID()` is unavailable.

For host PHP source development, Node's `php_origin` must be the registered
host route reachable from its container (for example the registered
`host.docker.internal` route). PHP's `control_origin` uses Node's registered
loopback HTTP listener; the browser uses its public loopback WS listener. This
layout does not start or need a collaboration ingress or a shared Docker network.
The publisher retains the explicit network setting for the independent service
owner; use the ordinary service Compose file without CA/network overlays unless
the selected route requires a network.

For an application running in Compose, the existing application start entry
selects `compose.collaboration-development.yaml`. It changes only the same Nginx
service's optional template: a container-only HTTP listener on 8080 serves the
exact signed PHP operation family. It exposes no management host port and leaves
the public application's internal-namespace deny in place. Set `php_origin` to
that Nginx Docker alias on port 8080, and `control_origin` to the independent
Node service's `collaboration` alias on port 80. The independent service owner
uses its existing network overlay to join the application's actual default
network (`network` in the input). The browser reaches Node's own loopback WS
listener; no second PHP or Node process or TLS proxy is added.

HTTP publication does not inspect or copy certificates and removes
`CA_SHA256`/`NODE_EXTRA_CA_CERTS` from the independent service projection. Start
that service without its CA overlay. Existing certificate files are left alone.
HMAC request/response binding, fresh nonces, current native user authorization,
grant renewal, revocation acknowledgments, save receipts and business barriers
remain required on HTTP. Other environments reject WS/HTTP, and selecting WSS
in development retains the same certificate validation as production.

Old published private configurations are not changed by this source update.
The old four deployment-only fields in `server/.env` require explicit owner
review and relocation into the existing projection before normal publication;
enable refuses that legacy state rather than silently resetting its revision.
Keep the original completed private TLS input and its protected backup, add only
`"websocket_scheme": "wss"` to a reviewed 0600 input copy, then use the same
publisher with the actual current revision (shown below as `N`):

```sh
python3 server/docker/scripts/configure-collaboration.py migrate-state --app-root /absolute/app --configuration /absolute/reviewed-private-configuration.json --expected-revision N
python3 server/docker/scripts/configure-collaboration.py status --app-root /absolute/app
```

Migration verifies the old input's canonical SHA against the four old fields,
the unchanged PHP settings, independent service configuration, existing ingress
projection and both published CA copies. It retains revision, identities,
secret, enablement and all runtime settings, publishes the new input digest in
the existing projection and removes only the four old fields from `server/.env`.
A mismatch stops the operation; do not manufacture values to satisfy it. An
interruption after projection publication can resume the same migration input.
Repeating a completed migration verifies all PHP, projection, service and CA
outputs again and returns without rewriting files.
After success, normal unchanged enable publication uses that reviewed input.
Changing TLS to HTTP is a separate owner-controlled reconfiguration requiring
the original instance's safe stop/drain boundary; this metadata migration never
changes transport or authorizes a restart, drain or loss of unresolved saves.

## Source development with host PHP and TLS

An already installed source checkout can keep its registered host PHP listener
and use `compose.source-collaboration.yaml` explicitly. This composition starts
only a TLS ingress: it does not mount PHP source, adopt deployment identities,
seal vendor, change host file ownership, start MySQL, or start Node. It is never
selected by the APP startup composition above. Docker must actually reach the
registered PHP listener through `host.docker.internal`; verify that route before
using the composition. Do not change the host listener to a public binding to
make an unverified route work.

Use the same configuration publisher and certificate checks. Set `php_origin`
to the ingress's registered Docker DNS name on HTTPS port 8443 and
`control_origin` to `https://localhost:8443`; the leaf must cover both names and
the selected WSS hostname. This explicit development composition publishes its
management port **only at 127.0.0.1:8443** and WSS only on loopback. The production
overlay retains container-only management TLS. Neither endpoint disables the
existing HMAC, token, tenant, document or current-permission checks.

In an ignored, private `server/docker/.env.source-collaboration`, set the
registered `SOURCE_COLLABORATION_PROJECT` and numeric `SOURCE_PHP_PORT`. After
normal configuration publication, use both that file and `collaboration.env`:

```sh
cd server/docker
docker compose --env-file collaboration.env --env-file .env.source-collaboration -f compose.source-collaboration.yaml config --quiet
docker compose --env-file collaboration.env --env-file .env.source-collaboration -f compose.source-collaboration.yaml up -d
```

Start the independent Node service from its repository root with the selected
CA/network overlays on the same registered network **before starting this ingress**: Nginx resolves the
`collaboration` network alias while loading its configuration. Node can listen
while the backend TLS ingress is not yet ready; that is not an authorization or
save success. Its `PORT` and `BIND_ADDRESS` remain the service owner's explicit
listener choices; the publisher preserves that loopback binding.
Record the actual source, images, network, ports, certificate and service identity
before starting either composition. A healthy ingress is not a document save or
realtime qualification. Stop only the current owner's ingress through this same
explicit composition; Node still requires its independent durable drain.
