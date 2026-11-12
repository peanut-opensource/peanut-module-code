# 官方模块增量发行

官方作者仓为 `peanut-opensource/peanut-module-code`。只有 `peanut.*` 纳入官方目录，应用自有 `custom.*` 不参与官方发行。模块源码、模块版本、目录 Tag、宿主脚手架和 CLI 版本分别管理；不因宿主升级就给所有模块增加版本号。

## 单一入口及执行顺序

现有 `.github/workflows/module-release.yml` 是正式流程：固定来源 → 自动比较公开目录 → 冻结变更计划 → 按需准备工具 → 只打包变化模块 → 验证受影响依赖 → 审计历史不可变身份 → 保存原件 → 经明确批准发布原件。它不安装或升级业务应用，也不声称已经完成数据库/E2E 验收。

普通构建不必先人工运行另一份检查脚本。工作流在 PHP/Composer 准备之前自动调用同一原生生产者进行比较；`plan_only=true` 只生成计划。零变化返回 `no_module_release_needed`，依赖安装、模块打包、Tag 和发布均跳过。计划中的目录 Tag 不是模块版本。

本地只读计划仅需 Python、Git 和已授权的官方目录读取，不要求 PHP、Node、数据库或开发机资源登记：

```sh
python3 scripts/build-module-release-index --plan-only \
  --source-root "$PWD" --source-commit "$(git rev-parse HEAD)" \
  --channel prerelease --report "$PWD/.local/module-release-plan.json"
```

默认从指定渠道的公开目录自动发现一次基线，也可提供 `--baseline-release vX.Y.Z-rc.N`。离线基线必须同时提供 `--baseline-index` 与从独立可信来源取得的 `--baseline-sha256`；本地重新计算一个值不能证明来源可信。共享的 `module-release-catalog.py` 负责目录格式、官方来源、原始附件和不可变身份验证，不是第二套安装器。

## 比较与版本规则

生产者对固定 Git 提交中的模块后端及声明的全部前端贡献根，按排序后的路径、模式和 blob 身份生成 `source_sha256`。不含绝对工作目录和时间，因此同一源码换目录不会被误判变更。链接、不安全路径、脏源码及来源漂移拒绝；历史应用 baseline 文件保留其既有例外，不进入模块载荷。

计划区分 `unchanged`、`new`、`changed`、`version_bump_required`、移除待审及降级。已经发布的同一 `key@version` 不允许源码、内容或元数据变化。只提高目录 Tag 不会绕过这一规则。版本比较按严格 SemVer，不按文件时间或 Release 的创建顺序。

变化模块及其反向依赖闭包进入验证范围。未变化的依赖模块只在必要时下载原包并执行原生 check，不重打包。契约、Edition 和依赖约束仍由原 `PackageCheck`、模块边界检查及原生装配负责，不在发行器另写业务依赖求解器。

## v2 目录与两种摘要

新目录使用 `schema_version=2` / `peanut.module-release-index.v2`，保留 v1 的读取与不可变身份规则。每条记录仍包含 key、独立版本、资产名、包 SHA-256、大小、许可、Edition、Kernel 约束和依赖，并增加：

| 字段 | 含义 |
| --- | --- |
| `source_sha256` | 固定源码的规范化模块输入指纹，用于决定是否需要打包 |
| `artifact_tag` | 该 TAR 原件实际所在的不可变官方 Release，不是当前目录 Tag 的别名 |
| `payload_sha256` | 可用时记录 TAR 内规范化文件内容清单摘要 |
| `sha256` | 实际 TAR 的整包字节摘要，用于下载校验与不可变版本对账 |

变化模块只生产一次确定性 TAR，并保留原 `META-INF/files.sha256`。未变化模块直接引用其原始不可变 Release，当前 Release 只上传新 TAR 与完整新目录，不上传旧包的副本。引用必须直接指向拥有原件的 Release；不接受引用链、可变 Release、丢失附件或目录与原件元数据不一致。

目录自身和 TAR 都必须匹配 GitHub Release API 的独立 `assets[].digest`、官方 URL 和长度。目录里自述的 SHA 不是唯一信任依据。原有稳定/RC opt-in、单模块 TAR、普通文件类型、路径隔离、大小上限、秘密文件拒绝及 Apache-2.0 许可检查保持不变；重新压缩或改写旧包来“对齐摘要”被禁止。

v2 是消费者协议变化：CLI、脚手架原生 source-adoption 和开发者后台均须采用支持 v2 的实现。正式发布 v2 目录前应先交付相应消费者版本；未发行 dev 源码不能写成已安装的公共 CLI 能力。旧 v1 已发布目录和原件无需重发。

## 固定计划执行与续接

实际构建使用已保存的计划和摘要，不再查询“最新基线”：

```sh
python3 scripts/build-module-release-index \
  --source-root "$PWD" --source-commit FULL_40_HEX_SOURCE \
  --tag vX.Y.Z-rc.N --channel prerelease --output /absolute/module-release \
  --plan-file /absolute/module-release-plan.json --plan-sha256 EXACT_PLAN_SHA256
```

同一原目录已知失败时增加 `--resume`。状态绑定源码、计划、生产者、实际工具/依赖身份及输出摘要。已完成 pack 但 check 失败时保留 TAR，再次执行只补 check；环境变化只重新验证，不能把旧环境 PASS 当成新环境 PASS。已完成包损坏、同版本不同字节、未知在途 pack 或并发 writer 均停止并保留原现场，不重开任务或静默覆盖。

GitHub 工作流使用 `resume_run_id` 恢复先前已结束 run 的固定计划与部分原件；重试原 run 时按原 attempt 查找。不能恢复仍在执行或来源不一致的 run。每次保留的 artifact 名包含 run ID 和 attempt，避免错误拼接。默认生产流程不再安装未使用的全局 CLI/Node。

## 正式发布与故障恢复

`scripts/publish-module-release` 是唯一发布器，`--mode audit` 只审计，正式 `--mode publish` 仅允许注册的 main workflow、原成功构建 run、原完整目录摘要和仓库不可变发布批准。受保护 PR 合并可形成 M；脚本按实际 PR、来源 S 和同树关系核验，自动创建或复用指向 S 的注释 Tag，不移动错误或已发布 Tag。

发布始终消费原 CI 资产。已存在草稿逐个核对已有资产，只上传缺失原件；已有正式 Release 完全一致则只回读验证，不重复发布。外部状态未知先对账原 run、draft、Tag，不默认重发。最终检查完整附件集合、官方 URL、大小、GitHub API digest、实际下载字节和 `immutable=true`。

输出明确记录 packaged/reused/checked 模块、计划与目录摘要、源码以及发布状态；源码检查/打包成功不能冒充安装、权限、租户隔离或业务验证。模块作者应用升级和后续 ST/MT 消费者验收是独立的下一阶段。
