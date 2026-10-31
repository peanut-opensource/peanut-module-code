# 制品修订接入

`peanut.artifact-revision` 保存租户业务对象的不可变修订信封，提供创建、封存、快照读取和分支关系比较。它没有独立业务页面、HTTP 路由或前端包；页面、对象权限、正文存储与正文差异展示由业务模块负责。不要为使用修订而直接读写本模块私表或依赖 Persistence / Model 实现。

## 正式入口与消费者

以 `module.json` 的 `contracts.exports` 为公开面，通过应用容器注入：

| 入口 | 输入 | 输出 |
| --- | --- | --- |
| `Contract\ArtifactRevisionQuery::snapshot` | 已授权租户 ID、制品类型、业务键、修订键；可选 `forUpdate` | 已封存的 `Contract\Dto\ArtifactRevisionSnapshot`；不存在或尚未封存返回 `null` |
| `Application\ArtifactRevisionService::createRevision` | 已授权操作上下文、制品类型/键、可选父修订键、预期制品版本、幂等键 | `Application\ArtifactRevisionReceipt`，状态 `pending` |
| `Application\ArtifactRevisionService::finalizeRevision` | 同一对象上下文、修订键、预期制品/修订版本、载荷 schema 键/版本、载荷引用与 SHA256、可选附件清单 SHA256、幂等键 | `ArtifactRevisionReceipt`，状态 `finalized` 及信封 SHA256 |
| `Application\ArtifactRevisionService::compare` | 同一对象上下文、左右两个已封存修订键 | `Application\ArtifactRevisionComparison`；关系为 `same`、`ancestor`、`descendant` 或 `diverged` |

上述命名空间均相对于 `PeanutAdmin\Modules\ArtifactRevision`。比较结果以左修订相对右修订描述关系，属于谱系比较，不读取正文、不生成文本 diff。

当前真实源码消费者为 Workflow 的 `Integration\ArtifactWorkflowSubjectRevisionResolver`：先核业务对象上下文，再通过 `ArtifactRevisionQuery` 读取固定封存快照，向 Workflow 返回 `revision_key` 与 `sha256`。该可选适配器不代表应用已注册具体 Workflow 场景或已有制品数据。创建/封存目前没有普通产品业务消费者；不能把 fixture 或容器解析当作业务已采用证明。

## 最小只读接入

在消费模块声明依赖 `peanut.artifact-revision`，注入 `ArtifactRevisionQuery`。业务入口先完成对象授权，随后在同一次请求中调用：

```php
use PeanutAdmin\Modules\ArtifactRevision\Contract\ArtifactRevisionQuery;

// $context 已由业务入口授权；类型/业务键必须对应其 primary 对象。
// $revisions 是构造器注入的 ArtifactRevisionQuery。
/** @var ArtifactRevisionQuery $revisions */
$snapshot = $revisions->snapshot(
    $context->tenantContext->tenantId,
    $subjectType,
    $subjectKey,
    $expectedRevisionKey,
);
if ($snapshot === null) {
    // 由消费者转换为自己的“固定修订不可用/冲突”语义。
    throw new RuntimeException('The selected finalized revision is unavailable.');
}
$revisionIdentity = ['revision_key' => $snapshot->revisionKey, 'sha256' => $snapshot->sha256];
```

快照还包含 `envelope` 与 `createdByMemberId`，不暴露 ORM 对象。信封含制品/修订/父修订标识、schema、`payload_ref`、载荷和附件摘要；它不含正文。载荷引用的解析、访问控制与内容摘要核验由正文所属业务承担。不得把 `payload_ref` 直接当作公开下载 URL。

## 权限、生命周期与错误

- 查询是已授权业务内部边界，不执行用户权限判断。`tenantId` 必须来自可信上下文，不能使用请求直接传入的租户值；查询始终限定租户、制品类型/键和修订键。模块安装、启用与禁用由宿主正常生命周期处理，不能用直接容器调用绕过业务入口。
- 写入/比较上下文必须具有有效租户、成员、账号、请求标识，且 `resourceKey` 和唯一 primary target 精确对应制品类型与业务键。调用者仍负责先授权相应业务操作。
- 类型为最多 64 字节的小写点/横线分段标识；业务键为最多 128 字节的非空可打印 ASCII；修订键为 `revision_` 加 32 位小写十六进制。SHA256 使用 64 位小写十六进制。
- 首次创建对象时 `expectedArtifactRevision=null`；已有对象必须传当前制品版本。父修订必须属于同一租户、同一制品且已封存。创建回执中的 `artifactRevision` 是后续操作的预期制品版本；`revision` 是该条修订的预期版本，两者不可混用。
- 封存后载荷及信封不可修改；修改正文应创建后继修订。封存传入 schema 键（最多 64 字节标识）、schema 版本（最多 32 字节 ASCII）、载荷引用（最多 512 字节 ASCII）与摘要。本模块固定的是引用和摘要，不负责持久化或清理正文文件。
- 每次写命令使用自己的幂等键；相同操作/键/语义输入重放既有回执，不因网络重试重新创建修订。幂等记录使用一天有效期，不能把它当永久业务去重键。审计、幂等记录及修订写入使用既有数据库事务，未发送外部通知。
- `snapshot(..., forUpdate: true)` 仅用于调用者已有事务内的锁定读取，默认普通只读。快照校验持久化信封完整性；损坏不会伪装成 `null`。
- 查询非法标识抛 `InvalidArgumentException`，损坏信封抛 `UnexpectedValueException`，数据库失败向调用者传播。命令/比较通过 `ArtifactRevisionException` 报告 `ARTIFACT_REVISION_INVALID`（422）、`NOT_FOUND`（404）、`CONFLICT`（409）、`INTEGRITY_FAILURE` / `INTERNAL_ERROR`（500，均带 `ARTIFACT_REVISION_` 前缀）；幂等处理中的请求另有既有 `ApiException` 语义。消费者在能转换业务语义的边界处理错误，不把存储失败改成空快照。

`ArtifactRevisionService::revision` 仍返回模块内部只读记录类型；跨模块固定修订读取应采用已导出的 `ArtifactRevisionQuery` 和快照 DTO，不把内部类型作为新的依赖。本次没有新增列表、写 HTTP 接口或业务场景；应用采用与真实业务验证需由具体制品所有者完成。
