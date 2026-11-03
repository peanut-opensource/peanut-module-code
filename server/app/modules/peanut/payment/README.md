# Payment composition

`module.json` exports `Contract\PaymentMethod`, `PaymentChannelGrantCommands`,
`RechargeCommands`, `RechargeQueries` and `RefundReconciliationCommands`.
Use the selected Module provider; do not read Payment Models or create another
order, grant or settlement ledger.

## Methods, grants and application entry points

| Contract | Existing consumer / binding | Responsibility |
| --- | --- | --- |
| `PaymentMethod` | Stable provider identifiers: `WECHAT=2`, `ALIPAY=3`; `isProvider` checks the set | Identifier only; not channel availability or a grant |
| `PaymentChannelGrantCommands` | `ThinkPhpPaymentChannelGrantCommands`; `PayConfigApplicationService` calls `ensureSelfGrant` | Resolve pay-way/provider/channel and the tenant's active external binding; explicit grant/revoke remain privileged application operations |
| `RechargeQueries` / `RechargeCommands` | Bound to module `RechargeApplicationService`; module `Service/MemberRechargeApplicationService.php` is the member API consumer | Current member config/orders, order creation, prepay and verified callback settlement |
| `RefundReconciliationCommands` | `ThinkPhpRefundReconciliationCommands` | Existing refund reconciliation contract; not a browser success callback |

The host establishes the current tenant/member context and operation access.
Grant lookup uses the existing Integration external binding and verifies its
tenant/status. Grant records/configuration stay server-side; do not pass the
array returned by `activeGrantForTenant` to a browser. `lock=true` is used inside
the caller's transaction. `ensureSelfGrant` reuses an actual configured binding;
it does not create provider credentials or authorize arbitrary merchant access.
The methods taking tenant/binding IDs are not public unauthenticated endpoints.

Payment creation validates the enabled recharge setting, amount and available
terminal channels. Prepay accepts the member's own unpaid order and an enabled
pay way, then rechecks the active tenant grant; WeChat identity is resolved through
OAuth when required. A configured method is not proof of a successful external
request. Callback parsing and `settleVerifiedCallback` stay on the existing
verified channel/binding path; settlement validates order, amount, currency,
provider/grant and transaction identity. Browser navigation never credits balance.
Transactions, audit, existing idempotency and reconciliation remain service-owned;
embedding a component does not supply those guarantees itself.

## PC and administration presentation

The [PC recharge guide](../../../../../pc/modules/peanut-payment/README.md)
contains the actual `@peanut-admin/peanut-payment-pc/api` imports and sequence.
The host injects `RechargeRequestClient`; config/detail/lists are reads,
create/prepay are explicit user commands. `/recharge` is contributed by the PC
package, with terminal `4`. Do not create a second client/session or call prepay
on mount, retry or a status refresh. Use server config channels for selection.

Web contribution supplies `/app-setting/pay`, `/finance/recharge` and
`/finance/refund`. Their existing local `api.ts` helpers and pages are application
implementation entries, not new public npm component exports. Change the host
layout around these consumers without bypassing their route/command permissions.

## Failure and current verification boundary

`RECHARGE_DISABLED`, amount limits, `RECHARGE_CHANNEL_UNAVAILABLE`,
`RECHARGE_PAY_WAY_DISABLED`, `PAYMENT_CHANNEL_UNAVAILABLE` and
`PAYMENT_WECHAT_IDENTITY_REQUIRED` remain explicit failures from the existing
service. Keep input and display the error; refresh authorized configuration before
a new user choice. After an unknown create/prepay result, inspect existing order
state through the original application entry instead of automatically creating
another financial operation. A provider return page is not a settlement receipt.

Real OAuth/provider credentials, merchant grants, externally reachable callbacks,
payment/refund and account credit are NOT_RUN by these examples. No mock provider,
external request or real-funds operation is authorized or performed by this guide.
