# Document revision comment acceptance

Run the checked-in tests with the project's registered PHP environment after normal Composer synchronization:

```sh
python3 scripts/project-env exec -- php server/vendor/bin/phpunit --no-configuration --do-not-cache-result --fail-on-warning --fail-on-risky server/tests/Modules/Peanut/Document/DocumentRevisionCommentTest.php
python3 scripts/project-env exec -- php server/vendor/bin/phpunit --no-configuration --do-not-cache-result --fail-on-warning --fail-on-risky server/tests/Modules/Peanut/RichText/RichTextPlainTextProjectionTest.php
python3 scripts/project-env exec -- node --test web/src/modules/peanut-document/tests/comments.test.cjs
python3 scripts/project-env exec -- pnpm --dir web exec vue-tsc --noEmit --project src/modules/peanut-document/tsconfig.json
```

The PHP fixture loads production Document/RichText through the normal Composer loader and asserts installed/source byte equality. It does not add a production namespace loader or require production source files directly. Source versions are established through actual public Document create, solo save, freeze and snapshot/read calls. Two independent synthetic actors exercise actual comment writes, reads and controller dispatch.

Native behavior exercised includes Document authorization orchestration, owner resolution, prepared-version proof binding, tenant-scoped models, immutable revision validation, transactions, thread CAS, request receipts, exact source/quote/range checks, pagination and append-only activity. Tests compare all Document/source/comment/request rows before and after stable-code rejections. Current owner/session revocation and source corruption are also injected at the final guard, proving rollback rather than only early input rejection. A storage-trigger failure propagates the real database exception and permits an unchanged request to retry after the failure is removed.

The external current Identity session/read-fence, permission principal, module availability, business-owner policy and audit boundaries are explicitly synthetic. The test does not claim native Identity SQL lock behavior or real Article/Newsroom permissions. The File reference boundary accepts only an empty reference set; this is not File resource-authorization acceptance. Contexts use the public validated-session factory; production Admin roots are created by the actual Document authorization service.

SQLite is private and uses one connection per isolated test process. Tables derive columns/defaults/unique/check constraints and Document-owned foreign keys from the actual native migrations. External Identity/File foreign-key targets are omitted at the declared fixture boundary. SQLite does not prove MySQL DDL upgrade acceptance, concurrent lock ordering or same-request races. Positive revisions/comments/statuses are never inserted by fixture SQL; explicit negative fact injection and the synthetic storage-failure trigger are documented in the tests.

The client tests execute the same TypeScript state machine used by the public Vue component with controlled delayed/error transport responses. They cover code point selections, context and thread switching, unknown/rejected original-request recovery, denial after lost acknowledgement, malformed/stale responses, late acknowledgements, current-state reload after replay, and view-only/disposed states. This is not a DOM-render, browser selection, real HTTP session, or websocket acceptance test.

The existing native MySQL Document persistence/prepared-version/action-boundary scripts require their separately registered isolated database resource. They must not fall back to a shared database. This fixture includes the actual prepared-version and current-save rejection contract without claiming that separate native MySQL suite was executed.

## Readable owners with editing unavailable

The owner-state regressions first create, save, freeze and read a real revision, then create its thread through the actual public Document contracts. A second synthetic actor reads both the thread list and detail while the synthetic owner permits view but returns either an edit business conflict (409) or an edit-scope miss (404). Both reads must preserve the fixed source and stored activity and report `can_write: false`; restoring edit scope or unlocking the owner must return `true`. All nine Document/source/comment/receipt tables are compared before and after these reads.

All four new command types and all four exact original-request replays must still propagate the original edit 404 or 409 without changing those tables. After restoring edit access, the original inputs recover their original receipts without adding activity. Both edit failures injected at the final native fence must roll back the whole write and leave the original request retryable. View 403/404/409, edit 401, the framework's HTTP 500 exception, and an unexpected programming exception are checked separately on both read endpoints; each must propagate the same exception object with no database changes.

Actual missing Document/revision/thread IDs and a missing synthetic business owner still return their exact 404 codes. View-scope 404 injected at the native locked and final read guards must prevent both read endpoints from returning data, even when an edit-only 404 would merely set `can_write: false`. Existing tenant, wrong revision/thread binding, immutable-version, source-digest and Unicode anchor tests remain unchanged.

Only the business-owner state/error boundary is synthetic in these added cases. They do not prove any application's actual review or approved-state policy, live IAM behavior, HTTP serialization, or MySQL concurrency. No owner-state exception changes a production command or its request-recovery authorization. Test temporary output can be redirected with `PEANUT_DOCUMENT_COMMENT_TEST_TMP`; its existing default remains unchanged. Normal Composer class resolution and source/installed-file equality assertions remain mandatory.
