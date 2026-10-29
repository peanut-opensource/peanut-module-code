# Peanut 开发规范 v1.0

状态：已批准。适用 ThinkPHP 8 / PHP 8.3、Vue 3 / TypeScript、uni-app（Vue 3）。

本版执行一次性全量迁移：所有现行自有源码、生成器、有效测试和开发说明共同遵守，不仅约束新增文件。旧 Model / Repository 公开导出立即收回，不建立兼容别名或永久白名单。公开开发规范的唯一正文随 Code 交付，Project 仅保留内部治理、批准记录、执行状态及引用。规范生效不等于实现和验收完成。

## 1. 范围与事实源

采用框架原生能力，不新增竞争容器、ORM、路由、队列、异常体系或万能配置语言。保留实际模块标识、领域语义及安全域；仅规范格式不改变表名、已执行迁移身份或客户数据。历史发行快照、第三方依赖及原始证据不按现行格式重写。正式发布、可见性、生产部署和真实数据操作需要对应授权。

公开规范的适用行为、默认、例外和检查方式在本文件维护；详细既有应用合同在本目录对应专题中维护。API metadata / 路由是接口资料来源；module.json 是模块能力来源；composer.json 是 PHP 映射来源；package.json 是客户端入口和包声明来源；各原生锁是精确装配记录。重叠字段由生成与一致性检查约束，不按文件日期猜优先级，不手改摘要或锁来掩盖不一致。

规范可执行部分由已提交工具配置、生成器和回归共同落实。外部规范后续变化不会自动改变本版本。公开资料不得要求消费者读取维护者私有 Project、桌面路径或秘密。

## 2. 职责与依赖

| 位置 | 唯一职责 |
| --- | --- |
| PHP Core | 技术上下文、Scope、授权原语、模块和迁移协议、框架接入；不反向导入 Code 业务 |
| Web Core | 无业务请求设施、通用 UI、Vue / Nuxt / uni-app 适配与测试辅助 |
| Code | 产品装配、业务模块、各客户端、生成、安装和升级 |
| Project | 内部治理、决策、状态和资源协调，不是产品运行或模块开发依赖 |

默认依赖方向是入口 → 所属模块公开用例 → 本模块数据访问 → Core / 框架。先按模块归属，再按真实职责组织。跨模块只用明确导出的公开能力，不直接访问私表、内部 Model、Repository 或任意 Query；宿主组合根负责实例装配，不因此获得业务越权通道。

复用优先级：现有公开能力 → 模块内复用 → 有真实共享需要时提取公共能力 → 确实缺少时新增。简单纯函数不套接口 / 工厂；接口只用于真实替换、跨模块协议或必要测试边界。不机械为每类加 Repository，不以未来可能用到为由生成空包和空层。

## 3. 文件、命名和格式

| 对象 | 统一规则 |
| --- | --- |
| ThinkPHP 宿主目录 | 保留框架及已登记 app 映射的小写目录；同一区内不增加同义变体 |
| 模块根 | server/app/modules/<vendor>/<snake_case>，小写标识 |
| 模块类型目录 | Controller、Validation、Service、Model、Contract、Value、Exception、Infrastructure；按实际用途建立 |
| 独立 PHP 类型 | PascalCase 类型 / 同名文件，PSR-4 精确匹配；一个需独立加载的类型一个文件 |
| 方法和内部属性 | camelCase；框架覆写签名、数据库字段及第三方名称精确保留 |
| 资源目录 | database/migrations、route、resources，不能按类型目录风格一起改名 |
| 前端普通目录 / TS 文件 | kebab-case；固定入口 api.ts、types.ts、contribution.ts 保留 |
| Vue 展示组件 | 多词 PascalCase；正式页面入口 index.vue、App.vue 等保留 |
| composable | useXxx.ts 导出 useXxx，状态和清理边界明确 |
| uni-app easycom | 遵循 component-name/component-name.vue 或显式登记映射 |
| PHP 模块测试 | tests/Modules/<PascalVendor>/<PascalModule>，保持真实测试发现规则 |

