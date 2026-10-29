# 标准业务文档、富文本协同与多格式内容合同（037 → 039 / V1）

- 状态：**实施目标合同，尚未实现、未验收、未发行**；2026-10-12 按 037 已确定产品原则补齐。本文是 Code 公开开发合同的目标文件，须经仓库治理与实际实现验证后才可标为生效。
- 适用：ThinkPHP 8 / PHP 8.3、Vue 3 / TypeScript；`standalone` 与 `multi-tenant`，同一应用内不同业务模块。
- 既有规范：[开发规范](../development/standard.md)、[模块交付](module-development-delivery.md)、[身份权限](identity-organization-access.md)、[租户 Scope](tenant-modules-and-data-scopes.md)、[文件引用](../development/application-conventions.md)、[CLI](cli-and-recipes.md)、[服务绑定](service-bindings.md)。本文不复制文件资源可见性状态机，不引入第二个文件管理器或权限系统。

> 本文中的接口、表、权限、组件名称均为**待实施的目标合同**。现有源代码仍以实际 route、module.json、PHP / TS 类和迁移为准；不得因本文存在而宣称已安装或支持这些新接口。

## 1. 产品边界与拆分

业务记录（如新闻、合同、采购单）是权限和生命周期的主体；文档是该记录的正文/说明内容，不是独立协作平台。

| 负责者 | 负责 | 不负责 |
| --- | --- | --- |
| 业务模块（如 `peanut.article` 或派生 APP 模块） | 主业务对象、负责人/参与人、角色和状态、归档恢复、允许哪些文档槽位、审批/发布时点、可执行操作 | 保存 CRDT/存储文件本体、实现协同 WebSocket |
| `peanut.document`（新增 Code 官方模块） | 标准文档身份、业务归属、逻辑状态、正文权威持久化、不可变业务版本、引用索引、审计与受控公共 Query/Command | 自己再造负责人/成员/角色、决定业务流程 |
| `peanut.content-tools`（新增目标：内容工具／内容编辑与预览） | 统一工具注册、格式与能力选择、ContentEditor/ContentViewer、工具会话与编辑/预览适配器生命周期；具体格式插件各守自己的实现 | 不拥有业务文档版本账本，不另造文件存储，不授予业务或资源权限 |
| `peanut.rich-text`（复用现有模块，调整职责） | Tiptap/ProseMirror 结构、富文本控件、工具栏、查看器、类型化前端交互及正文内容投影 | 充当通用业务对象/角色表、单独拥有新正式文档账本 |
| `peanut.file`（复用） | `file_key`、真实上传/分类/存储/地址解析/访问控制、文件版本/资源管理与资源审计 | 将文档查看权限自动变成资源管理权限 |
| `peanut.collaboration`（新增 Code 的 PHP 应用适配模块） | PHP 的协作准入/有效授权、会话签发/撤销、读写/保存服务接口、协作事件审计 | 用 Node 绕过 PHP 直接查业务表；成为另一套身份系统 |
| `peanut-collaboration`（Code 交付的可选 Node 服务） | Hocuspocus/Yjs 房间、同步、连接与提交持久化请求、健康指标 | 直接连接 Peanut 业务数据库，作为租户或角色真相源 |
| PHP/Web Core | 既有可信执行上下文、Scope/授权原语/客户端通信基础 | 拥有完整文档/富文本/协同业务 |
| Peanut CLI（独立仓） | 编排已发行的应用原生配置、服务启用/升级/诊断；不独立发明部署引擎 | 直接保存文档或管理业务授权 |

一个业务对象可以拥有多个文档（正文、补充说明等），**一份正式文档始终只有一个主管业务对象**；不允许两个对象共同修改同一文档。其他对象引用某个文档时，V1 只能建立指向该文档已冻结版本的引用或另存副本，不把引用变成第二个所有者。V1 不对普通用户开放无业务归属的独立文档/草稿中心；历史游离文档可为迁移保留受限制的待认领记录，但不作为新增业务能力。

**依赖规则：**业务模块使用标准文档时依赖 `peanut.document`，文档/文件界面通过 `peanut.content-tools` 选择具体编辑器或预览器；富文本由现有 `peanut.rich-text` 提供适配器，业务附件仍由 `peanut.file` 保管。`DocumentEditor/DocumentViewer` 是文档侧的权限、版本与保存封装，内部委托 `ContentEditor/ContentViewer`，不能另写一套格式选择器；文件侧同理。工具注册由可信应用组合根完成，content-tools 不反向导入具体 provider，provider 不依赖 Document 私表，以免循环。仅启用多人协作才装配 `peanut.collaboration` 和 Node；工作流只消费固定文档版本，不成为必需依赖。

本次以“内容工具”作为上层名称，不将 `peanut.rich-text` 全库替换重命名：旧模块标识、已执行迁移、组件和 API 兼容入口保留；DOCX/XLSX/PPTX/PDF/图片/媒体编辑与查看工具统一在内容工具模块下接入和管理，而不是塞进 File 或 Document。部署容器、工具适配器和独立模块不是一一对应关系。详细包与接口职责见 [内容工具合同](content-surface-and-format-adapters.md)。

## 2. 标准文档模型与归属

### 2.1 文档类型与标识

- 权威 ID：服务端生成 `document_id`（租户空间唯一）。V1 `content_kind` 区分 `rich_text` 与 `file_resource`：前者沿用 `body_type=rich_text` 和 Yjs，后者以受控 `file_key` 指向 Office/PDF/图片/媒体等原件；编辑器和预览器由 [多格式内容适配合同](content-surface-and-format-adapters.md) 按真实 MIME、权限与已装能力选择。不可将 Office 二进制伪装为文档 CRDT。
- 富文本数据模型沿用 `peanut.richtext/1` + `tiptap-prosemirror` 及 JSON `content`；`annotations` 为可演进数组。媒体节点保存 `{fileKey, kind, variantKey?}`，不保存签名 URL、存储路径或永久 CDN URL。
- 归属 `owner = {moduleKey, objectType, objectId}` 必填，`slot` 为业务模块声明的用途标识（如 `body`、`notes`，也可在业务许可下多份附录的稳定槽位），在 `(tenant_id, owner_module_key, owner_object_type, owner_object_id, slot)` 上唯一。不接受来自前端的表名、类名、SQL 或任意模块 key。
- `created_by` / `updated_by` 用于历史审计，**不构成额外文档管理员或成员 ACL**；文档负责人、参与人及授权以主管业务对象的当前规则为准。文档可以有自己的审计操作者，但不重复维护一套人的权利。
- 状态：`active`、`archiving`、`archived`；另有 `blocked_recovery` 供保存失败/归属异常安全隔离，不能把异常当作可编辑草稿。创建由业务模块受控命令触发，只有归属业务存在且允许该槽位才能创建。普通 CRUD 不得绕过这项不变量。

### 2.2 表与字段（新迁移；表名为目标名称）

