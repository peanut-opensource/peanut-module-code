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


## 2026-11-26 · 主入口开发侧栏验收纠偏（安装24/24不等于菜单通过）

- **阶段结果：** 原“24个模块active / lock READY”只表示安装与目录来源验收。**主开发入口42个Admin模块页面的实际登录、权限与浏览器展示仍为NOT_RUN，不得标记全模块业务基线PASS。** 当前真实源为Scaffold MT `5.1.18-rc.32`、Module dev `9d550aeb`、原服务`127.0.0.1:5020`。
- **主入口权属：** `http://127.0.0.1:5020/admin/`的`admin-web`绑定`tenant_id=1/code=default`。主租户有效`application-default` plan1无到期，选中全部24模块。QA2是别的host和过期许可，不得当主入口。
- **菜单分层清点：** 15个Module作者提供**42个tenant Admin page＋1个group**，另9个Module无独立Admin菜单但提供backend/Platform/ContentTools等能力；Core菜单12个（6个页面/6个组）。数据库`pa_system_menu`中所有42个Module page均active、show=1、disabled=0，对应permission和plan1 ceiling均有效。当前Vite静态路由递归77条，42/42 Module page路径均命中，缺失父级0；由真实菜单源合成“已授权服务器菜单”通过前端现行`mapServerMenu`处理，**42/42可投影**，不是有效会话真实菜单响应。
- **缺失层：** 当前主租户只有一个active`core.tenant-owner`，该角色显式Module授权0。Identity保护固定内置Owner只自动拥有Core的`CorePermissionCatalog::TENANT`，不能通过`role/permissions`直接修改内置Owner；业务Module必须先建立普通`application.admin.*`角色，赋予**有效套餐内可分配**权限，再给当前主开发成员追加该普通角色且**保留Owner原角色**。因此主入口“侧栏只有基础部分”的可证根因在租户成员角色的模块授权层，不在安装/manifest/同步/静态路由。
- **身份现况：** 主Owner account2/credential2均active未锁，现存`server/private/credentials/initial-administrators.json#admin`账号标识和密码哈希均**不匹配**，禁止再当当前开发凭据；现有私有凭据没有当前Owner可验证密码。原生`identity:credential-recover`初始模式缺已批准initial-binding／plan，current-existing-shared要求Platform与Tenant共享账号，当前独立账号不满足，不得假造凭据和批准文件。平台account1的`server/private/credentials/platform-dev-recovery-20261124-module.json#platform`经离线凭据哈希比较为active+match；本轮尝试独立平台HTTP认证被安全检查执行前拒绝，**不得换工具重试**。
- **桌面正式接管：** 开发已创建`server/private/credentials/module-main-default-owner-20261126.json#admin`（0600）的**待激活**身份，只有desktop通过现有授权Platform session在`/platform/#/owners`发起tenant1的原生Owner邀请并从正式`/adminapi/tenant/owner-invitations/accept`消费token（不暴露token或密码），才会成为可登录账号；原主Owner不能直接重置。然后在主`/admin/app/roles`建立普通“模块开发管理员”角色，按有效plan1选全当前可分配模块权限，并在`/admin/app/members`编辑新Owner的角色数组以**保留`core.tenant-owner`并追加普通角色**（两个Core端点已核guard），重新认证并核`POST /adminapi/user/info`和`POST /adminapi/user/menu` 200＋42条Module page实际可见与代表业务API 200/403。直到取得这个桌面回执都保持`MAIN_ADMIN_MENU_NOT_YET_ACCEPTED`。
- **私有可复核结果：** `.local/recovery/main-dev-sidebar-20261126/`包含当前数据库55菜单、Vite77条路由、42/42合成映射及本阶段状态四份0600 JSON；无原始token、密码、凭据哈希。保持24安装模块、Identity rc.17、原5020服务和开发库不变，不改正式许可证、角色表或G18隔离。


## 2026-11-26 · 主入口与 QA2 双租户后续核查（禁止新 Owner）

**本节优先于上一节“待激活 Owner 邀请”建议。** 用户明确不新增 Owner、不移交所有权、不重试已被工具安全检查拒绝的登录和许可操作。前一轮自建但从未通过原生邀请签发的 `server/private/credentials/module-main-default-owner-20261126.json#admin` 已按精确路径清理，**不存在新账号、邀请和有效登录**；不得再要求桌面使用该已撤回引用。

