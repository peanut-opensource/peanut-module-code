# Article Document owner and business Save

Existing Article body owners use peanut.article.edit for document creation, editor metadata, write tickets and business Save. Add permission alone does not edit an existing owner; ordinary detail still requires peanut.article.detail. Status permission neither writes a draft nor creates a version.

For an existing unpublished Article with empty legacy HTML, POST peanut.article.document with id and a stable request_id. Nonempty legacy HTML requires explicit migration; no empty Document replaces it. GET peanut.article.document.editor?id=… returns the exact owner, current owner_revision, Document state sequence, authorization revision, current content capabilities and any saved binding. Its article_metadata comes from the same locked Article row as owner_revision. The image field is the stable writable file_key (or empty); image_url is a separate display delivery URL and must never be sent as a writable reference. Upload and picker consumers retain FileRecord.file_key rather than deriving a key from a URL.

Solo draft changes use the existing Document save command. Collaborative transport uses the Article owner entry and the public CollaborationTransports::ownerTransport contract. Article rechecks the document and current authorization revision after transport preparation; it does not mint a second business credential. The selected transport must supply the matching owner and publicly allowed WSS configuration. Missing or changed transport fails closed; private control origins are not browser discovery addresses. Actual routes and response fields come from the generated API contract for this version.

POST peanut.article.document.save with id, document_id, expected_state_seq, owner_revision, stable request_id, cid, title, is_show=0 and supported metadata. Body bytes and client owner fields are forbidden. The original request records the current actor and full input hash, commits before Document freeze/RPC, obtains a committed immutable version, then rechecks current owner authorization and state CAS in a short transaction. That transaction commits metadata, fixed revision/source sequence/snapshot digest, and the completed Save receipt together. Autosave never changes this binding.

An editor keeps the owner_revision loaded with its Article metadata until an explicit reload. A fresh edit-purpose response rechecks current authorization before a new Save and must match that owner baseline and the exact durable Document sequence. Its authorization digest may change with a legitimate durable ACK because the digest includes state_seq; it is not compared to the pre-ACK digest. An admitted unknown request keeps the original key and payload.

After an unknown result, GET peanut.article.document.operation with the original id and request_id. A prepared receipt confirms preparation only, not an executing process or a successful Article binding. Only an explicit continuation repeats the same Save request and payload with the original person's current authorized session; it does not create a new operation. A deterministic owner-revision conflict commits a `conflicted` receipt before returning 409. Its query has `error_code=ARTICLE_BODY_SAVE_OWNER_CHANGED`, no result or continuation, and never reports the competing Article binding or the rejected request's committed Document version as this request's business success. Other errors remain unconfirmed rather than being classified from HTTP 409 alone. This also supports solo mode, whose Document operation query is not a collaborative barrier query.

Apply `20261025050000_add_article_body_save_conflicted_receipt.sql` through the normal Module/ThinkPHP Migrator lifecycle. It widens only the receipt CHECK constraints, retaining all existing rows, keys and immutable Document history; `completed_at` records a terminal receipt time for both completed and conflicted phases. Existing prepared rows contain the original actor and full input hash, but not the original owner-revision value. They are not guessed or backfilled into conflicts by migration or GET. The original actor may explicitly reconcile the exact original payload through the same Save entry: actor/hash and current owner CAS run before any new freeze; an obsolete baseline commits the conflict without changing the Article binding or consuming another Document target. Preserve that original input before a deployment/HMR reload; a missing payload is not authority to reconstruct or replace it. The UI retains unsaved metadata after conflict, disables continuing the rejected request, and requires an explicit discard/reload to adopt the current owner baseline. A failed Save may trigger one read-only query of its original identifier, never an automatic repeat POST.

If that ALTER committed but its native preservation callback rejected MySQL's `_utf8mb4` to `_ascii` digest-literal normalization, retain the failed original migration and its afterimage. The immutable `...conflicted_receipt-repair-20261025.sql` declares that exact original key through the existing append-only repair protocol. Its sole `SELECT 1` does not repeat ALTER or update any old row. Before and after, the native Article callback requires the already committed conflicted CHECK poststate and preserves all receipt rows, remaining observed schema, identities and allocators. The comparison accepts only the two complete observed digest CHECK spellings, after proving the fixed `input_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` column and identical regex bytes; no other CHECK or charset is ignored. A missing/partial poststate rejects the verification-only repair rather than guessing recovery. Only the normal native Migrator can append the repair's applied row and the normal lifecycle can reactivate the failed Article installation. The original failed row remains failed and resolves through that applied immutable repair; changing the guard is not permission to rerun the original SQL.