| 所属表 | 最小字段 / 主键 | 约束和含义 |
| --- | --- | --- |
| `pa_document` | `id, tenant_id, owner_module_key, owner_object_type, owner_object_id, slot, content_kind, body_type?, schema_version, status, auth_revision, state_seq, created_by_member_id, updated_by_member_id, create_time, update_time, archived_at` | `(tenant, owner, slot)` 唯一；`rich_text`/`file_resource` 类型校验；文档归属不可由普通接口改写；必要租户/成员约束保留 |
| `pa_document_state` | `(tenant_id, document_id), yjs_state_binary, state_seq, state_sha256, projected_json, projection_schema_version, persisted_at, last_store_id` | **仅 `rich_text` 使用 Yjs**，其二进制为协同权威状态；JSON/HTML 是派生视图；持久保存/幂等由 PHP 确认 |
| `pa_document_revision` | `id, tenant_id, document_id, revision_no, source_state_seq, content_kind, yjs_snapshot?, content_snapshot_json?, immutable_file_key?, immutable_file_sha256?, created_at, created_by, reason, snapshot_sha256` | `rich_text` 固定 Yjs/JSON，`file_resource` 固定不可变文件字节及受控文件引用；业务冻结版本不可原位修改，恢复旧版本另建新版本 |
| `pa_document_file_ref` | `tenant_id, document_id, file_key, variant_key?, usage, derived_state_seq, updated_at` | 同时索引富文本附件和文件型主资源，调用 File 公开权限/引用机制，不拥有物理文件 |
| `pa_document_resource_binding`（目标） | `tenant_id, document_id, primary_file_key, verified_mime_type, source_sha256, state_seq, bound_at` | 文件型文档的原件绑定；替换使用新的受控文件对象或证明不可变的文件修订，CAS 更新且历史冻结版本不被改写 |

表名、字段默认值和精确 SQL 类型须在实现时对齐现有迁移风格（MySQL 8.4）；**不得改写**已有 `20260903-create-rich-text-document.sql`，不得覆盖 `pa_rich_text_document` 旧记录。新模块只声明自己新建/迁移的表。禁止将项目产品化过程需要的审计和编辑主状态隐式放在 Node 内存。

### 2.3 保存和版本语义

1. `state_seq`：每次 **PHP 持久成功**增加的技术保存序号；富文本的 Yjs 自动保存与文件型的受控替换均可推进序号，不能把编辑器界面变化冒充已保存；不等于业务正式版本。
2. `revision_no`：业务主动冻结的不可变版本序号。工作流送审、签发、发布必须拿 `revision_id` + 校验摘要，不能拿“当前实时正文”隐式代替。正文协同继续编辑不会更改既已送审/发布的版本。
3. 富文本 PHP 的 Yjs `store` 在文档级串行条件下校验 `(tenant_id, document_id, base_state_seq, store_id, lease_epoch)`，完整 Yjs 二进制和派生内容同事务 CAS；同 `store_id` + 同哈希重复提交返回同一结果，摘要不同拒绝。错误不静默覆盖或合并两个独立 Node 房间快照。
4. 发版/送审固定点击时的目标，等待实际入库证明覆盖该目标，再由有权业务操作生成不可变版本；等待期间其他人可以继续编辑，新编辑不进入该次版本。归档和回退停止新写、排空已有编辑并取得真实持久化回执后，才改变状态或恢复正文并推进授权修订及房间 epoch。保存失败或结果未知保持原目标与请求身份，不宣称已冻结或已归档。
5. 用 Yjs 更新/快照恢复协同状态；不要把 JSON 反复转回 Y.Doc 作为协同主存储（会造成内容重复或合并异常）。JSON/受清洗 HTML 可在保存、预览、导出时投影并携带源 `state_seq`。协同模式下禁止旧 HTTP `edit(document_json, collaboration_state)` 与 Node 对同一正式文档并发双写。

### 2.4 生命周期及归档

- 业务对象归档/标识删除时，其**专属文档**进入 `archiving` → 保存屏障 → `archived`，退出常规列表和编辑；可在获准的业务归档页面读取，不作为物理删除。其他业务对象/文件共享的附件不自动删除，也不自动改为私有；资源可见性变更走独立文件合同。
- 业务对象恢复时，核租户、原所有权、唯一槽位、归属及当前权限后受控恢复文档，不自动将已发布/已审批版本变为实时草稿；原业务记录物理缺失时 fail-closed、审计标记待修复。
- 业务对象永久清理、保留期、合规销毁独立授权与审计，先核修订引用/法律保留/文件引用；V1 不自动执行文档和资源物理销毁。
- 同一正式文档不可在不留审计的情况下“转给另一业务对象”；复制另建记录，历史归属保持。

## 3. 权限计算与文件关系

`can(action, document) = 已验证会话/租户 ∩ 当前模块安装及租户开通 ∩ 当前角色/Scope/成员/委托 ∩ 主管业务对象公共授权回调(action, owner, document.slot) ∩ 文档可用状态`。`view`、`edit`、`version.create`、`archive.read`、`archive/restore` 分开。前端按钮、存储 ID、文档 ID、Room 名、文件句柄本身从不授权。管理员不是自动越过其他租户的文档所有人。

- 业务模块应通过正式登记的 `DocumentOwnerPolicy`（确有跨模块边界才设接口）提供 `resolveOwner`、`authorize(action, ownerRef, context)` 和 `stateRevision`；返回可信 DTO，不暴露内部 ORM、SQL 或把原始用户输入当业务权限规则。Doc 模块复核租户/软删除/业务状态/模块状态；业务模块失效时新编辑拒绝。
- `owner` 解析失败、版本不存在、权限来源缺失、未知模块/Slot、服务断联不得退化为“同租户已登录即可读”；按现行 403/404 隐藏策略返回错误，并留下可追踪原因。
- 文档阅读许可 **不自动授予**其所引文件的独立下载原件/管理/重命名/删除。`peanut.file` 在 `view-preview` 场景可接受经 Doc 验证的一次性**受限预览授权**：同租户 + 当前文档授权 + 引用关系/可继承标记 + 当前文件可见性/敏感策略联合判定；涉密文件仍要求单独授权。文件管理入口不接受文档预览 grant。
- 正文只存 `file_key`，内容渲染时按当前权限向 File 服务请求短时地址；缓存键包含租户、资源及权限修订，撤销后重新解析。`file_key` / 媒体引用不隐含外部可公开访问；未经 File 确认不得将私有原图 URL 嵌入已发布 HTML。
- 文件公开→私有、引用影响/CDN 处置、物理空间迁移和批量操作由 `peanut.file` 的独立产品方案负责；Doc 只记录引用及刷新/受限呈现，不复制批量 Saga 或 CDN 逻辑。
- 文档关联多个对象的**引用**与共同所有权不同：引用方最多通过显式权限读取某个冻结版本，不能借引用获得正文写权限或文件管理权。跨租户默认拒绝，V1 不提供跨租户实时协作。

## 4. 正式 PHP / HTTP 接口

沿现有 ThinkPHP `adminapi` 模块路由、中间件、响应/错误和 OpenAPI 生成机制；以下命名为**目标动作键**，实现时按现有 permission/catalog 风格登记，不能另造 REST 兼容服务器。

