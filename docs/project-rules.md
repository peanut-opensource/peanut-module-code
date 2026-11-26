# Peanut Module Code 应用自有开发规则

本应用是可复用业务 Module 的默认开发环境。本文件由应用自行维护，与可升级的脚手架通用 Agent 规则分离。

- **业务模块**：每个独立 Module 拥有其业务数据、迁移、服务、接口、权限、菜单、客户端页面及生命周期。业务开发优先在 `server/app/modules/<vendor>/<module>` 完成，而不是把业务服务留在宿主 `app/common`。
- **依赖和通用技术**：先用 ThinkPHP/ThinkORM、PHP Core、Web Core 和现有模块公共合同。模块间仅通过显式 `contracts.exports` 或允许的稳定 DTO、公开服务调用；不复制宿主或其他模块的内部实现。
- **宿主边界**：仅在证明缺少启动、装配、安装、升级或原生适配时变更脚手架。技术共用能力进入合适 Core，完整可复用业务能力进入 Module，避免双套框架与无效兼容。
- **源码与发布**：保护本应用所有现有 WIP。源码开发、依赖包升级、模块正式发布、下游安装/运行产物和真实业务验收须分开确认；未授权时不自动发行或升级消费者。
- **官方模块交付顺序**：本仓先采用脚手架，完成并验证影响范围内的官方模块，再固定 `dev` 源码与原生锁，生成候选归档并核对版本、SHA-256 和推送来源。交付该清单后，下游才升级脚手架和官方模块，再改造自有业务。上游确认退役的合同随下游采用移除，现行 API 沿原合同保留。


## 模块开发实例持续复用基线（2026-11-26 实测）

- **唯一实例和数据：** 现有 `peanut-module-code` 的 `dev` 主目录；Scaffold MT `5.1.18-rc.30`；Module 后端通过已登记的 `127.0.0.1:5020` 服务启动，使用 Project 资源登记中的独立开发 schema `peanut_module_development`（mac-14 原有 MySQL 8.4 服务）。不得为重新验收新建实例、复制业务库或覆盖原安装凭据。
- **已核作者／安装／锁三方：** `server/app/modules/<vendor>/<module>/module.json` 共 **24** 个、`modules.lock` **24** 个、当前数据库 `pa_module_installation` **24 条 active**，缺项 **0**、停用 **0**。本次维护前 23 个完全一致，唯一差异是应用本地 `custom.ai-access` 的 rc.3 源码摘要变化；经原生 `module pack --prepare-candidate`、锁及 API 生成、最小已安装依赖闭包的 `module reconcile --selected-installed`，现已升级为 **1.0.1-rc.4**。最终 `peanut module reconcile --release-locked --plan-only` 对 **24/24 全部报告 `planned_unchanged`**；0 个待执行迁移、0 个迁移阻断（变更前现有迁移账本读取为 53 条）。
- **固定本地扩展候选：** `release/modules/custom.ai-access-1.0.1-rc.4.tar`，SHA256 `c6d66cf62fc19847f07104ed69e0af44a0f34a03192631237308023c3e728b93`，已通过原生 `module install --dry-run` 的 `source-preflight-ready`；仅是本实例本地包，不属于 `peanut.*` 官方模块发行，也不公开发行或改写旧 rc.3 TAR。
- **验收范围：** 24 个 Module 逐一运行原生 `peanut module check` 均为 READY；API 合同 `486/486 complete`、0 partial、0 route-only；正式 `scripts/check-openapi` 的 19 个派生产物一致。以上属于作者／装配和源码合同验证，不能代替所有业务的受权 HTTP、租户隔离、性能或外部服务验收。
- **持续复用而非反复首装：** 在本应用根运行 `peanut module lock --check` 和 `peanut module reconcile --release-locked --plan-only`；一致的已安装模块不重装。日后仅对真正新增模块执行原生 install，对变更模块按真实依赖闭包和可恢复计划执行原生 reconcile；修改源码、模块锁或安装身份后按现有规范刷新编译及 API 派生结果。切勿直接写安装账本或伪造版本。
- **安装与租户授权严格分离：** 安装为 active 只说明此实例部署且启用模块运行代码；租户可见菜单和 API 权限仍以有效套餐许可证、租户入口、成员角色与 HTTP 原生授权为准。**24/24 安装成功不等于所有租户拥有 24 个菜单，也不等于 24 个模块全部通过真实业务验收。** 本次未调整任何许可证、套餐或成员权限。
- **恢复与验证边界：** 本次源码／依赖锁和专库压缩备份、原生升级前后只读计划保存在 `.local/recovery/module-baseline-20261126/`（受限权限、禁止提交和输出凭据）；5020 服务由原登记 Service Manager 维护。业务验收继续复用当前实例、现有合成数据和受控身份。依赖准备采用离线设置，Composer 无法完整拉取远端安全公告，**不能据此声称联网依赖审计通过**。具体本次执行过程与未完成项目以 Project 唯一工业台账为准。
