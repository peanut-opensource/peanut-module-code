# API 合同与 SDK

HTTP 合同的源由实际路由清单、应用 metadata 和各模块的 `api/metadata/openapi.php` 共同组成。生成物包括：

- `server/generated/openapi.json`：OpenAPI 3.0.3 合同；
- `server/generated/api-catalog.json`：路由、负责人、授权、错误和合同完整度目录；
- `web/src/generated/openapi.d.ts`：全应用 TypeScript 类型；
- `web/src/modules/<module>/generated/openapi.ts`：模块自己的类型化调用入口。

OpenAPI 的 `info.version` 从同一源码树的 `release-versions.json#source_product_version` 生成。已安装的旧版本应阅读其发行包内合同；开发分支的最新合同不会把旧实例文档自动判为过期。

安装 `server/composer.lock` 与 `web/pnpm-lock.yaml` 的依赖后，刷新合同：

```shell
php scripts/generate-api-contracts.php
```

只检查结构化源与真实路由，不写生成物：

```shell
php scripts/generate-api-contracts.php --check
```

重新生成到隔离目录并与版本化产物比较：

```shell
./scripts/check-openapi
```

接口源变化而未刷新、缺少派生文件、模块接口删除后遗留旧 SDK，都会使检查失败。生成失败时不得继续发布旧产物，也不能只改时间戳或版本字符串冒充刷新。

`GET /adminapi/peanut.document.versions` 和 `DocumentQueries::versions` 只返回获准文档的已提交版本摘要，每页重新按持久 owner 核验 active 的 view 或 archived 的 archive.read。默认 limit 为 20，范围 1–100；可选正整数 before_revision_no 以版本号倒序向更早版本翻页，无总数。摘要不授予正文、文件、下载或恢复权限；选中版本仍走原查询和命令核权。查询拒绝外层事务，避免将未提交版本列为历史。自定义 DocumentQueries 实现须实现新增 versions 方法；现有方法及生命周期协议不变。

模块可以保留真实动态语义的字典，例如扩展属性或配置映射。固定返回对象应列出字段、类型、nullable、枚举和说明。公开合同不得包含数据库凭据、签名密钥、完整 Webhook 请求载荷、远端响应体或内部异常文本。

OpenAPI JSON 可交给支持 OpenAPI 3.0.3 的阅读器构建可读页面；发行站点和 SDK 必须使用同一份生成物及同一产品候选。

## 独立权限包与 API 关联

