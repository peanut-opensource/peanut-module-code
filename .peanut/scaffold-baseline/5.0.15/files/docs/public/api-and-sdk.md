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

## 部门目录分页合同

`GET /adminapi/dept/lists`、`/all`、`/leaderDept` 返回 `{lists, ancestors, count, pageNo, pageSize, hasMore}`，替代原全量数组合同；旧调用缺少明确模式或分页参数返回400，所有自带页面与生成类型同步使用此合同。权限仍为原 `core.department.read`，目录结构不授予成员或业务数据权限，负责人姓名不会自动解析为成员绑定。

三个入口都必须传 `mode`、`pageNo>=1`、`pageSize=1..100`。未知字段、溢出、冲突过滤明确拒绝。`mode=children` 必须传 `parent_id`（0为虚拟根），只分页当前父级的直接子节点；`mode=search` 对当前租户目录按 `name`、`status` 全局过滤后分页，不能与 parent_id 混用。名称是数据库当前排序规则下的字面子串，`%`、`_` 不作为通配符。`status=1/0` 精确匹配 active/disabled，不把 archived 当 disabled。`mode=resolve` 使用逗号分隔的正整数 `ids`、第一页、至多 pageSize 个ID，按真实ID恢复已选关联路径，不能混名称/状态/父级过滤。`leaderDept` 仅支持 search/resolve，保持 active 平面候选含义。

`lists` 是本页真实命中行，`count`/`hasMore` 只统计同一查询范围；额外 `ancestors` 不计入页数。按 `(sort_order,id)` 稳定排序；每页最多100个命中行，祖先按现有最大深度10分层批量补齐并去重，缺父、跨租户父、环和超深路径报409。COUNT和SELECT不承诺并发修改下同一数据库快照。客户端仅组装已请求页面及祖先，下一页和下一层由用户显式加载，不暗中拉取全目录。

节点分别声明 `realStatus`、`structuralOnly`、`selectable`、`hasChildren`；不要把未加载孩子当作叶子。`all` 的浏览允许 disabled 结构容器通向 active 子部门，搜索候选仅为 active；disabled 或仅补路径的祖先不可选择，展开与选择分开。archived 永不作为 active 候选或有效祖先；resolve 可展示已归档的原关联但标不可选，不能自动删除或重定向保存的ID。管理目录中的真实 disabled 命中仍保留原管理动作权限。两个选择器可恢复离页已选路径，显示不可选历史路径，失败不清空关联；筛选、身份切换或组件销毁使旧请求失效。写入仍由原服务校验父状态、权限、revision、深度及防环。