| 动作键/公开服务意图 | 输入（业务字段） | 输出 / 前置条件 |
| --- | --- | --- |
| `peanut.document.create`（由业务模块调用的公开 Command） | `owner:{moduleKey,objectType,objectId}, slot, contentKind=rich_text|file_resource, fileKey?（仅文件型）, idempotencyKey` | `{documentId,status,authRevision}`；由业务模块和 File 共同核权，文件型还需校验真实 MIME、受控资源绑定；不提供匿名创建入口 |
| `peanut.document.detail` | `document_id`、可选 `revision_id` | 当前获准元数据 + 派生 JSON + `stateSeq/revisionId`；只读 API 不返回二进制状态或签发 WebSocket 凭据 |
| `peanut.document.version.create` | `document_id, expected_state_seq, reason, idempotency_key` | `{revisionId,revisionNo,sourceStateSeq,digest}`；先保存屏障；正常业务模块决定何时使用 |
| `peanut.document.archive` / `restore` | `document_id, expected_auth_revision, request_id` | 归档/恢复结果，受主管业务模块生命周期动作协调；不提供脱离业务状态的任意归档 |
| `peanut.collaboration.transport` | `document_id, mode: read|write` | 返回 `wss_url, document_name, document_id, mode, protocol`；重新核编辑/查看权限，不签发协作凭据 |
| `peanut.document.solo.save` | `document_id, expected_state_seq, structured_content, request_id` | 仅 `rich_text` 单人模式；保存后 `{stateSeq,persistedAt}`；启用协同时禁止此路径双写 |
| `peanut.document.resource.bind`（目标 Command） | `document_id, file_key, expected_state_seq, request_id` | 仅文件型：校验 owner/文件引用授权和文件真实 MIME/哈希，CAS 绑定新受控资源修订，历史冻结版本不能被改写 |
| `peanut.document.capabilities`（目标 Query） | `document_id, intent=view|edit, revision_id?` | 服务端返回可信已装适配器、可用状态及查看/编辑/原件下载三种独立权限；不接受前端声明授权 |
| `peanut.file.assets` / 受控 `resolve` | `file_key` 集合、`document_id` 受限上下文、目标动作 | 按 File 本身权限返回动态预览，不因为 caller 提供 document_id 就信任授权 |

跨模块 PHP 公开能力按真实消费登记到 `module.json:contracts.exports`：如 `DocumentCommands`、`DocumentQueries`、`DocumentOwnerPolicy`、`DocumentVersionSnapshot`、`CollaborationSessions`。签名、返回错误码和字段要生成真实类型/OpenAPI；不可单独注册未经授权的可写 ORM Model。HTTP 请求中的 `tenant_id`、`owner` 字段都不能替代受信执行上下文和后端业务对象二次核验。

### 4.1 仅 Node↔PHP 内网接口（禁止浏览器直调）

使用 PHP 应用拥有的内部路由组（示例 `/internal/collaboration/v1/*`），同网络内仍要求双向服务身份：mTLS 或已登记的轮换服务凭据＋请求时间窗口/nonce/HMAC 防重放；不与浏览器 Bearer、成员 JWT、文件签名 URL 混用。应用实例标识/Edition/tenant/room 全部绑定；外网代理不暴露此组。

| 入口 | 请求关键字段 | 响应/保证 |
| --- | --- | --- |
| `authorize` | `token, document_name` | 后端验证原生 Session/Member、租户和文档权限后返回可信连接身份、Binding、epoch、实际模式、权限修订和租约；输入全部不可信 |
| `check` | `connection_key, mode` | 分开进行完整授权续期，返回当前连接 grant；不用于普通编辑逐帧请求或心跳 |
| `load` | `connection_key, room_key, lease_epoch` | 首次返回最新持久化 Yjs、投影、序号及既有存储身份；不存在不自动创建独立文档 |
| `store` | 固定合并捕获、原 base/epoch/store_id、参与者授权窗口及摘要 | 同事务保存 Yjs、投影、File 引用及 CAS/幂等回执；Node 不直连业务数据库 |
| `receipt` | `room_key, lease_epoch, store_id, capture_sha256` | 未知结果查询原捕获；未找到才可重送同一 tuple，不另造存储身份 |
| `references` | 原连接身份、epoch、新增文件引用及请求身份 | 实际加入者的独立 File 权限和保护预约；普通文本编辑不调用 |
| `target/prepare` | 原请求身份、点击时固定 Yjs/投影/引用及进度 | 在后续保存前由 File 保护该目标的引用；不写草稿，不授予预览权限 |
| `target` | 原请求身份、实际提交回执、原点击捕获及签名覆盖证明 | 确认实际提交覆盖已准备目标，不重写草稿；之后自动保存不改变目标 |
| `drain/release`（Node 控制面与 PHP 编排） | 原 operation/input 摘要、Room、epoch；释放带结果 epoch/status | 归档、回退停新写后保存实际捕获；PHP 锁定真实回执并推进 epoch，Node 关闭旧连接后 fresh load |
| `revoke`（PHP→Node） | `room, new_auth_revision, reason, event_id` | 可靠失权通知使现有连接按需降权/断开；通知丢失时 `check`/短期租约仍兜底，不能只靠推送 |

服务契约应有统一错误分类：`NOT_FOUND`, `FORBIDDEN`, `MODULE_DISABLED`, `OWNER_ARCHIVED`, `STALE_AUTH_REVISION`, `REVISION_CONFLICT`, `STORE_DUPLICATE_MISMATCH`, `COLLAB_UNAVAILABLE`, `STORE_BACKPRESSURE`, `RECOVERY_REQUIRED`，沿既有 BusinessException / API 语义映射。不能把 DB/网络失败映射成文档为空。

### 4.2 V1 端点封装与状态返回（Codex 必须同批固化真实类型）

D1 的真实 PHP 公开编排入口为 `DocumentCommands::freeze/restoreRevision/archive/restore/runOwnerLifecycle`。
正式版本消费同请求身份登记的点击目标，不要求它仍是当前最新草稿，也不为创建版本停止其他人的编辑。归档、回退和恢复才使用房间排空控制；原逐帧接纳日志及其 accepted 水位合同退出当前路线。
准备与最终提交分别使用短原生事务，网络只位于两个 COMMIT 之外。最终提交仍由当前 Session、原 owner policy 唯一决定资格，并用 Identity 的公开 current-read fence 锁定原权限/模块输入；持久水位、版本、File 引用、审计和原 operation 在同 PDO 原子写入。
服务请求与响应采用方向分离的 HMAC 传输协议 2。每次 Node 进程启动生成新的 UUIDv4 `holder_key`，与固定部署 `service_instance_id` 分开；签名 canonical 在 instance、service instance、key ID 后绑定 holder。连接授权与首次加载返回该 holder，Room、连接 grant、保存和恢复均核验它。新进程不能继承旧进程尚有效的租约；显式恢复须等待旧 Room 与写连接 grant 到期、核对未完成业务，再推进 epoch。历史协议 1 房间只能在原服务已排空并停止的切换条件满足后迁入，不将历史日志回放到新服务。

