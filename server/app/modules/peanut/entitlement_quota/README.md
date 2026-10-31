# Entitlement Quota

`peanut.entitlement-quota` 是可选的租户内商业额度服务。正式入口为
`Application\EntitlementQuotaService`；公开类型以本目录 `module.json` 的
`contracts.exports` 为准。不提供 HTTP 路由、SDK、菜单或配额管理页面，普通业务无需依赖它。

## 接入前提

消费模块显式声明对此模块的依赖，并在宿主组合根绑定自己的
`Contract\EntitlementMeterRegistry` 和 `Contract\EntitlementPolicyProvider` 实现。
本模块 `ModuleProvider` 不提供默认计量或默认放行策略；Composer 安装、模块发现、迁移、
租户启用和人员业务授权是不同步骤。缺少业务实现时不能直接解析完整服务。

- `find(meterKey, targetType)` 返回 `EntitlementMeter` 或 `null`。计量类型、目标类型、
  单位必须匹配实际业务，未知计量返回 `null`。
- `snapshot(context, meter, targetKey, evaluatedAt)` 返回 `EntitlementGrantSnapshot`
  或 `null`。提供者负责业务授权与策略选择，不能信任请求提交的额度或租户。
- 快照包含 grant/revision 标识、计量和单位、正整数上限、周期、生效起止及预留 TTL。
  周期为 `lifetime`、`utc_day` 或 `utc_month`；TTL 为 30–86400 秒。
  时间统一 UTC，窗口限制在策略生效范围内；同一策略修订不得换内容。

依赖使用框架现有容器、时钟、幂等、审计和数据库能力。调用者不得直接使用本模块的
Model、Persistence 或私表作为跨模块写入口，也不得自行创建默认租户或绕过对象授权。

## 操作与结果

所有方法首先接收由正式业务授权入口建立的 `AuthorizedOperationContext`，随后是
`meterKey`、`targetType`、`targetKey`。上下文必须含有效租户、人员、账号、请求 ID、
业务资源和操作，以及恰好一个匹配的 `primary` 目标及目标 ID。
服务核对上下文与目标的一致性，不替调用者授权业务操作。

| 方法 | 其余输入 | 返回值 | 行为 |
| --- | --- | --- | --- |
| `check` | 正整数 `amount` | `EntitlementQuotaDecision` | 只读判断；容量不足为 `allowed=false`，不占额度 |
| `usage` | 无 | `EntitlementQuotaUsage` | 返回已提交、有效预留、上限、剩余、窗口与策略摘要 |
| `reserve` | `amount, idempotencyKey` | `EntitlementQuotaReceipt` | 事务内检查容量并创建 pending 预留 |
| `commit` | `reservationKey, idempotencyKey` | `EntitlementQuotaReceipt` | 将未过期 pending 预留转为 committed |
| `release` | `reservationKey, idempotencyKey` | `EntitlementQuotaReceipt` | 将未过期 pending 预留转为 released |

结果对象提供 `toArray()`，使用 snake_case 字段和 UTC 时间字符串；数量为整数，
单位由计量合同定义。Receipt 还含预留 key、数量、状态及预留/到期/结算时间。
不要将 `check` 的结果当成容量保证，实际占用必须经 `reserve`。

预留 TTL 不超过窗口结束；用量查询不计已过期的 pending。结算已到期预留会将其
转为 expired 并返回冲突。已终结预留不能换新幂等键再次结算；同一请求重试保留原键，
独立业务动作使用不同键。命令内使用现有 ThinkPHP 事务、行锁及租户/人员/操作维度幂等，
幂等语义还绑定原业务授权资源、操作、授权依据和目标。结算通过原预留读取政策，
不会因当前策略变化而重新计价。外部操作、通知和付款不在数据库事务的撤销范围内，
消费业务自行负责其成功后的 commit、取消后的 release 及失败恢复。

## 最小调用例

以下仅为接入片段，假定服务依赖已绑定、租户已启用且 `$context` 已完成该对象的业务授权。
计量和目标直接取业务入口的可信值；片段不创建策略、HTTP 入口或真实业务消费者。

```php
use PeanutAdmin\Modules\EntitlementQuota\Application\EntitlementQuotaService;
use PeanutAdmin\Modules\EntitlementQuota\Application\EntitlementQuotaUsage;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

function quotaUsage(
    EntitlementQuotaService $quota,
    AuthorizedOperationContext $context,
    string $meterKey,
    string $targetType,
    string $targetKey,
): EntitlementQuotaUsage {
    return $quota->usage($context, $meterKey, $targetType, $targetKey);
}
```

写入时使用同一正式入口调用
`reserve($context, $meterKey, $targetType, $targetKey, $amount, $reserveKey)`，
持有返回的 `reservationKey`，再在业务完成或取消的入口分别调用 `commit` 或 `release`。
结算入口仍须对相同目标建立有效授权上下文；不能由浏览器传入可信上下文。

## 错误与当前采用范围

`EntitlementQuotaException` 提供 `errorCode`、`httpStatus` 和消息：INVALID 为 422，
NOT_FOUND 为 404，DENIED 为 403，EXCEEDED/CONFLICT 为 409，
PROVIDER_UNAVAILABLE 为 503，INTEGRITY_FAILURE/INTERNAL_ERROR 为 500；完整错误码均以
`ENTITLEMENT_QUOTA_` 开头。原生幂等错误可作为 `ApiException` 透传。
入口沿应用统一错误响应处理，不能把策略故障、完整性异常或数据库故障降为零用量/允许。

2026-10-31 核对：服务、计量/策略合同和持久化已有实现；在本应用生产源码中未发现
本模块以外的服务调用者或计量/策略实现。服务内部是两个 provider 合同的实际调用者；
当前没有可据实列出的跨模块生产消费者。缺少消费业务和策略绑定属于尚未采用，
不以示例冒充应用采用，也不为展示能力给文件、任务或支付强加配额。
