# File / ContentTools existing-page consumer

`file-content-ui.mjs` emits an executable Playwright page function for the real File management page. It does not start a browser, create a context, log in, upload, alter permissions/providers, call a fixture endpoint, or prepare runtime resources. It uses the current `.grid-item` → `预览` → `文件预览` → `.content-tools-surface` consumer, not a mounted component fixture.

This is a source-ready driver, not evidence that a site or a permission matrix passed. Run only after the operator has normal current authority for the exact source/runtime, installed File artifact, identity/tenant/visible rows, existing files, data protection, remaining request budget, and exclusive browser window. Admission references below are audit pointers; the driver cannot grant or verify those external permissions.

## Offline entry

Use the checkout's registered Node via `scripts/project-env` where prepared:

```sh
python3 scripts/project-env exec -- node scripts/consumers/file-content-ui.mjs --emit /absolute/admitted-input.json
```

This only validates input and prints a function. Pass the emitted string to the registered mac-14 `browser_run_code_unsafe({code: emittedString})` **after** selecting the task's existing authorized page. No remote filesystem copy or package install is needed. Follow the registered mac-14 health/Profile 2/exclusive owner and login checks first. Codex IAB is the sole fallback on actual mac-14 unavailability; a separately authorized IAB Playwright-compatible page can call the exported `runFileContentUi(page, validateInput(input))`. Do not use a new empty profile or copy cookies. The driver neither selects nor closes a tab.

## Version 1 input

Required JSON fields (no credentials, token or signed URL):

| Field | Contract |
| --- | --- |
| `version` | `1` |
| `sourceCommit` | 40 lowercase hex; exact adopted source from current operator proof |
| `installedFileDigest` | 64 lowercase hex; installed File artifact from current adoption proof |
| `pageUrl` | Exact already-open File page URL, HTTP(S), without credentials/query/fragment; no assumed localhost route |
| `pageResources` | Finite array of `{ "url": "/exact-reviewed-asset", "type": "script" }`; types only `script`, `stylesheet`, `image`, `font`, same origin, no credentials/query/fragment or `/api`/`/adminapi` paths. Supply exact current build assets from reviewed manifest/page dependencies, never a prefix or wildcard. An empty list permits no static assets |
| `browser` | `mac14-profile2`; or `codex-iab` with nonempty `fallbackReason` |
| `admission` | Nonempty reference strings for `runtime`, `sourceAdoption`, `identity`, `tenantRows`, `dataProtection`, `requestBudget`, `browserExclusive` |
| `expiresAt` | Absolute epoch milliseconds; checked at execution, not refreshed automatically |
| `maxApiRequests` | Positive integer ≤40 and ≤actual remaining authorized budget; never a fresh allowance |
| `identity` | `{ "id": positiveInteger, "tenantName": "exact current API tenant name" }`; IDs and tenant row authority must be bound by admission |
| `allowedFileKeys` | Explicit nonempty array of `file_` plus32 lowercase hex. Must include **every row on the currently admitted list page**; worktree is not data isolation |
| `file` | `{ "key": "file_…", "sha256": "64 lowercase hex", "name": "exact displayed name", "mime": "trusted lowercase MIME" }`; key must be in allowed scope |
| `action` | `preview` or `download`; download only when independently allowed |
| `expected` | `{ "state": "read_only", "canDownload": false, "viewerAdapterId": "builtin.image", "media": "img" }` for a viewable case; described below |

`expected.state` is one of `available`, `read_only`, `preview_unavailable`, `service_unavailable`, `permission_denied`. Viewable cases require `media` = `img`, `object`, `audio`, or `video`. Unavailable cases require `message` with exact actual user-facing text and `viewerAdapterId` matching the actual API, including `null` for no adapter. `canDownload` is always required and is independent of preview state. Duplicate visible names intentionally fail rather than selecting the wrong file.

## Actual operations and assertions

One invocation reloads only the existing page, observes actual `/adminapi/user/info` and File list responses, then opens the selected card's preview dialog. It checks actor ID/tenant name, all visible file keys against scope, selected stable key/hash/MIME/name, current capability state/adapter and download permission. It asserts rendered media or the explicit unavailable message with no media/iframe/script. A `download` case clicks the real independently authorized download button once, verifies the actual resolver grant and completed browser download, then deletes only that invocation's browser download temporary file. It never writes the downloaded file to a chosen path or returns its bytes.

The guard permits the known read-only File list/category/assets and host user info/menu endpoints, plus preview resolution for admitted keys and original download resolution only for the selected admitted download case. Other requests are limited to GET of the exact page document, GET of exact `pageResources` URL/type pairs, and GET of `/api/storage/delivery` for a URL actually returned by a validated resolver response after identity/file checks. Each delivery must match an admitted visible File key/hash and its independent original-byte permission; this includes existing thumbnails on the same admitted list page. Delivery resource types are only fetch/XHR/image/media. Arbitrary same-origin GET/HEAD, unlisted assets, other API actions and cross-origin requests are aborted; expiry applies to all requests. If the real shell needs another read endpoint or static asset, stop with the exact failure and review that direct dependency; do not remove the guard or broaden to wildcard APIs. Resolve calls wait at most3 seconds for the real identity/list responses. No response is mocked and no permission is patched.

Counts include observed `/adminapi/` requests during the invocation, including thumbnails and shell reads. A budget overrun blocks the request and returns `FAIL_OR_UNKNOWN_PRESERVED`; the count includes the blocked attempt. The output omits response bodies, tokens, cookies, delivery URLs and file contents. It does not persist a result file or change the task ledger. Store the returned compact receipt in the current authorized task result, including the exact input/source/artifact hashes and browser owner proof.

## Missing real cases to run after legal admission

Use separately admitted existing identities/objects and one input per case; do not generate fixtures or change service/ACL configuration with this driver:

1. Private image/media/PDF preview with `canDownload=false`, a real derivative grant, visible media and no download button.
2. Same stable resource under an independently download-authorized identity: exact download grant and one completed original download. Management permission is never requested by the driver.
3. Existing unsupported/trusted MIME case with null adapter: explicit no-preview message, no media and download only if independently granted. Real same-name/different-magic upload preparation remains outside this driver.
4. Existing provider/service-unavailable state: exact service/unavailable message, no mounted media. No service is stopped to manufacture it.
5. Existing denied-preview but allowed-download state, or denied-both state: no mounted media and exactly the independent download UI decision.

An absent File list row is **not** a preview-denied PASS: this driver stops before selecting it. Cross-tenant hidden objects, manage-API denial, revoked delivery, malicious bytes/history, server MIME detection and server data/concurrency protection need their separately admitted API/business consumers. An initial File snapshot response that lacks capabilities also fails explicitly rather than guessing adapter state. Do not claim the complete FUN09/FUN10 matrix from these scoped UI results.

There is no retry loop. A failed or unknown download/resolve must be reconciled by its original request and operator budget before another invocation. Old File31/RichText28 evidence is reusable only for its original scope. This driver does not authorize or retry File-first, source adoption, Cloud PUT, capture/store/drain, Document save or any platform-refused operation.