Node 验证本地直接配置后监听，不依赖 PHP/数据库的全局注册、prepare/activate、配对启动或进程准入租约。服务 Secret 是至少 32 字节的直接字符串，不能返给浏览器。Room 的 holder、epoch、短租约、连接 grant 和真实保存回执继续限定业务写入；协作业务准备与保存沿 IAM→Owner/Doc→State/operation→Room/Connection/Grant 事务路径核验。网络调用仍在 SQL 事务之外，业务屏障和未知结果保护保持。

实例停止由 Node 本地信号路径关闭新连接/写入，并等待已接收消息、加载和实际保存完成；只有真实数据库回执才能证明持久保存。原业务房间屏障、epoch、显式目标及未知结果保护继续有效，不以全局生命周期表、operator HTTP 或 PHP CLI 停机证明控制启动。未完成或结果未知时保持失败关闭，不能强杀或清除原内容。应用适配器尚未具备可证明安全停止的接入时，只阻止依赖该动作的停止/禁用/升级。

Node 控制端点 `/internal/collaboration/control/v1/business-barrier` 的 URL 保留，方向 HMAC 域和传输封套采用 v2，业务正文使用协议 2。请求 header、签名正文及计划均绑定真实 Room holder；新进程必须拒绝旧 holder 请求，不能将其回答为 offline。排空结果绑定原 operation、输入摘要、Room、holder、epoch、实际 store_id/state_seq/binary_sha256/Snapshot；进度计数不代替提交证明。同一 holder 无在线房间时仅返回 offline，PHP 在业务锁内采用真实持久化基线并防止新加入。旧 holder 已死亡且业务结果未知时保留未确认状态，通用恢复不解锁或冒充排空。技术提交不能替代业务成功。恢复追加新 CAS 状态/版本/epoch，使用不可变旧 binary 与 matching projection、fresh Y.Doc，并关闭旧连接、卸载旧 native document 后才确认释放。

点击目标先 prepare 保护文件引用，后用真实提交 snapshot 覆盖点击 snapshot 的证明确认；覆盖检查包含删除集合，不能仅比较状态向量或进度计数。公开目标查询在当前版本权限下，对不存在准备记录的请求返回 not_prepared，目标摘要、目标进度、提交与版本字段均为 null，不能作为已保存证明；已有目标仅向原操作者返回 prepared、confirmed、consumed 或 canceled 及真实已有回执，不返回正文。取消只释放未消费目标的专属引用保护；尚未准备的请求以原请求身份、真实人员和业务归属持久记录取消，重复取消返回同一 canceled 小回执，迟到的 prepare/register 不能复用该请求。没有快照的取消目标查询摘要与进度字段为 null，不制造提交证明。已开始正式业务保存或已消费的目标不能取消。未知结果复用原请求与点击内容，不能重新加载后悄悄另建目标。confirmed/consumed 目标可在当前完整业务授权下续作原正式保存，无须原 Node 进程仍在线。

归档文档的恢复有一个明确的持久基线分支：只有 Doc 已归档、Room 已关闭、Room 与写授权均已过期、没有待办业务，且数据库正文和 Room 基线一致时，才能用 `closed_persisted` 计划在 PHP 同事务围住加入、恢复状态、推进 epoch 并释放。它不签发 Node 排空证明、不延长旧租约；下一次有权加入仍由新 holder 显式恢复并加载持久草稿。关闭但租约尚有效的房间继续使用原生签名控制，活动房间和未知业务不能走此分支。
未知结果通过 `peanut.document.operation` 查询原操作并复用同 key/input；不新造 operation、不按超时解锁。正式 TLS/managed-loader/readiness 和真实数据库/多人/socket/浏览器资格由后续对应入口核验，源码协议或单元替身不证明已完成这些资格。

以下是**跨组件/跨进程强约束**，`Controller` 适配的最终响应外壳复用 Peanut 现行 API 格式，不自行发明通用响应码：

```ts
// 公开应用服务之间使用已验证的 Tenant/Member 上下文，不从请求体读取可信身份
interface OwnerRef { moduleKey: string; objectType: string; objectId: string }
interface DocumentStateRef { documentId: number; tenantId: number; stateSeq: number; authRevision: number }
interface FrozenDocumentVersion { revisionId: number; revisionNo: number; sourceStateSeq: number; digest: string }
interface CollaborationTransport { wss_url: string; document_name: string; document_id: number; mode: "read" | "write"; protocol: 2 }
interface DurableSaveAck { documentId: number; storeId: string; stateSeq: number; digest: string; persistedAt: string }
```

- 业务拥有者的适配合同（PHP 业务抽象）至少具备 `resolve(ownerRef)`、`authorize(context, ownerRef, slot, action)`、`currentAuthorizationRevision(ownerRef)` 三个语义，并给出当前 `owner` 是否有效及业务状态。无实现、失效模块、无法读取真实状态时拒绝；**调用者不能通过伪造 ownerRef 任意套用其他模块的允许结果**。
- Document 所属模块的正式公开 Command 至少提供 `ensureForOwner(trustedContext, ownerRef, slot, bodyType, idempotencyKey)`、`freezeVersion(trustedContext, documentId, expectedStateSeq, reason, requestId)`、`archiveForOwner(trustedContext, ownerRef, requestId)`、`restoreForOwner(...)`；Query 提供 `getAuthorized(trustedContext, documentId, revisionId?)`。真实命名可按 PHP 现有约定收敛，但语义、上下文、结果和权限**不可省略或改由业务模块直写 Document 私表**。
- Node 内网 `authorize` 响应中至少有 `tenantId, documentId, memberId, mode, authRevision, leaseUntil, connectionId`；`store` 必须有 `{room, leaseEpoch, baseStateSeq, storeId, checksum, stateBinaryBase64, contentProjection}` 并返回 `DurableSaveAck`；`load` 提供 `{room, leaseEpoch, stateSeq, checksum, stateBinaryBase64, schemaVersion}`。这些字段由相同服务身份、租户+文档主键绑定，不能跨不同 room 复用 `storeId`。
- `ContentProjection` 标记 `schemaVersion`、源 `stateSeq` 和投影器版本，确保服务器验证内容深度/大小/媒体节点/URL 安全与 `stateBinary` 对应关系。不接受 Node 传入未经校验的任意 HTML 作为正式富文本；如果仅能产生不可信投影，则把投影状态标为待修复并禁止发布/冻结，不覆盖权威 Yjs 二进制。
- 持久化响应**不等于 WebSocket 广播响应**：Hocuspocus Provider 不天然将 PHP DB commit 序号回传 Vue。`DocumentEditor` 需要明确的持久水位回执通道或受权轮询查询，并把它与本地已编辑状态向量关联；只有 PHP 确认覆盖本地修改后才显示“已保存”。服务宕机、网络未知时显示“未确认”，重新查询回执而非虚构成功。
- 原生用户 token 只经受控连接鉴权传输，不放 URL query、可索引页面或日志，不额外复制到协作存储。内部服务凭据、nonce、时钟偏差/重放窗及轮换策略须随同 Node/PHP 原生配置保持一致；时间字段使用明确 UTC ISO8601 或现行标准格式，不让 PHP 与 Node 靠本地时区推算。