Identity 统一管理系统、模块及实例自建权限包，`source` 分别为 `system`、`module`、`custom`。菜单、按钮各引用一个稳定权限 key，角色通过既有 `permission_keys` 授予同一 tenant/platform 安全域内可用的包。API 自动登记来自同一生成目录，身份 `api_id` 由 HTTP 方法、规范化路径模板及应用入口生成，与权限包或菜单无关。权限包和 API 为多对多，定义及授权边界见[身份与授权](../architecture/identity-organization-access.md#3-部门岗位角色和数据范围)。

| 入口 | 合同 |
| --- | --- |
| `GET /platformapi/permission/bundles` | `audience=tenant\|platform`、`page_no`、`page_size`、`search`；返回权限包 `key/name/module_key/source/audience/status/revision/configured/api_ids` |
| `POST /platformapi/permission/bundles` | 使用 `key`、`name`、`audience` 创建实例自建包，初始 `status=active` |
| `PUT /platformapi/permission/bundles` | 使用 `permission_key`、`name`、`status=active\|retired`、`expected_revision` 修改实例自建包；不修改 key 或受众 |
| `GET /platformapi/permission/apis` | `audience=tenant\|platform`、`page_no`、`page_size`、`search`，另可按 `status=active\|retired` 过滤；返回 API `id/method/path/audience/module_key/access_mode/status/default_permission_keys/prerequisite_permission_keys` |
| `GET /platformapi/permission/apis/bindings` | 按 `permission_key` 读取一个权限包的完整关联及修订 |
| `PUT /platformapi/permission/apis/bindings` | 使用 `permission_key`、`expected_revision`、`api_ids` 原子替换关联；空数组明确清空，受下述管理入口约束 |
| `GET /adminapi/permission/bundles/apis` | 租户按 `permission_key` 只读查看其可用权限包的关联 |

平台读取要求 `platform.permission.read`，创建、定义编辑和关联修改要求 `platform.permission.api.manage`；租户读取要求 `core.permission.read`。列表返回 `items/total/page_no/page_size`，权限包详情同样返回 `source`。`tenant` 受众的自建包 key 使用 `custom.` 前缀，`platform` 受众使用 `platform.custom.` 前缀，前缀后须有标识；完整 key 以小写字母开头，仅含小写字母、数字、点、下划线及连字符，最多100字符，不能与已有 key 重复。系统及模块包定义只读，自建定义不由模块同步覆盖；租户授权已有包，不开放租户自建入口。

定义编辑与关联保存都携带权限包修订，缺少修订返回 428、修订冲突返回 412，不自动重试；角色授权使用其独立修订。只能新增同受众、活动且受保护 API 的关联，公开或仅认证入口不能通过此接口改为权限入口。已关联的退役 API 可保留展示或显式移除，不能作为新增候选。保存后的 `configured=true` 表示实例已接管该权限包的 API 关联，后续同步保留其选择；包定义和角色授权仍分别维护。

API 的 `prerequisite_permission_keys` 始终返回字符串数组，无前置权限时为空；它由源码声明，关联选择器展示但不能修改。多个关联权限包之间按任一满足判断主要动作，前置权限则必须全部满足，即使重新关联也保留原有同时授权要求。源码路由的 `peanut_permission` 标记主要动作，不能将同一路由的其他必需权限并入任一满足的默认关联。

API 所属模块仍独立校验安装及当前租户开通，跨模块引用不会绕过它。受管菜单可引用同域有效的已有权限包（system/module/custom），实例引用仅替代源码默认菜单门槛，清空恢复默认，不与默认包叠加，也不改变 API 的源码前置权限；存储及同步见[菜单定制](../development/menu-navigation.md)。`GET /adminapi/user/info` 的 `action_bindings` 是 `{page_path, action_key, permission_key}` 列表，包含已配置但当前用户未获授权的项，不限权限包来源。它来自页面下 `A` 类型按钮的动作配置，前端按当前 `page_path` 与真实权限包授权判断；API 关联本身不把 `default_permission_keys` 自动授给用户，也不产生页面动作映射。

为保留当前界面的管理修复入口，`GET /platformapi/permission/bundles`、`POST /platformapi/permission/bundles`、`PUT /platformapi/permission/bundles`、`GET /platformapi/permission/apis`、`GET /platformapi/permission/apis/bindings`、`PUT /platformapi/permission/apis/bindings` 各自必须保留至少一个有效平台权限包关联。若关联替换或自建包退役将移除其中任一 API 的最后一个有效关联，整笔修改回滚并返回 409 `API_MANAGEMENT_RECOVERY_REQUIRED`。该限制只针对上述六个精确方法与路径；普通权限包仍可保存空集合。

## 实例菜单与按钮引用

MT 平台在既有权限面板维护已有租户菜单与按钮的权限引用，读取要求 `platform.permission.read`，保存要求 `platform.permission.api.manage` 并记录平台审计。租户不能修改实例全局配置；原管理端完整菜单编辑仍限 ST。该入口不提供菜单 CRUD，不修改页面路径、父级、组件、源码默认定义或模块身份。

| 入口 | 合同 |
| --- | --- |
| `GET /platformapi/permission/menus` | 无查询参数，返回 `{items,total}`；每项含 `menu_key/parent_key/type/name/page_path/action_key/permission_key/default_permission_key/revision`，`type` 为 `C` 或 `A` |
| `PUT /platformapi/permission/menus/binding` | 使用 `menu_key`、`permission_key: string\|null`、`action_key: string\|null`、`expected_revision` 保存并返回更新后的单项 |

`permission_key` 是当前有效引用，`default_permission_key` 是源码默认或旧菜单已登记默认，无法确认时为 null。显式选择同域启用的已有包（system/module/custom），模块包还须属于已安装模块；`permission_key=null` 恢复默认，默认未知时返回 409 `MENU_DEFAULT_PERMISSION_UNKNOWN`，不会猜测或清空授权。菜单只使用一个有效包，实例引用替代默认门槛；角色授权、API 前置权限、模块开通及数据范围仍各自核验。

`C` 页面保存时 `action_key` 必须为 null；`A` 按钮须有父页面和非空动作标识，空标识可首次填写，已有标识不能改。列表中 `C.action_key` 为空串，`A.page_path` 来自其父页面。动作标识须与页面代码一致；同页同动作引用不同包返回 409 `ACTION_PERMISSION_BINDING_CONFLICT`。

`revision` 是当前投影行的 SHA-256 字符串，客户端按不透明值原样传入 `expected_revision`，不把它当整数或包修订。缺少修订返回 428，失配返回 412，不自动读取新值重试；字段类型、动作约束或权限包候选不合法返回 400 `MENU_BINDING_INVALID`。这两个菜单入口不在上节六个 API 的最后有效关联保护范围内，其映射仍可由既有 API 关联入口修复。

## 部门目录分页合同

`GET /adminapi/dept/lists`、`/all`、`/leaderDept` 返回 `{lists, ancestors, count, pageNo, pageSize, hasMore}`，替代原全量数组合同；旧调用缺少明确模式或分页参数返回400，所有自带页面与生成类型同步使用此合同。权限仍为原 `core.department.read`，目录结构不授予成员或业务数据权限，负责人姓名不会自动解析为成员绑定。

三个入口都必须传 `mode`、`pageNo>=1`、`pageSize=1..100`。未知字段、溢出、冲突过滤明确拒绝。`mode=children` 必须传 `parent_id`（0为虚拟根），只分页当前父级的直接子节点；`mode=search` 对当前租户目录按 `name`、`status` 全局过滤后分页，不能与 parent_id 混用。名称是数据库当前排序规则下的字面子串，`%`、`_` 不作为通配符。`status=1/0` 精确匹配 active/disabled，不把 archived 当 disabled。`mode=resolve` 使用逗号分隔的正整数 `ids`、第一页、至多 pageSize 个ID，按真实ID恢复已选关联路径，不能混名称/状态/父级过滤。`leaderDept` 仅支持 search/resolve，保持 active 平面候选含义。

`lists` 是本页真实命中行，`count`/`hasMore` 只统计同一查询范围；额外 `ancestors` 不计入页数。按 `(sort_order,id)` 稳定排序；每页最多100个命中行，祖先按现有最大深度10分层批量补齐并去重，缺父、跨租户父、环和超深路径报409。COUNT和SELECT不承诺并发修改下同一数据库快照。客户端仅组装已请求页面及祖先，下一页和下一层由用户显式加载，不暗中拉取全目录。

节点分别声明 `realStatus`、`structuralOnly`、`selectable`、`hasChildren`；不要把未加载孩子当作叶子。`all` 的浏览允许 disabled 结构容器通向 active 子部门，搜索候选仅为 active；disabled 或仅补路径的祖先不可选择，展开与选择分开。archived 永不作为 active 候选或有效祖先；resolve 可展示已归档的原关联但标不可选，不能自动删除或重定向保存的ID。管理目录中的真实 disabled 命中仍保留原管理动作权限。两个选择器可恢复离页已选路径，显示不可选历史路径，失败不清空关联；筛选、身份切换或组件销毁使旧请求失效。写入仍由原服务校验父状态、权限、revision、深度及防环。