宿主 services 与模块 Service 是明确分区，不是自由选择。既有复杂领域的 Application / Query 等有职责的局部结构允许保留，不能只为对称搬动。缩写按词命名，如 ApiClient、SkuCode、UserId；既有 OAuth 及第三方类型保留准确拼写。类型名称、导入、映射、实际路径必须一致，不靠 macOS 忽略大小写。

命名先识别角色，再应用规则。例如展示组件 `OrderList.vue`、组合函数 `useOrderList.ts`、普通工具 `order-status.ts` 各用自己的写法。Nuxt 的 `pages/` 动态参数与 `layouts/` 注册名、uni-app 的 pages.json 路径、语言资源的已登记 `en-US` / `zh-CN` 键属于运行合同，不能仅为满足普通文件名规则改动公开URL或语言键。它们不是给普通组件/工具文件保留旧写法的白名单；重命名前须核实际注册、导入和生成源。

PHP 格式固定 PER-CS 2.0、PSR-4；UTF-8、LF、末尾换行，PHP 四空格，TS / Vue / JSON 二空格。纯 PHP 无结束标签，业务文件声明严格类型；覆写方法保持框架签名。语言语法要求的例外保留。引号、分号和换行由目标工程已提交 formatter / linter 配置唯一决定，不叠加互相冲突的外部配置。

Code四端共用根 `.prettierrc.cjs`，Web入口仅引用；Prettier版本和PHP格式工具在 `tools/quality` 原生锁中固定，跨仓检查使用明确根目录与仓内配置。编辑器读取 `.editorconfig`，前端格式检查/受控写入统一走 `scripts/format-source.mjs`，PHP走既定PER-CS入口；准备和具体命令见[质量工具](../../tools/quality/README.md)。配置存在、保存自动格式化、实际全范围检查通过是三个不同状态，须分别核实。纯格式归一化与业务修改分开提交，历史/生成字节不重排；后续按变更范围检查，不能仅靠口头要求减少冲突。

控制器 XxxController，验证器 XxxValidate，业务动作 XxxService，模型实体名，公开纯查询 XxxQueries，命令合同 XxxCommands。拒绝用新的 CommonService、Helper、Manager 掩盖职责；已有确有语义的名称不机械替换。不按行数强拆文件。

## 4. ThinkPHP 实现合同

Controller 获取原始输入、按动作校验、调用公开业务并映射响应；不承载完整跨模块事务、渠道调用或直接批量写私表。Validator 负责类型、格式、枚举、长度及场景，不用“校验成功”代替字段写权限。Service 负责对象授权、业务状态、不变量、事务和编排，不返回 HTTP Response。Model / 持久化实现负责本模块数据访问，不藏支付、通知等外部副作用。Provider 只登记，不在构造或注册时创建业务记录。

业务服务构造注入；单动作可方法注入。Controller 共享依赖使用受信源码中的 protected string $xxClass 与只读 $this->xx，@property-read 必须准确。复用当前 App；接口须有正式绑定，不接受请求中的类型名，不另建全局容器。App、Request、上下文由正式入口建立；缺绑定、冲突或缺身份明确失败，不能补默认租户。

Model 新记录 / 新操作显式新建或重置，Query 从干净初态开始，Validator 不复用受前一场景污染的实例。常驻任务和 SSR 不缓存跨人员 / 租户快照；上下文和事务在异常后恢复。

公开参数 / 返回类型明确。内部小结果可用精确数组形状；跨模块稳定结果用明确合同或值对象，不导出 ORM。提前拒绝非法状态，注释说明原因、单位、权限、事务与副作用，不重复代码字面意义。TODO、空数组、固定成功和未实现方法不是交付。