## 5. Vue 标准组件合同

标准**业务组件**位于 Code 的相应模块；不要把带文档 API、成员/文件权限和业务状态的组件塞进 Web Core。沿 `script setup lang="ts"`、Composition API、Element Plus、异步导入和现有 SDK。

```ts
// 目标公开类型示意，不代表已在源码导出
interface DocumentIdentity { documentId: number }
interface DocumentOwnerRef { moduleKey: string; objectType: string; objectId: string }
type DocumentMode = 'read' | 'edit';
type SaveState = 'idle' | 'syncing' | 'persisting' | 'saved' | 'error' | 'readonly';
interface RichTextValue { schemaVersion: 'peanut.richtext/1'; editorModel: 'tiptap-prosemirror'; content: unknown; annotations: unknown[] }
interface FileRef { fileKey: string; kind: 'image' | 'video' | 'audio' | 'file'; variantKey?: string }
```

| 组件 / 类型入口 | Props（目标） | Emits / 行为 |
| --- | --- | --- |
| `RichTextEditor`：`@peanut-admin/peanut-rich-text/editor` | `modelValue`, `mode: 'read'|'solo'|'collaborative'`, `provider?: CollaborationBinding`, `disabled?`, `toolbar?` | 单人模式 `update:modelValue`；协同模式 Yjs 由 DocumentEditor 管理，**不得**每次 onUpdate 再把 JSON 当独立保存源；`change`, `connection-state`, `error`，卸载清理连接 |
| `DocumentEditor`：`@peanut-admin/peanut-document/editor`（新增） | `documentId`, `mode`, `readOnly?`, `initialRevisionId?` | 按已授权格式能力选择真正的富文本或专用文件编辑器；未装 Office 引擎明确只读/不可编辑；所有保存经 PHP 版本与权限合同；`ready`, `save-state`, `version-created`, `error` |
| `DocumentViewer`：`@peanut-admin/peanut-document/viewer`（新增） | `documentId`, `revisionId?`, `allowDownloads?` | 统一只读分发：根据服务端内容类型/MIME/权限选择富文本、PDF、图片、视频、Office 等已注册预览器或安全降级；`load`, `capabilities`, `error` |
| `FileAssetPicker`（适配已有 `FileAssetSelector.vue`） | `selectedFileKey`, `allowedKinds`, `documentId?`, `disabled?` | `select({fileKey, kind, variantKey?})`, `cancel`, `error`；后端按真实文件授权校验，不把传入的 documentId 当权利凭证 |
| `FileReferenceView`（File 或 Document 组合件） | `fileRef`, `documentId?`, `operation='view-preview'` | 逐次解析短期地址与错误占位；`load`, `permission-denied`, `error`；不公开秘密 URL 到持久状态 |

约束：公共编辑器仅负责内容交互、状态/事件；`DocumentEditor` 负责文档 API + Hocuspocus Provider；文章/新闻等业务页面传已获取的 `documentId` 并保留自己的保存/发布流程；`FileAssetPicker` 仅业务挑选，不修改文件 ACL。旧 `v-model:collaboration-state` 只用于已有编辑示例与迁移兼容，正式协同态由统一 Provider/PHP 驱动，不能多端互写 base64 快照。

示例用法（仅说明调用形状）：

```vue
<script setup lang="ts">
import { DocumentEditor } from '@peanut-admin/peanut-document/editor';
defineProps<{ documentId: number }>();
</script>
<template>
  <DocumentEditor :document-id="documentId" mode="edit" @save-state="state => { /* 页面提示 */ }" />
</template>
```

内容白名单、链接协议、图片来源/大小、粘贴清洗、富文本导出 HTML 的服务端再校验是必要条件；媒体 `fileKey` 应在服务端校验，未授权时显示错误占位而不泄露存储地址。保留现有 `RichTextEditor` 导出路径，迁移时先增加适配/弃用提示，不无计划断掉消费者。撤权、切租户、卸载均断开 Provider，清理延迟回调和本地缓存，不能由旧会话重放保存。

**跨模块多格式合同**： [编辑器与预览器注册、能力判断、Office/PDF/媒体降级与版本处理](content-surface-and-format-adapters.md)。第 6 节的 Hocuspocus/Yjs 只适用于 `rich_text`，不是 PPTX/XLSX 等格式的通用协作协议。

## 6. Hocuspocus ↔ PHP 运行时合同

### 6.1 连接与权限

1. 浏览器通过现有登录会话请求 PHP `transport` 元数据，并把原生用户 token 交给 Hocuspocus。PHP 不签发协作票据，不把 token 放入 Room 名或返回元数据。
2. Hocuspocus `onAuthenticate` 调用 PHP `authorize`，输入为 `{token,document_name}`。文档名各字段全部作为不可信请求处理；配置核对、`TenantAuthService::context`、租户相等及实际文档授权共同产生可信 Room/Binding 和实际连接模式。后续连接引用服务端 Session 账本，短房间/连接租约持续复核真实权限。
3. 锁定 SDK 的 awaited `beforeSync` 在合并前检查该连接已经取得的写授权、有效期和房间状态；普通文字更新不逐帧请求 PHP，不在 `onChange` 重复完整授权。传输心跳只保活，完整身份、功能及文档授权在加入、续期和状态变化时复核。撤权通知拒绝新写并关闭/降权连接，短租约复核作为有明确检测上限的兜底；不得将周期复核称为即时撤权。授权过期或无法续期则停止新写，重新认证后才恢复。
4. 在线“谁在编辑”（awareness）只含必要昵称/成员 ID 且以实际鉴权身份绑定，客户端自报名字不被信任。只读和可写连接能力分开；必要时无授权者不订阅正文广播。

#### 可靠负向授权事实与定向失效

Identity 成员 suspend/left、成员角色减少、角色权限减少或归档、独立 Session 撤销、密码变更导致的账户会话撤销，沿原事务产生 `identity.revocation.fact`。事实复用 Identity 审计主键/冻结 JSON 和独立 `revocation_` ID，固定前后修订/负向差集、实际受影响 tenant/member、原 actor provenance、来源请求与摘要；不使用后来变化的角色关系重构旧事实，不把原 session/token/password 写入任务。账户影响多个租户时逐 tenant/member 冻结；本切片不声明覆盖未知全局 Account disabled、Platform 或 credential-recovery 写路径。

已注册的可选消费者在同一真实 PDO/外事务中加入官方 Task；异常向上传播，不能提交身份变更却丢事实。消费者缺失时不伪造 queued，事实可由部署 owner 显式 `php think identity:revocations --tenant=<id> --after=<audit-id> --limit=20` 有界发现并补投。`--repair` 仅为该专用 profile 的 dead/旧 holder 完成记录增加一个剩余尝试（总计上限10），不重置原 attempt、fact 或目标身份。该命令无 Node RPC、不登记自动调度、不授普通 HTTP/租户任务维护权限。