- **入口不是同租户：** `127.0.0.1:5020/admin/`→绑定 `default/tenant1`；`qa-module-20261120.module.localhost:5020/admin/`→QA2/tenant2。前者plan1无到期，24模块/143套餐权限上限；后者plan3于**2026-11-21 05:14:00 UTC**过期（许可行status仍显示active不等于当前effective），原套餐13模块/20权限上限。QA2已有Owner与两个普通角色、桌面已确认侧栏只有工作台/组织与权限/运维与审计；**过期许可使模块业务权限无效，不能通过创建角色或追加菜单修复QA2**。本轮未修改QA2许可、身份、角色或菜单。
- **默认租户授权层：** 原有account2／member1仍active且未锁；角色只有 `core.tenant-owner`，没有显式模块权限。内置Owner依设计自动取得固定Core权限，但不是“全套已安装业务模块管理员”，不得改内置Owner权限或擅自临时提升。此前核准的42个模块page在正式菜单注册和Vite静态路由完全42/42匹配，`web/src/config/settings.json`明确 `menuFromServer=true`，`POST /adminapi/user/menu`经正常会话加载，前端 `mapServerMenu` 的已授权合成投影42/42；目前**未证实服务器菜单投影源码BUG**。没有真实default Owner令牌，不能模拟本次已获权登录或实际42页面。
- **已有Owner时的原生邀请真实规则：** `TenantOwnerInvitationAdminService::assertOwnerBaseline`对**provisioning**租户拒绝已有Owner候选；对**active**租户反而要求至少一个active Owner，随后若无pending邀请且有Platform `platform.tenant.provision-owner`权限，可以邀请*另一位*Owner。**无需移交已有Owner，技术上可邀请第二Owner，但本轮用户禁止**；不以“必须新增Owner”替代维护现有开发身份。
- **持久正式普通开发角色路径（需先合法登录）：** 现有Tenant1 Owner如持有经验证的有效本人会话，可在 `/app/roles` 经原生 `role/add`创建独立 `application.admin.*`“模块开发管理员”，以 `GET role/permissions/catalog`的当前计划可分配权限列表（并非无条件全系统）和 `PUT role/permissions`携`expected_revision`一次提交；`/app/members`用正式`admin/edit`编辑**现有Owner本人**，原角色 `core.tenant-owner`保留并追加普通角色。上述绑定存`pa_role`、`pa_role_permission`、`pa_member_role`，依现有事务/修订/审计持久生效，不依赖临时QA2套餐、不覆盖模块原生目录或升级源码。完成后以*同一默认Host*重新正常获取实际`POST user/info`＋`POST user/menu`，确认15模块42页、代表API实际200/403，再判PASS。
- **当前最小真正缺项：** 在本Module私有凭据中未找到**已经核验可用于默认Owner当前身份**的引用；`initial-administrators.json#admin`旧邮箱/密码均不匹配。当前原生`identity:credential-recover`的initial需要缺失的原始grant binding/approved plan，current-existing-shared需要同一平台与租户账号，而当前默认Tenant Owner account2≠Platform operator account1。未有已经批准的现有Owner专用维护方案。进一步受限身份交叉读取被工具安全检查拦截，本轮未绕路重试。故最小外部前置只能是**桌面提供一个仍有效的现有default Owner授权会话**；如确无会话，须另行明确批准**针对现有Owner account2/tenant1的原生、可审计的凭据恢复合同**，先设计与验收恢复门槛，而不是伪造初始绑定、直接UPDATE密码或签发新Owner。Platform独立会话不能跨租户代签角色。
- **最终界限：** 默认42应有页面与QA2业务页面当前实有均未新增浏览器通过证据；本轮无源代码BUG确证、无业务角色变动、无新开发账号、无许可证变动，`MAIN_DEFAULT_ADMIN_READY=BLOCK`；24/24安装仅`INSTALL_PASS`。禁止带病启动四业务应用升级。


## 2026-11-26 · tenant1有效第二Owner后的技术模块验收与rc.35采用（覆盖先前身份阻断结论）

