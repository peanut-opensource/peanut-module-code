# 项目工具检测与可选本机配置

本入口只选择已经安装的工具，不安装 mise，不改全局 PATH，不复制 vendor/node_modules，也不自动下载、升级、测试、启动服务或发布。首批验证范围是现有 macOS 本机；不宣称完成跨平台验证。

## 自动检测与复用

当前 `peanut.project-env.v2` 不要求每个工作区先人工登记 host。`requirements` 读取项目声明；`doctor` 按本次 `--tools` 范围只读验证实际工具；`ensure` 只建立或复用本地派生入口；`run -- <原生命令>` 在同一入口内 ensure 后执行。已有工具、manifest 约束、工具文件身份和配置不变时复用 prepared 状态，不重复安装依赖。

选择顺序是显式 `--host` → 已存在的本地 host → 可选共享 `PEANUT_TOOLCHAIN_PROFILE` → 自动发现已安装工具。自动发现支持 `PEANUT_TOOL_NODE`、`PEANUT_TOOL_PHP`、`PEANUT_TOOL_PNPM` 等明确覆盖、PATH 和已有的 Corepack pnpm 缓存；npm/pnpm 必须使用所选 Node 解释器及真实 CLI 文件，不能把 shell 包装器交给 Node。错误的显式配置直接拒绝，不偷偷回落到另一套工具。缺失工具只报告，不自动安装。

```sh
python3 scripts/project-env --tools php,python3 doctor
python3 scripts/project-env --tools php run -- php --version
python3 scripts/project-env --tools node,pnpm run -- pnpm --dir web install --frozen-lockfile --ignore-scripts
```

最后一条明确请求安装依赖；不是 doctor 或 ensure 的隐式行为。`--root` 选择声明来源，`--state-root` 选择派生状态目录；两者不同时建议显式传绝对 state root，避免相对路径按错误项目解析。普通应用不需要维护者私有资源登记；数据库配置、真实实例目标与运行期授权仍由应用的原生安装/升级入口验证，不能因工具自动检测而跳过。

## 唯一来源

- `tools/toolchain.json`：随项目提交的推荐工具版本。版本差异只提示；实际 PHP/Node 约束来自项目 manifest，npm/pnpm 的 Node 要求来自包自身 manifest，锁格式与来源由原生包管理器保持。`web/package.json` 的 `packageManager` 是 pnpm 推荐版本，不单独构成拒绝范围。使用固定 `scripts/project-composer` 时保留其版本和 SHA-256；config 选择其他已安装 Composer 时，在本机 host 中绑定其路径、版本和文件 SHA-256，防止配置文件绑定的文件被替换。该本机摘要只绑定当前文件，不证明官方来源。
- `.local/toolchain/host.json`：本机私有工具路径、解释器与 CLI 文件绑定、缓存路径及 release/backups/Core 目录设置。由 `tools/toolchain.local.example.json` 提供基础格式，不提交真实桌面路径或凭据。Core 参数可相对所选 Code 根输入，`core.php`/`core.web` 始终保存绝对路径，固定绑定实际 Core 位置，避免改变 Code 工作目录后按另一基准解析；`.local` 配置不随 Git 合并；普通新工作区可自动检测或复用共享 profile，仅显式本机路径/Core 绑定失效时需要重新 config。commands、caches、extra_path、watch_paths、release 和 backups 路径继续接受相对或绝对值，相对路径统一以 Code 项目根为基准，运行时解析为绝对路径。`peanut config` 在当前 Code `.local/toolchain/cores/php|web/` 下分别保存 Core host 和缓存；Core host 用 `path_base` 记录 Code 根，Core 源码目录保持只读。Core 工具要求从各自的 `composer.json` 或 `package.json` 读取，并由 Code 自己的 `scripts/project-env --root <Core> --state-root <Code> --host <Code-local-host>` 执行，因此不要求 Core 仓有 `tools/toolchain.json` 或 `scripts/project-env`。
- `scripts/project-env`：统一入口；可由系统已安装 Python 3.9+ 启动。项目业务命令仍使用登记的 Python 版本。入口不会解决工具缺失，缺失或版本不符须先由授权的环境维护操作修正。