唯一 Task profile 为 `identity_negative_fact`，purpose/handler 固定 `identity.revocation.propagate`。授权来自已提交 Identity 事实、专用签名域和原 Task claim，不来自失效 actor session。普通 member profile 的 creator 非空/FK、Core 签名和现行 reauthorize 保持；只有负向 profile 可以有 NULL 技术 creator，原 actor 仅留审计引用，不伪造成员。worker 每个批次/RPC/ACK提交重新核 fact/hash、tenant、purpose、jobKey、attempt、lease、模块及当前 holder；租约丢失不能追加 ACK。

正向 transport/authorize/check/renew 在同一原生 PDO/外事务内按 IAM lifecycle/package/installation → Tenant/Account/Member/Role/Permission/Session → Owner/Document/State → Room/Connection/Grant 持锁。仅以服务端 OwnerPolicy 的权限、模块和对象绑定捕获原生 IAM 摘要，锁定重读相等后执行真实 Admin 权限决定；IAM 锁保留至外事务完成。原事务外 snapshot 合同不变。现有 64 位 SHA256 `authorization_revision` 统一使用 `peanut.document.grant-authorization.v1` 域，绑定 tenant/member、文档、owner/action、所需权限/模块、原生 IAM fence、Document/Owner 修订。Role/tenant 修订推进使撤权→恢复仍改变摘要，不按 opaque hash 数值排序。

PHP 仅从 Collaboration 自己的可信 Session/connection ledger 选择同 tenant、冻结 member 的旧 grant，按 connection ID 有界分页。专用 System fact/job/attempt 范围下只读真实 Session、当前权限及 Owner/Document 生命周期，并以前后相同原生 IAM 摘要和对象绑定确认观察：`OLD_STALE` 加入精确旧 tuple，`CURRENT_FRESH` 排除真正重新授权后的相同摘要，`UNKNOWN` 保留 pending/retry，不能用空 ACK 代替未完成选取。协议2的历史授权摘要与新算法不同则按旧 grant 处理；版本未知、账本缺失或查询异常均失败关闭。首次选取的 high-water、每批精确 connection/room/epoch/member/authorization_revision 集合、scope digest 与 ACK 写入原 Task event，重试复用。新 holder 使用新的目标计划/ACK，不能继承旧 holder 证明；Node 只匹配当前连接的完整旧 tuple，后续 renew 的新摘要不被旧 scope 关闭。历史 accepted capture 仍使用原持久 grant/hash/时间窗口/holder/epoch 证明，不能改用当前人类权限重验或丢弃已接纳内容。普通 grant lease 仍是独立兜底，不恢复逐帧 PHP 鉴权。

Node 负向协议为 `POST /internal/collaboration/access/v1/invalidate`，payload/HTTP protocol 均为1，签名域 `peanut.collaboration.access-invalidation-v1.request.v1` / `.response.v1`，与 drain/release/operator 域隔离。沿现有服务密钥、instance/service/key-id/holder 前缀、nonce/timestamp、method/path及请求/响应 body hash验证。请求字段精确为 `protocol_version,action,fact_id,fact_sha256,tenant_id,subject,negative_action,revision_reference_sha256,operation_id,instance_id,service_instance_id,holder_key,targets,scope_digest`；subject 为 `kind,id,member_id`，每个 target 为 `connection_key,room_key,lease_epoch,member_id,authorization_revision`，每批最多100。scope digest 是删除 scope_digest 字段后递归按对象键排序的 JSON SHA256。只允许 invalidate_old_grant；不得赋权、重入房间、恢复写、修改正文或释放 Document 屏障。

Node 先拒绝匹配旧 grant 的新写并关闭连接，再回复经过认证的 ACK；完整旧 tuple 已不在当前 holder 中时可回复 no_matching_connection，不能因此关闭相同 key 下后来更新的授权。跨租户/成员、来源不符和旧 holder 拒绝；空 targets 仍绑定冻结 subject/member 与当前 holder，不是未发送成功。ACK 字段精确为 `protocol_version,fact_id,fact_sha256,operation_id,instance_id,service_instance_id,holder_key,scope_digest,outcome,processed_at,matched_count,closed_count`；outcome 为 applied 或 no_matching_connection，时间为 UTC ISO8601 毫秒。重复事实/目标同摘要返回同一结果；改摘要拒绝。PHP 只将有效、当前绑定的 ACK 持久关联事实，超时/非200/签名错/失联/retry/dead保持未确认。

occurred_at 在事务内写入，不作为准确 T0。上述独立命令对30秒仍未确认的记录持久记 `identity.revocation.overdue_candidate`，明确使用 precommit_occurred_at 的保守候选依据；它不是已测 commit SLA。健康条件下 T1−T0≤5秒、T3−T0≤30秒和随后5秒检测仍是需要真实 APP/Worker/Node/DB及部署配置证明的资格目标，当前 NOT_QUALIFIED。本切片为 PHP 源实现；Node消费者、迁移执行、真实 ACK、并发授权/撤权与时效运行断言未验证。

### 6.2 初始化、自动保存与崩溃恢复

