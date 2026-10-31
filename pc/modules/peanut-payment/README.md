# PC 充值能力

`@peanut-admin/peanut-payment-pc` 的默认入口登记 `/recharge` 页面，`@peanut-admin/peanut-payment-pc/api` 导出该页面实际使用的无状态 SDK。宿主提供符合 `RechargeRequestClient` 的请求对象，SDK 不保存会话、租户或组件状态。

```ts
import {
  getRechargeConfig,
  createRechargeOrder,
  getRechargeDetail,
  prepayRecharge,
} from '@peanut-admin/peanut-payment-pc/api';

const request = useRequest();
const config = await getRechargeConfig(request);
// 用户输入金额并明确选择 config.channels 中的一项后创建订单。
const order = await createRechargeOrder(request, amount);
// 仅在用户明确发起支付时调用：此操作会请求真实渠道。
const prepared = await prepayRecharge(request, order.id, selectedPayWay);
// 渠道返回页面不代表入账；主动刷新服务端订单状态。
const latest = await getRechargeDetail(request, order.id);
```

真实消费者是本目录 `pages/recharge.vue`，不需要另建页面或支付服务。PC 固定终端 `4`；其他终端使用各自客户端入口，不能借 PC SDK 伪造终端。

| 操作 | 输入 | 输出 |
| --- | --- | --- |
| `getRechargeConfig` | 请求客户端 | 启用状态、最低金额、余额、可用渠道 |
| `createRechargeOrder` | 请求客户端、十进制金额字符串 | 服务端充值订单；初始渠道来自默认配置 |
| `prepayRecharge` | 请求客户端、订单 ID、用户所选渠道 | 更新后的订单与渠道预支付参数 |
| `getRechargeDetail` | 请求客户端、订单 ID | 当前订单、支付状态与金额 |
| `getRechargeLists` | 请求客户端、页码和页大小（默认 1/10） | `lists`、`count`、`pageNo`、`pageSize` |

身份、租户及错误由宿主 `useRequest` 和服务端正式入口处理，SDK 不补默认身份、不吞错误、不自动重试。服务端 `RechargeQueries` / `RechargeCommands` 由 Payment 的 `ModuleProvider` 绑定，应用 `app/api/services/RechargeApplicationService.php` 是其真实消费者；跨模块不能直接使用 Payment 私有 Model。详情与列表限本人订单，预支付仅接受本人未支付订单并校验当前终端渠道及租户授权；最低/最高金额由服务端执行。支付入账只接受经验证渠道回调，浏览器不设置成功状态。

页面保留用户渠道选择供预支付采用；查看既有订单时优先采用订单当前可用渠道。用户点击“刷新支付结果”只读取订单、配置余额和当前列表，已支付后移除旧支付指引，无轮询与自动支付。组件销毁后无 SDK 定时器或持久状态需要释放。

外部支付凭据、商户授权、回调可达性和真实入账仍须在获准环境确认。编译或页面展示不证明实际资金链路已验证。
