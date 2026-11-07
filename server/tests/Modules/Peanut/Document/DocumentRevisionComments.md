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