- 仅在第一次载入某份文档时 `onLoadDocument` 从 PHP 读取已持久化 Yjs 二进制，缺值时仅在业务已创建且合法的初始文档生成一次 CRDT，禁止每次从 JSON 重建 Y.Doc。
- 本轮采用锁定原生 Hocuspocus 的合并、广播和共享房间生命周期：先合并广播，后批量自动保存。广播前可靠记录每条增量仅为未来备选，本轮不实现、不保留第二套长期运行链。
- `onStoreDocument` 带去抖保存（初始目标 `debounce=2s`, `maxDebounce=10s`；按实际负载验证），在等待 PHP 前固定本次 Yjs 字节、投影、文件引用、批次 ID、原 `state_seq`/epoch 及目标进度。保存期间的新修改继续累积，不能在数据库返回后从已变化的活动文档生成本次提交证明。失败或结果未知保留原批次身份，先查原回执，不换 ID 重复写入。
- “已合并”与“已保存”分开。SDK 的 `SyncStatus`/`synced` 不证明入库；只有核对 PHP 实际提交回执后，才通过原生 stateless 通道发布与本次固定捕获对应的 Yjs Snapshot。前端用 `Y.snapshotContainsUpdate` 验证本地更新及当前可见草稿的覆盖，包含纯删除，不能仅按 state vector 时钟或发送次数认定保存。
- 新增文件引用保留来源人员的权限证明和必要资源占用，特殊引用变更可单独核权；普通文本不因此恢复逐帧 PHP 请求。持久化在同一事务内校验文件引用、更新引用索引和草稿、记录幂等回执；不能用最后一位编辑者或 System 身份代替多人批次的来源权限。
- 显式保存通过原生消息顺序确定房间目标并固定捕获，等待该目标实际入库；已有提交证明覆盖目标时复用原结果。正式历史版本使用该明确目标的不可变 Yjs/投影快照，不把每次自动保存当成历史版本，不新增完整操作回放系统。
- 回退先隔离旧房间、旧连接和未完成捕获，推进 epoch，再从历史 Yjs 加载新活动房间并重新同步授权成员；不能将旧版本的 update 简单合并进包含后来修改的 Y.Doc。
- Node 单实例 V1，以单 Room 单活/写租约避免两个 Node 并发覆盖。存储失败时保留待提交状态、受控退避重试和明确 `unsaved` / `read-only` 标记，队列设置上限；达上限禁止继续接受写，不能丢弃写入却显示成功。进程停止应先停止接入、drain，有未确认保存时返回非成功终止状态并留下告警。
- PHP MySQL 为耐久真相源，Node 本地内存/可选临时队列不作为最终存储。启动后从最新 `state_seq` 加载；旧服务最后写入不得覆盖新 `lease_epoch`。重复 `store_id`/网络结果未知先查持久结果，不盲提交新版本。
- 未入库的广播修改在突然宕机后可能丢失；本轮恢复只保证最后实际提交的 Yjs 草稿及历史版本，不承诺通过未实现的增量日志重放这些修改。切换旧服务前须先结清其已接纳未持久内容和未完成业务请求，保全原 Yjs、引用、历史及原失败证据，再隔离旧 epoch。
- 浏览器断线：明确“连接中断/未保存”；V1 先停止可写输入，不承诺离线多端合并。重连需新票据、重新核权、重新同步；权限已撤销则进入只读/拒绝，不偷偷把本地旧变更写回；如果无法无损恢复，提供导出当前本地未提交内容的受控人工恢复路径，明确仅本地草稿而非正式版本。
- 只配置一项 URL/Secret、身份验不通、owner 缺失、Schema 不兼容、DB 不可用、Node 失联时禁止错误回退到另一个无授权写通道。未启用协作的文档才使用带 `expected_state_seq` 的单人 HTTP 保存；一旦启用协作，禁止自动切回旧 CRUD 双写。
- 新旧 schema 升级不允许混合读写；服务和 PHP `protocol_version` 握手必须兼容，先完成迁移和服务健康再允许流量，回滚不能使数据库被旧格式覆盖。

原生 stateless 进度协议的最小结果为 `committed { documentId, roomEpoch, stateSeq, captureId, binarySha256, snapshotBase64 }`。Snapshot 必须由该固定捕获的已提交字节生成；客户端只接收自己授权文档/epoch 的结果，拒绝将客户端发来的 `committed` 转播为服务端证明。`progress` 查询可返回既有提交证明，重复/无变化更新和握手不能制造新存储；`save_target { requestId }` 在同连接原生更新发送完后确定服务器目标，固定原请求身份并等待实际回执。计数仅用于展示，不作为持久化覆盖证明。首次同步完成后仍须取得提交证明，不能仅凭 `synced` 显示已保存。

点击捕获与实际自动保存捕获可以不同：后者必须用锁定 Yjs 的 Snapshot 覆盖检查证明包含前者的结构和删除。签名服务请求分别提交 committed_binary_sha256 与 target_binary_sha256/target_projection_sha256，以及点击时冻结的字节、投影和引用。PHP 校验实际回执、服务证明、当前操作者和 File 权限后登记目标；正式版本只使用点击捕获，不能混入等待期间后来接受的编辑。覆盖算法由 Node 执行，不声称 PHP 独立解析 Yjs。

### 6.3 技术选型确认

本轮从原生 `@hocuspocus/server` **4.6.0** 增量接入，客户端 `@hocuspocus/provider` **4.6.0**、Yjs **13.6.32**，直接和传递依赖均保持当前 lock，不顺带升级或统一传递依赖版本。认证、加载、轻量写入闸门、变化记录、批量保存及进度控制分别使用锁定 SDK 的 `onAuthenticate`、`onLoadDocument`、`beforeSync`、`onChange`、`onStoreDocument`、`onStateless`；具体事件参数核对已安装的精确源码。旧自定义接纳、房间、恢复链及此前提速修改只作为参考，不作为新实现的保留前提。此为已确认目标，源码/运行资格须按实际完成情况单独记录。

## 7. Docker、Edition、Peanut CLI 与运维合同

### 7.1 交付结构

- `peanut-hocuspocus` 是与 `peanut-admin-code` 同级的独立项目，拥有完整服务源码、锁定依赖、独立 Docker 配置与启停入口。以当前 Hocuspocus 4.6.0 原生服务增量接入；PHP 内部 API、统一身份和业务权限仍归 Code 官方模块，前端 SDK 仍归对应模块。服务通过接口认证、加载和保存，不导入、挂载或执行应用 PHP、前端源码，不直连业务 SQL。
- 服务使用固定 Node 22.23.2 运行镜像并只读挂载本项目程序及锁定依赖，不把 APP 源码烘焙进镜像。应用保留现有 PHP/MySQL/Nginx 配置和可选 TLS/WSS overlay；明确 `APP_ENV=development` 且配置 `websocket_scheme=ws` 时，使用本机 HTTP/WS，Compose APP 仅为同一 Nginx 选开发内部 HTTP 模板。独立服务按实际接口路线选择已有网络，不复制应用默认部署，不维护第二套长期协作机制。
- 正式环境的服务容器不直接暴露公网 Node 端口；由应用 Nginx 经严格 Host/TLS/WSS 代理 `/collaboration/`，内网只可访问 PHP 内部接口。开发 WS 直接使用 Node 自身已登记的 loopback 监听，PHP API 为已登记 host HTTP 或 Compose 内网 8080，不发布管理 host 端口。`wss_url` 为历史字段名，开发可为 `ws://`，其他环境只签发 `wss://`；HTTP 页面消费 WS，HTTPS 页面仍要求 WSS。PHP 内部服务鉴权凭据通过既有受管 secret/env 装配，不入 Git、日志、客户端；HTTP 同样执行 HMAC、nonce、防重放、实时授权、撤权、保存回执和业务屏障。HTTPS 保留 CA 校验，不自动降级；镜像使用可复现源、锁及 digest。健康/就绪检查覆盖 Node 进程、PHP 合约可用、必要存储；就绪失败不得让编辑 UI 显示可用。
- 运行时建议非 root、只读根文件系统、必要临时 tmpfs、资源/连接/请求体限制、重启策略、心跳和审计脱敏。日志携带 instance、room 的受限哈希、trace、保存序号/延迟、错误类别，不记录正文、票据、Secret、临时文件 URL。
- V1 单 Node 实例为正式支持拓扑；**不要**只通过 `replicas:2` 就宣称横向 HA。后续 Redis 房间同步/租约、节点间状态合并、一致性与保存接管验证后再开放多副本；Redis 可作为未来扩展，而非 V1 强制依赖。

### 7.2 Single / Multi-tenant