Code 源码仓不再保存 `packages/core-php` 或 `packages/core-web` 的归档副本。开发端 `server/composer.json` 的 `peanut-admin/core` 是独立 PHP Core 仓库的固定 Git 提交与原生 Composer 锁，源码联调继续使用已有 `core-dev`；不为开发依赖复制 ZIP 到 Code。制作**独立应用发行模板**时，现有 `freeze-core-release` 从独立 PHP/Web Core 仓生成固定归档到指定的仓外 `release` 构建输入，再由 `build-scaffold-release` 将所需归档写入最终 APP、重新生成 APP 自身的原生锁。这些是生成应用的可安装依赖，不是 Code 源码里的业务 Package 目录。历史版本归档保留在 Git 历史及明确的发行材料中，不为了源仓清理删除使用中的应用依赖。

Core 的 prepared 指纹绑定 Code state root、该 Core 的 host scope、解析后的工具要求、实际命令文件身份及登记观察项，不绑定临时 Core 副本的绝对源码路径。相同 Core scope 与相同声明内容可以复用 prepared 入口；PHP/Web scope相互独立，工具约束、执行配置或工具身份变化会产生新的指纹；业务 manifest 的 description、业务依赖及 host 中的 release/backups 等非执行字段不使环境失效。

本地联合开发的源码选择由 `.local/core-dev.json` 独立保存；首次 `scripts/core-dev select` 可复用 host 中已明确选择的 Core 绝对路径，已有源码选择优先，显式 `--php-core-dir/--web-core-dir` 可覆盖本次选择。`peanut config` 不回写已有源码选择。`core-dev install/link --client web,platform` 只链接所列 Web 客户端，一次安装并构建所选 Web Core；默认仍为四端。Web Core 安装/构建和 PHP Core Composer 操作分别使用本 Code 的 Web/PHP scope，通过 `project-env exec` 调用登记的 pnpm/Composer，不借 Corepack 或固定的应用 Composer wrapper 更换工具。scope 未准备时先显式 config；安装/构建不会隐式 prepare 或安装工具。既有 Core watch 同样通过 Web scope 执行，完整命令见[当前开发生命周期](current-code-lifecycle.md#9-应用生成安装与升级)。

PHP 的 `local-core-composer` 仅在 ignored `.local/composer-core/composer.json` 中将 Core 依赖投影到所选源码的真实 Git 分支。已配置本地 path 模块若依赖 Core，其真实 manifest 元数据也在同一开发 manifest 内投影到该分支，模块版本保留原声明；Composer 通过原生 `package` 元数据和 `dist.type=path` 强制链接真实模块目录，不生成源码副本。局部 `update` 明确列出 Core 和这些受影响模块，其他包保持正式锁定版本；不使用 `-w/-W`、稳定版本 alias，也不修改正式 `server/composer.json`、锁或模块 manifest。此处复用 [Composer package repository](https://getcomposer.org/doc/05-repositories.md#package-2)、[path downloader](https://github.com/composer/composer/blob/2.10.2/src/Composer/Downloader/PathDownloader.php) 与[局部 update](https://getcomposer.org/doc/03-cli.md#update-u-upgrade)，只服务源码联调。

原生模块生命周期的依赖准备同样读取本工程明确保存的 `.local/core-dev.json`：存在有效选择时，其 Composer 操作经正常 `project-env prepare/exec` 调用 `local-core-composer install`，沿用所选 PHP Core 和 backend env；helper 继续核对来源、锁与服务登记。不存在选择时保持正式 Composer 路径；无效或属于另一工程的选择明确失败，不回落固定包。该路由只处理 PHP 开发依赖，不隐式安装或重建 Web Core。

已准备依赖的只读核验也沿用这项显式选择：确认已安装 Core 路径为所选源码后，模块的 Core 约束取现有 `.local/composer-core/composer.json` 内对应模块的开发投影，再由 Composer Semver 核验实际安装版本。其他依赖、PHP 与扩展仍按模块原声明核验；没有本地 Core 选择时，全部保留正式约束。核验不生成投影、不运行 Composer；缺少对应投影须先走上述原生准备入口，不改正式约束或把开发分支伪装成发行版本。

## 使用

使用支持该协议的 CLI，在选定 Code 根执行 `peanut release --plan` 只读检查，再执行 `peanut release`。默认从公开声明自动检测已安装工具；需要固定特殊工具、输出目录或本地 Core 时才使用 `peanut config` 或共享 profile。CLI 内部调用 `project-env` 和原生模板生产者，不要求每次手动 prepare/install/hash。需要本地 Core 随模板携带时显式选择 `--with-local-core`。

### 日常制包与正式发行的最短路径

**生成模板不等于公开发行。** `peanut release` 首次按实际输入准备并调用原生双 Edition 生产者，原生准备与 ST/MT 分别保留状态。相同输入再次执行时校验原附件并返回 `reused`；已知失败使用 `peanut release --resume`，仅重试未完成步骤。输入变化、已完成输出篡改或在途结果 UNKNOWN 都不能冒用旧 PASS。`--plan` 无副作用；计划存在不代表实际测试或发布完成。

**正式公开版本沿现有 GitHub Actions，不从本机手工上传包。** 四类制品独立定版和发布：

| 制品 | 常规触发方式 | GitHub Actions 负责 |
| --- | --- | --- |
| PHP Core（`peanut-admin/core`） | 确认固定源码及资格，审阅 `dev → main`，推送指向当前 `main` 的 annotated `vX.Y.Z` 或 `vX.Y.Z-rc.N` Tag | 核验固定的合格 CI run、原生锁与公共来源；等待 Packagist 对应版本索引并验证，再创建 GitHub Release |
| Web Core 六包 | 六包及根版本一致、资格有效后同样推送当前 `main` 的正式 Tag | 从合格制品按依赖顺序通过 npm trusted publishing/OIDC 发布六包、核 npm 内容，再创建 GitHub Release |
| Peanut CLI | 按独立版本号与当前 CLI main 的注释 Tag 进入现有 release.yml | GitHub Release 与 npm @peanut-admin/cli 双渠道核验同一来源及 tarball/SRI；实际公开版本以 npm view 结果为准，不把未发行的 dev 源码写成已安装版本。CLI 变化不要求脚手架重发 |
| ST/MT 脚手架 | 本地开发只运行必要入口；固定 dev 源 S，手动执行一次 scaffold-release.yml 的 publish=false，变更破坏性时 run_tests=true | 同批成功 run 保留 ST/MT 模板、CLI 消费与原 transport；受保护 PR 合并 main 并核同树，在 main 明确 publish=true 后自动核验/创建指向 S 的注释 Tag 并复用原件，不重测试/制包 |

Core/CLI 的 `release.yml` 由**推送 Tag**触发，单独合入 `main` 不触发正式发布。PHP/Web Core 在现有 `ci.yml` 选择默认 `production`，提供精确 `expected_source_commit`；正式工作流消费 `CORE_QUALIFICATION_RUN_ID` 指定的成功 CI 原件，不再制包。完整 Core 测试使用独立可选 `qualification`，完整 Code 应用 CI 同样按需，不因发行重复启动；production 保留包消费 PASS、完整测试 NOT_RUN。只有原件缺失、过期或相关输入变化才回原生产入口，不能复用不匹配原件或伪造通过。Core/CLI 正式 Tag 只接受 stable / RC；Code 稳定版和 RC 都由 main 明确手动发布；推送 Code Tag 不自动启动模板 CI。内部 `-alpha` 和本地 Git 归档不能假装已公开发包。

GitHub Actions 使用自身短期 `GITHUB_TOKEN`；Web Core 使用 npm OIDC 可信发布。维护者优先用已授权的 `gh` CLI 和现有自动工作流，不提取浏览器 Cookie/Session、手工复制长期令牌或新增认证体系。需要本人确认的 `main`/Tag/公开发布权限只在具体版本、源码和资格明确后处理。

**发行后验收：** 读取 GitHub Actions 真实结论、Packagist/npm/GitHub Release 的公开内容与摘要，再让独立干净消费者用官方版本实际创建 ST/MT、安装和完成至少一次必要升级。内部 `peanut release` 成功、开发机现有 `vendor` 和 Code `dev` 通过均不等于正式发布/正式包消费成功；运行时数据、已有客户菜单和权限需沿原生升级合同保全。没有实际缺陷，不重建制包/发行程序。

npm/pnpm 必须使用同一选定 Node 与对应 CLI 文件，Composer 必须使用选定 PHP。普通新工作区自动探测，不要求重填配置；只有显式保存的路径失效或确需重选本地 Core 时才重新 config。手工 config 仍先预览写集并请求确认，拒绝确认不写入；共享 profile 不复制进入公开发行物。

```sh
python3 scripts/project-env requirements
python3 scripts/project-env doctor
python3 scripts/project-env exec -- peanut status
```

上面保留了显式准备后的 `exec` 调用；正常自动路径使用 `run`。不支持 v2 的历史脚手架继续采用原 `config/prepare/exec` 合同，不能将新 CLI 当作已经替换旧应用的环境脚本。

Peanut CLI 的 `peanut config [--path <project-root>] [--yes]` 会发现已安装工具并预览后写入 `.local/toolchain/host.json`，推荐版本不一致时显示实际版本。主项目缓存默认是 `.local/toolchain/cache/pnpm`、`.local/toolchain/cache/npm`、`.local/toolchain/cache/composer`；Core 缓存默认是 `.local/toolchain/cores/<php|web>/cache/<工具>`。只有确认后才创建缓存目录并调用 `project-env prepare` 建立本地薄命令入口；它不安装工具、不改变全局 PATH。`host.json` 的 `core.php`/`core.web` 可相对 Code 根输入但保存绝对路径，以固定绑定实际 Core 位置并避免 Code 工作目录变化后按另一基准解析；`.local` 配置不随 Git 合并；自动检测不要求重新 config，显式 Core/路径绑定变化时才需要重新选择。release、backups 及其他既有路径仍按 Code 项目根解析并保持可读相对路径。

`requirements` 不需要本机配置，可供 CI 读取。`doctor` 只读检查；`prepare` 只在登记的 state root 下建立薄命令入口及临时目录，不安装第三方包。`exec` 只使用已经准备好的登记，不自动 prepare；子进程继承同一工具 PATH 和缓存。工具缺失、manifest 声明的平台约束不兼容、原生锁拒绝或绑定文件变化时退出 2；推荐版本差异本身不会拒绝。成功 exec 后保留实际子命令退出码及原始标准输入/输出。首次 `prepare` 先创建临时目录，再执行工具版本探测；`doctor` 在临时目录缺失时使用系统临时目录只读探测；后续 run/ensure 自动准备自己所需状态。

入口会创建工具入口，不是新的隔离系统或包管理器。命令本身仍具有调用者权限；选择已信任的配置和程序。不要用此入口绕过资源、测试、发布或部署授权。Peanut CLI 是独立安装的全局工具；这里的 project-env 只负责为当前项目选择登记工具环境，并在对应 Node bin 已安装 `peanut` 时调用它，不再在 Peanut Module Code 内复制业务 CLI。

## 可选制包数据库备份

维护者可用 `peanut config --release-database-registry <绝对登记文件> --release-database-resource <稳定ID> --release-database-sql <绝对SQL文件>` 保存可选 `release_database_backup`（`registry`、`resource_id`、`output`），默认关闭；`--no-release-database-backup` 清除选择。三个字段不含凭据，只写本机 `.local/toolchain/host.json`，不随源码或模板交付。SQL 和登记必须位于 Code 外，SQL 还须位于发行输出之外。完整数据库可能含秘密，Git 备份应选私有仓库，工具不执行 Git 上传。

启用时 `peanut release` 在输入准备和双 Edition 制包前调用一次 `scripts/export-release-database`，失败即停止。该入口只接受本机开发维护登记；数据库连接由本机应用环境加载并核对登记，维护选项不写 `.env`。导出按登记的 SSH 管理地址及容器执行原生 mysqldump，通过 `--single-transaction` 取得事务快照；严格 known-host 校验，容器 root 密码仅在远端容器内展开。输出目录必须属于当前用户且为 0700，SQL/receipt 为 0600；成功核原生退出码和完整 footer，再把旧 SQL/receipt 保全至 backups 后原子替换最新 SQL。旁边 `.sql.json` 记录 registry/资源、UUID、源码快照身份、native 参数、摘要及恢复位置。完整dump与用于首次安装的结构/必要初始数据源分别管理，导出不会改写安装 SQL。模板生成器及其 `--check` 不导出数据库。

## 速度与缓存

同一登记、工具文件身份、关联配置和关键进程环境不变时，exec 复用 prepare 的版本检查结果，不在每个命令前扫描全机或安装依赖。修改项目要求、本机路径、被观察 PHP 配置等后，需要显式 prepare。`watch_paths` 可登记 PHP ini 文件和扫描目录；不读取或打印其内容。一次 prepare 不代替完整产品资格。

pnpm、npm、Composer 各用自己的共享缓存。Node/pnpm/Composer 工具本体使用登记的已有文件；不同项目可以绑定同一份匹配版本，也可以绑定各自版本。不同包管理器大版本由其自身划分存储格式，不复制或清空其他项目缓存。vendor/node_modules 仍按各工作树原生锁安装；缓存只改变速度，不能绕过 frozen lock 或来源校验。

CI 安装工具的步骤先从 `requirements` 输出读取版本，再绑定 CI 自己的路径并 prepare；不得复制本机 host.json 到 CI。此批不触发 CI，也不替已有 Recipe 偷改 baseline。新的 CI 配方接入必须更新其版本与摘要；固定历史发行候选保持原样。

## 下游应用与可选 CI

下游交付约定是通过同一可变模板 inventory 接收 `scripts/project-env`、`tools/toolchain.json`、本机配置示例和本指南；公开 AGENTS 引导使用该入口。维护者必须先用原生生成器更新 inventory 并核对来源，之后正常生成应用；仅修改生成器或入口文档不代表已经完成交付。生成应用不携带真实 `host.json`、维护者资源或凭据，也不自动安装 GitHub 工作流。工具要求是可定制的 managed 文件，升级遇到本地修改沿既有冲突流程处理；历史应用不因模板更新被直接改写。

Recipe 源码与 catalog 由独立 CLI 维护，现行职责见[CLI 与配方](../architecture/cli-and-recipes.md)：`scaffold-ci` 使用正常模板生产者；`app-ci` 仍是既有 server-only 候选实现，尚未适配产品 Git 生产流程，不列为日常生产前置。旧混合 `github-ci` 已退役，既有安装 baseline 和定制保留；不能用 add、复制文件或改 baseline 冒充 Recipe 更新。

CI 使用自己的工具绑定与缓存，不继承本机 host.json；缓存只保存包缓存，不保存 vendor/node_modules、工具 shim、凭据或数据库。具体工作流实现、资格及实际运行结果分别核对，文档示例不触发 CI、正式发布或生产。

## 固定发行候选

环境入口可以在维护工具 checkout 中执行已有的原生发行脚本，脚本通过 `--source-commit` 读取原本固定的源码。工具入口所在提交和实际发行 source commit 必须分别记录，不能把工具提交冒充制品来源。旧 source/tree、snapshot、成功制品与失败日志不可覆盖；失败后仅在确认旧进程终止、诊断已明确、授权允许时使用新的输出位置恢复未完成步骤。
