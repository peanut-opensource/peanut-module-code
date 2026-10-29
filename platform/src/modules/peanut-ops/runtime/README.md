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