- **身份变化由桌面正式完成：** default/tenant1已通过原生Platform邀请激活第二个本地开发Owner，保留原Owner，绑定普通开发角色ID11；当前有效受控引用为`server/private/credentials/module-main-default-owner-20261126.json#admin`（0600，禁止打印）；此前“不可登录／不得新Owner”旧检查点不再描述当前账号状态。本Runner已核文件存在且受保护、原5020运行，**但通过本工具正常tenant1登录被OpenAI安全检查执行前拦截，未发出HTTP请求，不能视为用户账号仍阻断**。连接浏览器工具也明确需要原始扩展context，当前只见本机Peanut Service Manager，因此本Runner不具备桌面现有IAB会话控制能力，不能虚报任一认证后业务读写。
- **桌面提供的页面证据仅导航：**`.local/recovery/main-dev-sidebar-20261126/desktop-official-page-entries.json` 0600，记录32个路由均有可见内容，无导航ERROR。其中24个属于本轮技术模块、8个属于已排除业务模块；剩余4个`custom.ai-access` Admin技术页未在此文件记录（并非已证缺页面）。**不等于列表200、写入/回滚、租户权限403或跨模块流程PASS。**
- **角色11真实授权与套餐区别：** 当前DB role11在tenant1的`pa_role_permission`实际**95**条＝**92项技术`peanut.*`＋3项`custom.ai-access`**；套餐plan1列有135项全部`peanut.*`上限，其中业务`peanut.article`21、`peanut.member`12、`peanut.payment`10共43项**未给角色11**，另`custom.ai-assistant`业务授权0；角色11对四排除业务模块和Platform/Core显式越权均为0。技术模块28个Admin页面所需菜单权限**28/28均已授**。role11风险分布：normal43、sensitive34、critical18；不代用户直接撤权。私有逐键证据`.local/recovery/main-dev-sidebar-20261126/role11-technical-scope-review-20261126.json`和`role11-minimum-privilege-proposal-20261126.json`列全部18 critical+34 sensitive撤权复核候选，需桌面确认真实必要权限和角色revision后经正式角色保存收敛。
- **Scaffold原生升级失效保护：** Code`dev@57d8d6e21`修`ModuleReconcile`在获取Module生命周期写入器之前强校验`GeneratedApiCatalog`当前源锁，过期/损坏/缺失时`MODULE_API_CATALOG_STALE`**fail-fast**并明确要求重新运行原生generator，不再像先前Workflow升rc9一样先写DB再把Module标记failed。Code源头隔离测试7断言、ModuleReleaseSelection12cases/24断言PASS，未在正常成功请求上自动重写catalog。不可变本地Scaffold`5.1.18-rc.35`ST/MT完成，MT SHA256`b2e94f827c86fe8f89b49e2e6b6ca448cff93f426654d89345fd21ffebd54cf7`、source snapshot`ec63f83d2c0f3ecbaec7552bf03f8531771420ac33b85cbc9aeb0e18d316987e`，local-only。当前Module原生升级rc34→rc35为1005项2replace/1002preserve/1保留自有元数据，plan SHA`sha256:8e699ed0cab0f90e943fc27480746092dd7cb5d222a2f18fb138db1097f09ff6` apply＋verifyPASS，生成式API更新后486/486 contract＋24/24 reconcile unchanged，PHP原生guard在真实Module源码上执行PASS。没有改24模块、Core或前端Web源码，不额外重编未变Web bundle。
- **真实5020与交接：** 同登记Service Manager精确停启，原服务目前running；无会话7/7 HTTP符合200/401和静态JS/CSS实际字节。已生成`.local/recovery/nonbusiness-module-rc35-20261126/desktop-technical-auth-handoff-20.json`（20模块按实际生成API Catalog抓取真实GET/写入候选、24/28页面现有IAB导航、精确待验，绝非执行成功），原独立Code/Module合成测试与20/20作者/Composer逐文件镜像证据复用。**只有用户桌面已连接的正常IAB能对这20模块逐项进行新的认证后读写/撤销/权限拒绝验证**；当前工具无法进入其上下文，已经确认为工具层阻断，不需用户重建Owner、不创建租户、不移动凭据。