New Save requires unpublished state. The status command can publish a managed Article only when Document resolves its formally saved fixed binding; failure rolls back the status transaction. Public detail renders that fixed version through ArticlePublicBodyRenderer, never the live draft or legacy HTML. Historical HTML conversion is still explicit. Legacy unmanaged CRUD remains available. Status-only users may hide a managed Article without changing its fixed binding.

Apply the new incremental migration through the normal Module lifecycle before using these entries. Source and pure contract checks do not prove real database transactions, two-user collaboration, revoke/retry behavior or WSS readiness.

## Reuse existing Article capabilities

### Embedding Article editing and history

The existing Web list page composes `ArticleBodyEditor.vue` and
`ArticleBodyHistory.vue` in `web/src/modules/peanut-article/views/list/`.
These are Article application components, not new npm package subpaths:

| Component | Inputs | Output / responsibility |
| --- | --- | --- |
| `ArticleBodyEditor` | `articleId`, identity-partitioned `contextKey` | `metadata` (authorized editor metadata), `metadata-ready(boolean)`, `locked(boolean)`, `saved(ArticleBodySaved)`; exposes `save` using the existing Article command |
| `ArticleBodyHistory` | `articleId`, `documentId`, `contextKey` | Loads version summaries with the existing Document API and embeds `DocumentViewer` with the selected immutable `revisionId`; no independent Save or publication |

Reuse that composition when changing Article presentation. The editor embeds
`DocumentEditor` with the fixed Article owner, edit-purpose metadata resolver and
owner transport resolver. Retain the original owner revision, pending request and
target while changing layout; do not POST the draft JSON to Article or save it
again through another rich-text form. Only Article's completed business receipt
updates the saved body binding. Document versions and collaboration persistence do
not publish Article; publication still uses Article's existing status command and
its fixed binding validation. A custom read view may use the public Document
Viewer shown in [Document's guide](../document/README.md), with current owner
authorization and an explicit revision for historical content.

`module.json` exports `Contract\PublicArticleQueries` for storefront reads and member collections, `Contract\ArticleQueries` for administration reads, and `Contract\ArticleAdministration` for authorized commands. Resolve these through the existing Module provider in the current application context; do not import Article Models or read its private tables across modules. A real consumer is `app\api\controller\ArticleController`, which establishes the public tenant or authenticated member context before calling PublicArticleQueries. Admin and Document-owner operations retain their specific `peanut.article.*` permissions and current tenant/module admission checks.

The existing UniApp SDK is `uniapp/src/modules/peanut-article/api/news.ts`; PC uses `pc/modules/peanut-article/api/article.ts` with its injected request client, and Web administration uses `web/src/modules/peanut-article/api.ts`. Each client keeps its own transport and authentication lifecycle. The UniApp search page and news page already consume `getArticleLists`; no additional query wrapper is needed:

```ts
import { getArticleLists } from '@/modules/peanut-article/api/news';

const page = await getArticleLists({ keyword: '产品', pageNo: 1, pageSize: 15 });
// page: { lists: Article[], count: number, pageNo: number, pageSize: number }
```

The SDK maps pageNo/pageSize to the public `GET api/article/lists` parameters page_no/page_size. Reads use the host's resolved public tenant and enabled module; public lists contain visible articles only. Collection commands and collection lists require the current member session. Errors reject through the client's existing request layer; callers must distinguish a failed request from an empty successful page. The search consumer shows loading/retry states, retains the submitted keyword for pagination, and discards superseded or hidden-page results. Returning to an interrupted search resumes only the same read. Search does not create content, collections or notifications.

Article owner collaboration uses the server-selected transport. Browser URL parsing accepts ws/wss `/collaboration` URLs with DNS or IPv6 hosts and optional ports, while rejecting credentials, queries and fragments. The server owns environment admission (ws only in development) and transport configuration; the Article UI must not require an explicit port or invent a fallback endpoint. Owner/document/revision/capability checks still apply before connecting.

When enabling collaboration, the Article SDK preserves the structured HTTP 403 / `MODULE_TENANT_DISABLED` rejection before the shared Axios interceptor reduces it to a message. This exact guard runs before ownerTransport can initialize collaboration, so the UI reports missing tenant collaboration entitlement and offers an explicit metadata/authorization reload. It does not retry the POST automatically or clear a pending Save/target. Network failures, timeouts, malformed responses and other errors still retain the original unknown-outcome observation path; an arbitrary HTTP error is not proof that no mode change committed. Session and refresh handling remain on the original shared client.