外部回调先验证渠道绑定及签名再建受限上下文。HTTP、CLI、worker、导出和直接服务调用执行相同业务授权。事务内事件、提交后动作、可靠异步必须分别定义，不把支付 / 库存藏进模型监听器。

## 5. 模块生成、公开面及声明

Peanut 产品入口使用 vendor.module 两段小写标识。Core 通用解析器接受更多形态不等于产品承诺支持。所有后端 / 前端 / 测试路径在写入前整体校验，路径与大小写冲突、前缀占用、父路径或符号链接越界均拒绝，失败不得留下半成品。

模块 PHP 前缀由自己的 composer.json → src/ 声明，宿主正式注册。dcs.product 对应 server/app/modules/dcs/product、Dcs\\Modules\\Product、web/src/modules/dcs-product、tests/Modules/Dcs/Product；这不是对下游产品创建业务的授权。

骨架只创建选定能力真正需要的文件。纯后端不生成空前端，纯查询不强迫带空 Commands。模块安装、租户开通、人员授权和代码发现分开。Module 与 Plugin 现有协议继续使用，不新增另一 manifest。

contracts.exports 是模块公开面，不是所有可 autoload 类的目录。生产公开 Model / Query / Repository / 持久化实现仍不作为任意可写公开能力导出；一般业务协作使用公开查询、命令、稳定结果或技术协议。当前敏捷阶段允许同库模块通过ThinkPHP模型关系、预加载和JOIN进行有tenant隔离、最小字段的只读关联；条件见[模块调用规则](../architecture/module-development-delivery.md#3-模块之间怎样调用)。这不授权保存、修改或删除来源模块数据，不暴露其敏感字段或可写ORM对象，也不要求新增View/ReadModel/独立查询服务。内部模型仍由所属模块维护，收回导出不等于删除数据库实现；跨模块写入仍由所属模块业务入口承担。

## 6. 输入、数据、事务与文件

写接口拒绝未声明字段；tenant_id、角色、创建者、余额等保护字段不能通过批量赋值写入。缺字段表示不修改；null / 空串仅按字段合同允许清空。类型转换明确声明，不宽松强转任意输入。当前统一响应 / 错误机制复用；权限、服务端、网络和解析错误不能变成空列表或“找不到”。对象隐藏 404 / 显示 403 按安全入口合同执行。

列表、详情、关联、计数与导出共用获准范围。筛选、操作符、排序和字段投影显式声明；分页用既有有界公共合同，排序需要稳定键。导出可有独立获准字段集，但不能扩权限；超出同步范围走既有任务，不静默截断。

平台级保留期维护与普通租户业务分开：ImportExport仅处理已结束且到期的任务，标记expired并清文件引用，不删物理文件；Integration保留payload/evidence截止语义，使用固定有上限批次，每批独立事务，可中断后安全继续。两者复用既有可信系统维护上下文和审计，不授予普通租户/普通HTTP调用权，不自动启用HTTP或定时入口。清理审计只写必要元数据及处理数量，不含业务正文；当前不要求另建归档备份系统。

现行维护入口为部署管理员显式执行的顶层CLI：`php think import-export:expire --limit=100`，以及 `php think integration:purge-expired --payload-cutoff=2031-01-01T00:00:00.000Z --evidence-cutoff=2030-01-01T00:00:00.000Z --limit=100`（日期必须由维护策略确定，示例不可直接用于业务环境）。每次只执行一批，默认100、上限1000；Integration对载荷、子尝试、父投递分别限额，子记录未删完时保留父记录，相同截止时间再次调用继续，三个计数全零表示当前无可处理项。复用ContextualCommand建立的实例系统身份，拒绝已存在的租户/平台HTTP上下文、无关命令和非CLI环境；不注册租户调度入口。成功审计与该批数据变更同事务，失败回滚并记录安全原因/零处理数；Integration拒绝外层事务，不能以大事务包住多批。

最外层用例拥有事务，参加模块共用连接、不自行提交；独立入口明确事务策略，真实嵌套行为按所用 ORM 验证。提交后通知失败不能伪装成可重复整个写入的普通失败。外部副作用有幂等、状态和补偿，不声称 DB 事务可撤外部效果。金额 / 数量用领域精确类型，不能浮点结算或全站默认两位小数；时间沿 UTC 和明确接口格式，不改历史值。

实体显式声明软删除模式，不由 delete_time 推断。普通查询排除已删项；回收、恢复、永久删除分别授权，仍保留 Scope。恢复检查唯一性、关联和状态，不覆盖后来记录、不隐式恢复前置对象。批量原子或逐项结果由动作合同声明；未声明不暴露批量写。删除后编号复用、保留期和重复结果由实体合同决定，不让生成器猜测。

文件沿现有稳定引用，不存签名 URL；共享引用的软删 / 恢复不得误删文件。永久回收单独核授权、引用、保留条件。受限缓存先授权，包含影响结果的身份 / 租户 / Scope / 修订；公共缓存不含个人数据，也须区分实际内容不同的 Host / 站点 / 语言。审计与普通日志分开，不因失败关闭安全审计，不输出秘密或敏感原始请求。

## 7. Vue 与 TypeScript

页面组合路由、状态和展示，不重复拼 URL、令牌、刷新或重试。展示组件显式 props / emits / slots，不偷读租户或硬编码业务 API。有状态复用使用 composable，纯转换用函数，UI 复用用组件；不新增 mixin。Pinia 只保存真实跨页共享状态，不容纳所有临时表单。

现行 Vue 统一 Composition API 和 TypeScript，普通 SFC 用 script setup lang=ts；真实 JSX 渲染组件可用类型化 setup/render，不以形式转换破坏渲染。computed 只派生值，watch 才做明确副作用；不改 props，使用稳定 key，富文本走清洗边界。API 类型优先使用生成 SDK；unknown 先校验后收窄，禁止 as any、双重断言、ts-ignore / ts-nocheck、降低 strict 或排除现行业务目录隐藏错误。对象合同用 interface，组合 / 联合类型用 type，不造空接口。

加载、空结果、失败、成功分开；提交有防重复状态，最终幂等由后端负责。旧请求不得覆盖新状态；卸载、切租户 / 用户 / 筛选后取消或隔离过期请求。清理监听、定时器、订阅。可分享筛选进 URL；短暂表单局部化，持久草稿按用户 / 租户 / 模块 / 草稿分区，退出不清理其他独立身份。

PC 普通业务 CSR，已登记公开展示页才 SSR；公开 HTML / payload 无个人凭据。SSR 按请求隔离，不用模块级单例存个人状态。SPA 使用完整静态产物和同一 PHP API，不运行 PC Node，不能拆 hybrid/public 冒充 SPA。现行根路径、admin / platform 等边界保留，主版本不因规范迁移升级。

沿现有 UI 和主题，不替换 Element Plus。优先 token / 变量，样式 scoped、CSS Modules 或模块前缀，不污染全局。业务文件 / 组织选择器留业务模块，纯控件可入公共 UI。交互提供清楚标签、禁用、焦点和错误状态，按平台验证可访问性。

### 外部响应必须先校验再收窄

网络泛型、类型断言和生成类型均不校验收到的字节。适配器从unknown开始，逐项核实实际消费的对象/列表形状、状态字面量、可选字段与嵌套元素，再返回声明类型；只检查Array.isArray不证明列表元素正确。可扩展的未知元数据保持unknown，不将其提升为任意业务类型。

```ts
type InstallationState = 'uninstalled' | 'installed' | 'blocked';

function readInstallationState(value: unknown): InstallationState {
  if (value === 'uninstalled' || value === 'installed' || value === 'blocked') {
    return value;
  }
  throw new Error('Invalid installation state');
}
```

反例：`String(value)` 后检查枚举，再把原value断言成枚举，会让数组或自定义对象伪装成字符串；`value as unknown as Result`只压过类型错误。修复不能把损坏响应变成空列表或成功状态，也不能改变既有合法响应、凭据传递和网络错误语义。验证使用真实消费者源码和真实依赖声明，覆盖合法值、null/数组/错误类型及失败传播；格式通过不代替这项验证。

## 8. uni-app

对象是 Vue 3 uni-app，不引入 Vue 2、uni-app x / UTS 或把后端换成 uniCloud。保留实际 src 根及 App.vue、main.ts、pages.json、manifest.json。页面由正式 pages.json 或其生成源登记，组件不自动成为页面。新增 / 删除页面同时处理路由、导航、测试。static 只放可公开媒体，不放源码、秘密、依赖或闲置大文件。

区分页面与组件生命周期：路由参数、显示、返回、卸载使用适用 uni-app 入口，不把 onMounted 当作每次返回都会发生。请求、存储、导航、扫码、相机和文件选择走稳定适配器；独有代码集中条件编译，不在业务各处分叉。DOM / Node 库不能进入非 H5 路径，运行时 if 不保证可编译。

按需引用组件，easycom 目录、类型与实际包一致。会话沿既有安全域和撤销协议，普通本地存储不是秘密保险箱。权限拒绝、用户取消、设备不支持分别处理。每次说明实际验证的平台；H5 成功和已有构建命令不证明小程序 / App 已通过。

## 9. 生成、定制与升级

派生 API 类型、索引、摘要从唯一源生成；业务起始样板创建后可编辑，再次生成识别冲突，不覆盖；上游受管代码走正式升级和归属机制。不能把整个后端或前端都标自有，也不能把所有生成文件都任意覆盖。

以已有分类 / 文章和自定义合成模块作样板，不另造 CRUD 框架。每个业务声明所有者、安全域、动作、输入输出、权限、删除 / 批量 / 唯一 / 关联语义及文件影响；缺业务输入不猜实现。模板变动实跑生成、原生校验、自动加载、前端类型 / 组件、行为、重复 / 冲突和说明一致性。黄金例子独立于生成器算法，故意错误必须被拒。

安装仅首次初始化；升级用独立机制和已安装入口先核完整文件 inventory、SHA-256 与版本/来源/目标身份。保护自有业务、定制、数据、权限、秘密、公共 / 私有上传。真实迁移和成对恢复是单独验收；不以文件恢复、fresh 或合成 Host 回执替代。

### 产品使用者与维护者的职责分开

模板源码快照由 `scripts/scaffold-runtime/scaffold.conf.json` 的 `source_snapshot` 过滤，最终清单由正式 inventory 入口生成并按 `inventory_omissions` 省略指定条目。后端 `server/.peanut`、`server/plugins`、`server/plugins.lock`、`server/resources/project-resources.json` 及配置中列明的旧脚本投影属于运行产物，不作为模板源码。`packages/core-web/*.tgz` 与 `packages/core-php/*.zip` 仅交付本次 Core 身份及原生依赖声明明确引用的归档，保留 `--with-local-core` 的真实输入，不继承陈旧归档。

使用者由CLI从固定模板包首次创建APP，再按APP原生锁准备真实Core与第三方依赖、必要构建和配置，通过网页向导首次安装并确认访问后同步产品自己的Git；不要求维护者源码仓、私有Project或人工prepare。模板创建载荷保留开发源码、模块、构建配置、锁、迁移、安装/升级入口、部署模板、公开资料及许可证，不复制APP的vendor、node_modules或缓存。模板中独立的tooling域仅封存源码升级工具及其锁定生产依赖，不进入APP创建载荷、受管树或APP运行依赖；CLI验证后调用同一原生升级器，不隐式安装依赖。既有APP保留原目录、专库、Git和定制，不默认重建。

首次安装预检环境、配置、发行身份和依赖，解析模块依赖，执行适用初始迁移及账号/默认租户初始化，再健康检查并登记版本。Standalone不强制平台运营账号；重复执行不得清库、重建账号或重置系统。缺依赖给出安装提示，不直接落入类加载错误。安装模块、开通租户和授予人员权限是独立动作。

上游源码升级由CLI固定模板并调用模板内原生 `scripts/scaffold-upgrade`，校验完整 inventory、逐文件 SHA-256 与版本/来源/目标身份，不能先运行未校验包内代码。产品 Git 更新使用 `scripts/deploy-update.sh`，不能与 CLI 模板源码升级混为一步。升级复用baseline三方比较、归属、冲突、锁、计划和文件恢复；源码apply不安装依赖、不构建客户端、不迁移DB或部署。依赖、构建、迁移和业务确认按本次变化另行处理。

产品在自己的Git `dev`开发，人工审核定版到`main`及tag。生产首次从固定main提交在空目录、空库准备依赖、构建和配置，经网页向导安装；既有实例在原目录更新固定main，按变化处理锁、构建、pending迁移、缓存/进程和业务确认。该协调尚未完整接通，具体保护与缺口见[产品Git交付](../public/application-release.md)。旧包更新器和固定 Git 包准备链已退役；`git pull`或单独迁移命令不等于完整升级。

受管文件的路径重命名属于 Scaffold 升级合同，必须由目标 manifest 显式声明旧路径、新路径和同一 owner；禁止用名称、内容相似或 Git rename 猜测替代声明。旧路径未定制且目标空闲时可自动迁移；任一侧存在下游内容时进入同一 plan 的显式冲突决策，application manifest 已占用目标、归属不一致、链式/循环声明或计划后路径漂移均拒绝自动接管。APP-owned、第三方模块和秘密继续不进入自动写集。

代码恢复与数据恢复分开；重复或未知结果先核现场，不伪造不可逆DDL的down，不盲切不兼容旧代码。激活前按同计划恢复数据库、公共/私有文件及受管代码，activation_started后不得自动回灌旧备份。SSR运行依赖由目标环境按锁安装和构建，不能删除.output中的依赖后声称SSR仍完整可运行。当前实现入口及配置区别见[源码导读](current-code-lifecycle.md#9-应用生成安装与升级)。

维护者负责固定产品、包、模块、API/事件及迁移身份，先完成所选组合验证，再按授权发布可取得的Core和产品。脚手架、PHP Core、Web Core各自独立发行和升版，分别按自身变化决定SemVer；脚手架更新不要求Core跟随发版。Web Core六包及其内部依赖仍使用同一个Web Core发行版本。生成 APP 自有版本、模块/API/事件/schema 身份仍按各自合同维护，不重写历史发行。日常联合开发直接使用显式选择的本地Core源码与所需watch，详见[日常入口](current-code-lifecycle.md#9-应用生成安装与升级)；阶段候选组合才消费固定源码commit/tree、精确包版本和摘要的本地Core候选，使用同一模板归档与创建/升级合同，明确保留development来源及不可正式发行标记。Composer与npm按各自版本语义验证，不手改锁摘要、不覆写既有版本/tag。公开使用说明不授予发包、生产或客户数据操作权限，也不把完整产品资格附加成每次纯规范整理的任务。

PHP Core与Web Core由其维护者按固定main来源、注释tag、已获准渠道和实际发布结果正式交付；下游APP正式交付只消费适用的已发布依赖，不需要执行上游发包任务。源级发布操作由源码仓的docs/maintenance入口和Core发行说明维护，不随APP教程复制。缺少公开固定依赖时停止相应正式交付；开发候选按Code源码仓`docs/maintenance/release-producers.md`中的冻结合同生成和验证，不以内部压缩包冒充渠道已发布；该合同不随生成APP交付。

正式版 tag 为 `vX.Y.Z`；经单独授权的演练用 `vX.Y.Z-rc.N`，Web 六包与内部依赖保持同一 `X.Y.Z-rc.N`，npm 预发行使用 `rc` dist-tag。首次建包可能遗留指向演练版的 `latest`，须核对并清理后才可将无版本安装视为正式渠道。公开版本不可复用；演练版本不进入正式产品锁。正式交付前核两个Core各自公开版本、内容/摘要与精确来源，生成原生正式依赖锁并复核最终组合；稳定正式交付要求稳定Core。候选组合的验证不证明最终公开包已等同采用，不能省略受影响的最终资格与人工Gate。

## 10. 规范、知识和 AI

一主题一正文，不维护规范 / 最佳实践 / 注意事项三份相同内容。设计决定记录理由和影响；经验标来源与适用版本；状态、下一任务、阻断各有唯一位置。外部文件变更不自动更改本规则。公开使用者无需私有维护资料；内部资源和个人信息不进入公开开发说明。

AGENTS 只定位规范版本、职责和真实检查入口。每项任务写清目标、归属、参考、允许写入、业务合同、验证和交付。顺序：核基线 → 找复用 → 最小文件计划 → 证实缺陷或写验收 → 实现 → 同步派生资料 → 验证 → 审阅 → 提交。不得因换模型重选已经固定的目录和写法。

可读性看调用链、单一业务负责人、明确类型 / 错误 / 状态和真实复用，不看文件或抽象数量。没有假成功、空身份、删断言、假摘要或隐蔽 TODO。

## 11. 检查与合入

格式配置、精确路径、PHP 冷加载、TS / Vue 编译、模块边界、生成样例和文档引用都要可重复执行。修复先保留失败，再验证。历史负例夹具不是现行写法范本，不能格式化其故意无效输入来改变测试语义。

纯文档核来源 / 链接；路径和类型核所有导入与冷加载；业务核正常 / 拒绝 / 范围 / 事务；Vue 核交互与相关 SSR；uni-app 核受影响平台；模板核实际生成和覆盖拒绝；依赖核锁、空消费者和集成；安装 / 升级核首次、重复、冲突、保护和真实恢复。静态结果不得冒充真实 DB / 浏览器结果。

Git 保留并发，不强推、不改第三方，发行快照与已应用迁移不重写。格式变更不是发布、部署或清库授权。测试按所属工作树使用临时文件和明确资源，恢复前核身份。工具拒绝保留原错误，禁止换工具或包装绕过；只暂停对应工作。报告区分源码修改、通过、失败、未执行、提交、制品发布与部署。

## 12. 采纳来源

本规范是 Peanut 对公开材料的原创适配，不宣称大厂所有项目都采用同一目录。以编制时采用的条款和本项目锁定配置为准。

- ThinkPHP 8.x CONTRIBUTING：PER-CS 2.0、PSR-4 与测试；https://github.com/top-think/framework/blob/8.x/CONTRIBUTING.md
- PSR-4：https://www.php-fig.org/psr/psr-4/ 。精确映射不等于全目录强制同一大小写。
- 腾讯 TDesign Vue / Starter：组件、逻辑、示例、测试与资料维护；https://github.com/Tencent/tdesign-vue-next/blob/develop/CONTRIBUTING.md
- 字节 Arco Vue：变更、测试及 API 文档生成；https://github.com/arco-design/arco-design-vue/blob/main/CONTRIBUTING.zh-CN.md
- 阿里 f2e-spec：TS、文档和工具配套；https://github.com/alibaba/f2e-spec
- 腾讯 TDesign uni-app：真实集成与按需引用；https://github.com/Tencent/tdesign-miniprogram/tree/develop/packages/tdesign-uniapp
- DCloud 工程、页面、路径与平台文档：https://uniapp.dcloud.net.cn/tutorial/project
- Vue 组合函数 / TS / SSR：https://vuejs.org/guide/reusability/composables.html

不复制外部仓的依赖版本、分支、发布权限或 CI 政策，不因参考库更换 UI、PHP 框架或移动端运行时。
