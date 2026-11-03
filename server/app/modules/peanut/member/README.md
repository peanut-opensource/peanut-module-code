# Member CRM reuse

`peanut.member` owns tenant member profiles, tags, balances and sessions. Public
PHP contracts are listed in `module.json` and bound by `src/ModuleProvider.php`.
Consumers declare a dependency on `peanut.member` and inject its contracts; do
not import its Models or access its tables.

## Existing consumers and entry points

| Entry | Input and output | Existing consumer |
| --- | --- | --- |
| `MemberQueries::identity` | Trusted execution context and member ID → `MemberIdentitySnapshot` or `null` | OAuth `OAuthCommandService` |
| `MemberQueries::lockedIdentity` | Same input and output, with a row lock inside the caller's transaction | OAuth account binding |
| `MemberProfileCommands::completeOAuthProfile` / `fillOAuthProfile` | Context, member ID and profile fields → `void`; avatar remains a storage reference | OAuth profile completion |
| `MemberQueries::balanceSnapshot` | Context and member ID → snapshot with integer cents, or `null` | Payment `RechargeApplicationService` |
| `MemberAdministration::members` | Admin list parameters → `PageResult`; `export=1/2` returns export metadata/file result | `MemberController::lists` and the Web member list |

Minimal read integration inside an existing trusted tenant operation:

```php
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use PeanutAdmin\Kernel\Auth\TenantContext;

// Inject MemberQueries through the application container.
$snapshot = $members->identity($context, $memberId);
if ($snapshot === null) {
    // Map absence to the consumer's own business result.
    return null;
}
return ['id' => $snapshot->id, 'nickname' => $snapshot->nickname];
```

`$context` is the current trusted `TenantContext`, not a tenant ID accepted from
request input. Native tenant-owned model scope supplies isolation; passing a
context object does not switch the current execution scope. The caller still
owns its operation authorization. Query results are snapshots: do not keep them
across users, tenants or requests. `identity` returns the stored avatar reference;
resolve file URLs at the presentation boundary. `lockedIdentity` requires the
caller to own the surrounding transaction. Profile/tag commands that document a
caller-owned transaction must participate in that transaction. Command failures
propagate the existing business exception; callers must not convert them into
successful writes. Missing identities are `null`, and `memberFields` returns `[]`
when absent.

## Admin Web list

The actual consumer is `web/src/modules/peanut-member/views/list/index.vue`.
It uses `getMemberList`, `getMemberExportInfo` and `exportMembers` from that
module's `api.ts`; these are application-local API helpers, not an independently
exported frontend package contract.

`GET /adminapi/peanut.member.list` accepts `keyword`, `channel`, `status`
(`'1'` normal / `'0'` disabled), `create_time_start`, `create_time_end`, `page_no`
and `page_size`. Normal reads return `{ lists, count, pageNo, pageSize }`; each
row includes `tags`. The page shares the current filters with export, resets
them on reset/session-context change, and cancels obsolete reads on replacement
or unmount. Clearing the date picker omits both date bounds. HTTP middleware
requires login, tenant module availability and the corresponding admin
permission. API errors use the shared interceptor; cancellation must not become
an empty successful business result.

The list displays existing tag assignments without a separate tag-catalog
request. Tag catalog management and member creation remain their existing
operations. This page does not add profile/tag editing or alter balance, session,
permission or tenant contracts.
## Member HTTP and access tokens

The module owns MemberLoginController, MemberSelfController, MemberAccountLogController and MemberVerificationController. Its normal route contribution retains the existing login/user/account_log/sms URLs and admission middleware. Verification-code routes are contributed only when peanut.notification is selected. Identity administration login and generic HTTP context adapters remain host-owned.

MemberAccessTokens is bound to the module's UserTokenService. The host CheckTokenMiddleware consumes parseSessionToken; OAuthEntryService consumes createToken after completed OAuth login. JWT issuer/audience, tenant/member/session/revision claims, persistent-session verification and current-session revocation retain the existing implementation. A parsed signature alone never admits a member. This module does not provide order auto-cancellation or automatic order verification.
