# 原生后台任务的可撤销固定来源

## 已实现范围

本次权威开发输入为 `fac20253305ab79fcdd1f51cbb59651a9d357de6`，任务为007 / SOURCE-01。固定来源授权由Document拥有，任务执行身份由原生Task提供，文件可读性由File检查，首条实际消费链接入独立AI模块。

| 模块 | 版本 | 本次公开变化 |
| --- | --- | --- |
| File | 1.0.44 | FileDocumentSourceChecks，仅核当前原件下载权、Document认领快照与实际摘要，不返回文件或授权 |
| Task | 1.0.30 | TaskSourceAuthorizations、SourceTaskBinding，绑定真实排队任务与当前原生执行身份 |
| Document | 1.0.17 | DocumentSourceGrants及持久授权/撤销/原请求回执表 |
| AI | 1.2.2 | 公开创建、原子排队签发、执行前来源检查、明确重试、准备取消和终止恢复 |

File不依赖Task。Document最低依赖File1.0.44，Task1.0.30是原生optional_dependencies，不加入Document的tenant.requires或Composer强制依赖。AI依赖Task1.0.30、Document1.0.17和File1.0.44。已提交无文件引用正文和固定File来源可用；包含File引用的正文仍明确拒绝，不能静默略过附件。

## 公开提交与执行链

消费方先检查来源资格，避免不支持的来源留下业务准备记录或占用额度。取得固定VersionSnapshot后，在同一Admin事务中发布真实成员任务、保存业务到job的绑定，再签发Document授权。授权绑定租户、原始成员/会话、当前授权修订、业务owner及其状态、Document授权修订、固定版本/摘要、消费模块/资源/操作/handler、实际job和最多24小时的期限。

签发不能由任意job key或自行构造的上下文代替。兑换只接受本运行时真实LocalWorker的JobExecution和同一System执行根；原生Task再次验证原签名授权、当前成员会话、租户及租约。Document重验owner/来源/撤销/期限，File来源还要重验独立原件下载权、已认领固定快照和实际存储字节。后续原生检查点与发送前最终授权栅栏继续重验。

原签发成员失去来源可见性后仍能撤销自己的授权；其他成员需要当前owner编辑权限。Document新表只关联自己的Document表。原生作者检查曾发现新迁移引用Identity私表的外键；r2仅删除该跨模块关联，保留Document自己的复合外键和原请求唯一键，成员有效性沿Identity公开会话合同检查。没有改检查例外、私表权限或安装账本。

## AI准备、失败与取消

真实AiTasks第一事务只在资格检查通过后保存任务和预留额度；第二事务把原生任务发布、job绑定、来源授权及queued状态一起提交。签发失败回滚第二事务，保留同一preparing任务与一次额度预留，原UUID可继续恢复。

未排队准备可以通过公开taskAction取消：使用真实业务version作CAS，不伪造job_revision。已有原生任务继续使用其真实job_revision；API oneOf和UI提交辅助函数均区分这两种请求，原请求存储与恢复内容保持固定。原有use/manage权限继续执行。

来源在兑换前被撤销时，AI记录未发送并释放相应预留，明确重试才重新授权和预留。来源在最后发送栅栏被拒绝时，没有provider调用；对dead但未发送任务的取消，仅捕获原生TASK_STATE_CONFLICT，并在现有事务中调用TaskJobService::detailForRecovery。该公开接口锁定终态行，要求真实revision、completed_at、无lease字段和无running attempt；恢复还限定dead。其它权限、存储和程序异常原样传播，不以泛捕获掩盖失败。

本地取消不代表远端请求已取消，未知发送结果仍沿既有核对/恢复规则处理，不自动重发。来源授权也不替代业务审核、候选采纳、实际发布或付款。

## 实际主控验证

- 新Document/Task/File/AI公开消费回归：**22 tests / 332 assertions，exit0**。
- 既有File清理回执定向回归：**6 tests / 50 assertions，exit0**。
- 实际AI API payload、OriginalRequestStore和Workspace编译定向测试：**8 tests / 8 pass / 0 fail / 0 skip**。
- 四模块原生作者检查、23模块锁及API生成通过，**17项生成物一致性**通过。
- 本次23个PHP源及13个JSON/TS/Vue/CJS文件按现有配置完成格式检查；无全仓重排。
- 完整 `pnpm --dir web build` 含vue-tsc和Vite，exit0；2891个模块转换，Vite阶段26.81秒，整条构建61.5秒。
- 正常Composer更新仅四个目标包；r2通过正常reinstall同步Document，无手改vendor。既有File1.0.43归档摘要保持不变。

PHP主命令：

```sh
python3 scripts/project-env exec -- php server/vendor/bin/phpunit --no-configuration server/tests/Modules/Peanut/Document/DocumentTaskSourceTest.php
python3 scripts/project-env exec -- php server/vendor/bin/phpunit --no-configuration server/tests/Modules/Peanut/File/SnapshotRecoveryReceiptTest.php
python3 scripts/project-env exec -- node web/tests/custom-ai-assistant-source-task-action.test.cjs
python3 scripts/project-env exec -- pnpm --dir web build
```

任务/Document/File/AI领域实现与原生LocalWorker真实执行；身份与机构许可、部分策略、密钥和提供方响应是受控边界，数据库使用SQLite及明确方言适配，文件检查使用实际合成本地字节。提供方地址为受控invalid域，没有真实外发。终态锁的调用与拒绝条件实际执行，但没有将SQLite结果说成物理MySQL并发资格。迁移在真实媒体实例上的执行、真实IAM/HTTP/浏览器、物理无Task的安装组合以及媒体消费者采用仍另行验收。

## 原生制品与证据

| 制品 | SHA-256 | 主控逐文件比对 |
| --- | --- | ---: |
| peanut.file-1.0.44.tar | dba27e44674c237afe71a294e3d146e911a244337b7b24c1457554dad703e1cf | 92 |
| peanut.task-1.0.30.tar | 3b37963f8437ee4675ebc8046b41b8f95c6e8b45aeb183f7b2b1989fcec40335 | 75 |
| peanut.document-1.0.17.tar | f9ebe4f5c0c07449b7b237f92c5199f55cbdf4ef354fc8692e487e3f16146591 | 58 |
| custom.ai-assistant-1.2.2.tar | 6e79fac7b8f0f833acdc11dfef2ed7bcea028afe0c03f76c12ea4e8507c9f1c7 | 53 |

原生模块锁SHA-256为 `732d3e3461d9e900127ace97ef99507ac013572d973a8e2ba023f2be82fd203f`。归档内容逐项与最终权威源码相同，包、秘密、安装依赖和大量日志不提交Git。

私有回执和原始输出在 `.local/media-full-cycle-20261107/`：原007-result/receipt、r1独立修订、000-007-metadata-integration.json、000-007-native-integration-r2.json、000-007-final-build-pack.json及对应日志。最初原生lock跨模块FK失败、夹具异常类型断言失败与后续正常修复均保留。媒体应用必须沿原生模块安装/升级采用，不复制本仓公共源码到下游。