- `standalone` 应用内部仍有可信默认 `tenant_id`，只是前端隐藏租户/平台管理；`multi-tenant` 中多个租户共享同一个部署实例服务，但每个 Room、服务请求、保存行、缓存、日志/限流、授权必须按真实 tenant 区分。不同应用部署实例及 Edition 永不混用 Room、Secret、数据卷、容器名、反向代理配置。
- 模块**代码已安装**、应用协作服务**已启用**、租户的`peanut.collaboration`**已开通**、当前人员/业务对象**被授权**四件事独立检查；SaaS 不因某租户启用而向所有租户开放。
- 租户停用/迁移/模块关闭：先拒绝新票据和保存，发可靠撤权并 drain 已接纳更新；数据保留而非删除，恢复前重新校验授权。跨租户实时编辑/数据同步不属于 V1。

### 7.3 CLI 最小正式接口（待编码）

CLI **不自己实现第二套 Compose 或升级状态机**；只编排 Code 已发行的原生服务配置/检查入口。建议在独立 `peanut-cli` 增加：

```text
peanut service collaboration plan [--path APP]    # 只读：显示可选组件、Edition、配置缺口、版本/冲突
peanut service collaboration enable [--path APP]  # 显式启用：调用应用原生配置计划/原子落地，不触碰运行服务
peanut service collaboration status [--path APP]  # 读取启用/运行合同和健康；不得将未探测当健康
```

以上是**待实现目标命令**，不是现行 `peanut 0.3.2` 已支持的选项。其 `create` 先保留 `--edition standalone|multi-tenant` 明确选择；是否纳入可选服务由应用版本化模块/服务 profile 指定，不能在 CLI 中维护一套独立源码/Secret 模板。`enable` 只发布配置与端点信息，不编排 Node 运行；APP 自身启动/升级沿用原生 Docker/installer/upgrade 流程，Node 由其独立 Compose 和服务 owner 管理。APP 配置命令不承担 Node 停服或禁用，正式部署仍需对应运营 Gate，不能直接删除持久卷。

发布/升级：记录服务镜像版本、PHP API protocol、Module 版本、迁移水位、Edition/profile、schema 和回滚前提；生产更新先兼容检查与排空、备份，再服务/PHP/前端按兼容顺序升级。新应用默认协同功能可选且默认关闭，未选择时不安装 Node 常驻服务；启用协同也不要求将 PHP Core 或 Web Core 分裂成新服务。

APP 的可选配置入口为 `python3 server/docker/scripts/configure-collaboration.py plan|enable|status --app-root <绝对 APP 路径>`，负责配置发布与读取；应用启停适配只操作自身 Compose，不调用全局 runtime 登记、配对 probe、prepare/activate 或进程准入。启用外部协作端点不要求普通 APP 部署、停止或升级先停 Node。应用自身的停写、容器与挂载身份、数据库备份恢复校验继续保留。后端暂不可用时 Node 保持监听、拒绝无法鉴权的新连接，并按授权有效期与保存保护处理已有连接。Node 自身停止仍由服务 owner 走原生信号排空、真实保存回执与房间屏障；失败或未知不能强停、清空 capture 或宣称已保存。TLS/WSS 及运行验收见 [可选服务说明](../../server/docker/COLLABORATION.md)，源码存在不等于运行资格。

## 8. 旧代码迁移与兼容

2026-10-12 `peanut-admin-code` 的已观察基线：`peanut.rich-text` 有 `pa_rich_text_document`、`revision`、`document_json`、`collaboration_state`、CRUD/软删除、300 秒 HMAC 协同票据和 Tiptap/Yjs/Hocuspocus 客户端；但未观察到完整 Hocuspocus Node 服务、业务 owner 授权、PHP 内部持久化协议、可靠撤权/保存屏障。`peanut.file` 已有 `file_key`、资源/素材选择器。旧富文本页面仍可单人编辑，不能当完整实时系统。

迁移原则：**新增 `peanut.document` 所有的新文档表，不重命名、改写、删除旧迁移或直接清空旧文档**；盘点旧数据 tenant、关联消费者、修订、CRDT 二进制是否有效并预演迁移。只有经业务所有者明确匹配且租户一致时才迁入新正式文档；无法识别 owner 的旧文档保持只读隔离、出报表供人工认领，不偷用创建人为默认 owner，不对整个租户开放读写。

旧 `peanut.rich-text.document.*` 对已知消费者安排受控兼容期：先保留读或适配到新 Document API；新写流按业务归属迁走，并禁止编辑同一文档的两套持久化。先盘点下游 APP 对 API/组件的使用，再决定弃用版本与升级说明；依赖声明、模块权限和菜单均随迁移修改，旧固定 release/scaffold 快照不回写。任何迁移应有备份、幂等校验、逐租户数量/哈希比对和恢复/拒绝策略；Code 当前部署/单多租户的 APP 升级按既有机制，不发明新 SQL 自动执行后门。

## 9. 最低充分验收边界

1. **文档/业务：**一个 owner 多 slot、一文档唯一 owner，未知归属创建拒绝，业务归档/恢复联动；不依赖文档 ACL，未授权不可读/写/冻结/归档；历史游离文档隔离。
2. **协作：**至少两个不同人员同租户同时修改并按最新 Yjs state 重启恢复；另租户同数字 document_id 不串 Room；只读用户不能发送写入更新。
3. **撤销：**移除编辑权限、离职、业务归档、租户/模块停用、票据过期/重放、Node↔PHP 断联时拒绝新写；在途屏障不可提交旧权限更新；旧凭据/错误请求不泄露正文或授权。
4. **保存/故障：**自动保存 debounce/maxDebounce、ack 前 UI 不显示已保存、重复 store 幂等、CAS 冲突、崩溃重启、失败重试/backpressure、超限 Yjs/正文、保存屏障和固定版本不可变；JSON 派生不得覆盖 CRDT 真相。
5. **资源：**正文保存/迁移仅 `file_key`，过期 URL 和私有文件不得被引用绕过；Preview vs Download/Manage 区分、文档权限变化导致地址重解析；引用索引与版本快照一致。
6. **交付：**Node 不启用时不破坏原 APP 默认启动；启用时 WSS/内部 API/秘密/健康检查和版本兼容可核对；Standalone 与 Multi-tenant 各有正确隔离，后者同 Room id 不跨租户；CLI plan/status 如实反映未安装/已启用/故障，升级与禁用不删除正文。
7. **组件：**富文本粘贴清洗、组件 v-model 单人语义、协同单权威来源、销毁后不重连、异步切租户旧响应不可写回，文件选择器无越权；测试需包含真实 PHP/DB/Node 浏览器端到端，不只依赖 mock 证明。

测试授权、真实环境启动、可见性改变、生产部署、正式发行与客户数据迁移均是独立执行 Gate；当前设计文档**不代表上述验收已运行或得到执行授权**。

## 10. 参考实现资料（非项目唯一合同）

- Tiptap Hocuspocus：<https://tiptap.dev/docs/hocuspocus/server/hooks>（认证、加载、保存 Hooks）
- Yjs 二进制保存：<https://tiptap.dev/docs/hocuspocus/guides/persistence>（Yjs 二进制为持久化主态，JSON 为视图）
- Provider 配置：<https://tiptap.dev/docs/hocuspocus/provider/configuration>。

以上第三方参考只用于实现锁定版本 API，变更不能自动修改本产品的身份、权限和持久化合同。
