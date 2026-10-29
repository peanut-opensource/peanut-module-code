# 模块目录、命名空间与生成器

本页说明当前源码的物理映射；不改变 Module key、数据库表、权限点、HTTP 地址或已发布版本。生成行为由 `ModuleHostLayoutFactory`、Core `ModuleHostLayout`、`ModulePhpNamespace` 与 `ModuleScaffoldGenerator` 负责。已存在模块的 PHP 前缀以自己的 `composer.json` 为准，不从目录反推或使用历史大小写别名。

## 路径与大小写分开判断

宿主装配使用 `app\...`，对应 `server/app/` 内现有的小写目录，例如 `app\common\services`。模块根是小写 `server/app/modules/<vendor>/<snake_case>`；模块 PHP 类型进入 `src/`，类型目录如 `Contract`、`Model`、`Service`、`Controller`、`Validation` 按实际命名空间精确匹配。`database/migrations`、`resources`、`route` 是资源目录，不因类目录采用大写而一起改名。

前端模块目录使用 key 的 kebab-case 合成路径。PHP 测试目录保留 `server/tests/Modules/<PascalVendor>/<PascalModule>`。不能把整个项目一律改成大写或小写，也不能依赖 macOS 默认文件系统忽略大小写来证明 Linux 可加载。

以下表格描述显式选择 `--client=admin-web` 时的映射；默认 `--client=none` 不创建前端，回执的前端路径和包名为 null。表格不是第二份配置，真实生成回归逐项核对；回归执行状态以实际报告为准。

| Module key | 后端根 | PHP 前缀（省略末尾分隔符） | 管理端根 | 测试根 |
| --- | --- | --- | --- | --- |
| `dcs.product` | `server/app/modules/dcs/product` | `Dcs\Modules\Product` | `web/src/modules/dcs-product` | `server/tests/Modules/Dcs/Product` |
| `acme.customer-record` | `server/app/modules/acme/customer_record` | `Acme\Modules\CustomerRecord` | `web/src/modules/acme-customer-record` | `server/tests/Modules/Acme/CustomerRecord` |
| `official.sample` | `server/app/modules/official/sample` | `PeanutAdmin\Modules\Sample` | `web/src/modules/official-sample` | `server/tests/Modules/Official/Sample` |

## 作者入口

在已配置的应用 `server/` 目录执行 `php think module:create dcs.product --vendor=dcs` 创建后端模块；需要管理端贡献时显式加 `--client=admin-web`。当前命令只支持 `none` 和 `admin-web`，不因其他客户端目录存在就假称生成器支持。`dcs.product` 和参数 `dcs` 均为小写标识；`--vendor=Dcs` 不合法。输出中的命名空间、后端/前端/测试路径与包名应作为下一步装配输入；不要把旧 `server/app/Modules/Dcs/Product` 或 `app\Modules\Dcs\Product` 手工复制回来。

骨架默认只建立 Provider、清单声明的路由、资源和迁移说明，`contracts.exports` 初始为空。只有存在真正业务操作时才创建并导出对应合同，不预设空 Commands。显式前端只生成 contribution.ts 和 package.json，不生成空 api.ts 或 views 占位。`Controller`、`Validation`、`Service`、`Infrastructure`、`Model` 在确有类型时创建；CRUD 生成器为选定实体写入它实际需要的目录。前端贡献类型来自 `@peanut-admin/vue`，不是已经退出当前依赖组合的 `@peanut-admin/admin/core`。

修改模块源码后，仍按既有顺序更新适用 API/SDK、模块制品摘要、`plugins.lock` 和应用模板清单；不能只改生成物或关闭摘要检查。发布快照、历史迁移身份和已部署实例不由这份说明自动迁移。

## 回归入口

`server/tests/Unit/ModuleScaffoldCapabilitiesTest.php` 验证后端默认、显式管理端、非法客户端、原生 Composer 失败后的回滚，以及生成的结构测试能够执行。结构测试明确不代替业务权限验收；SECURITY.md 是后续真实业务的测试合同，不是空驱动或固定成功用例。

`php scripts/check-source-layout.php --root=<Code根> --root=<PHP Core根>` 使用 Git 路径和 PHP tokenizer 检查生产类型与文件大小写，不依赖大小写不敏感的文件存在判断。它不加载业务、不连接数据库，也不替代 Composer、浏览器或 Linux 运行验证。

`server/tests/Productization/ModuleScaffoldLayoutTest.php` 实际生成表中三个模块、执行原生 Composer 校验、校验清单与前缀并独立加载 Provider，核实没有虚构的公开业务合同；临时目录必须是所属 checkout 的 `.local/tmp`。`scripts/tests/source-layout-test.php` 验证检查器会拒绝错误导入和文件名、同文件隐藏类型，并区分显式加载工具和历史快照。原 `ModuleCreateCommandTest.php` 继续负责完整框架 CLI/Vite 路径，运行它仍须合法应用环境。
