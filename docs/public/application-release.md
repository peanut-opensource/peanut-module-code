# 产品 Git 交付与生产更新

产品源码归自己的 Git：dev 开发，定版环节在开发／发行机器准备前端静态文件，经审核合入 main 并创建对应 tag，生产采用该固定 Git 提交。产品、脚手架、Core 和 CLI 版本独立；模板用于 create/upgrade，生产不直接吸收模板，不使用 B 包或另传前端压缩包。

## 1. 产品定版与前端准备

前端构建属于第 4 步的发版准备，运行在有 Node 和原生包管理器的开发／发行机器。先将本次源码、产品版本声明、Edition 和锁文件确定，再在该 APP 根目录执行现有入口：

```sh
scripts/deploy-update.sh --prepare-release --version=<声明中的产品版本>
```

版本参数只核对 APP 声明，不改版本；省略时采用当前声明，不写死版本或部署目录。准备动作读取 APP 原有身份与依赖声明，验证构建所消费的 Web 归档和 PHP 锁／本地归档，将 `plugins.lock` 已选模块作为真实 Composer path 包及客户端 workspace 一次加入原生依赖图，保留宿主依赖并由 Composer、pnpm/npm 更新对应锁。随后各构建一次 admin 和适用的 multi-tenant platform。Admin 显式使用 standalone/multi-tenant mode，API 保持同源。审阅模块依赖声明及原生锁变化；PC/H5 后置，保留其源码与既有产物。

输出是普通 HTML、JS、CSS 等文件：`server/public/admin`，以及 MT 的 `server/public/platform`。这两个目录作为产品 Git 的受管发行内容；准备动作只清对应静态目录，拒绝链接、特殊类型或仍被忽略的目标。它不启动业务、安装数据库、修改 main/tag 或自动提交。

审阅构建差异并把静态目录与同一版本的源码、manifest/lock 一起提交；MT 同时提交平台目录。例如：

```sh
git add -- server/public/admin
# MT 另执行：
git add -- server/public/platform
```

其他源码及版本文件按本批精确写集提交，不批量暂存无关改动。完成必要业务确认及 main/tag 审核后，固定最终提交；构建成功不等于公开发行、完整资格或生产采用成功。Node、npm、pnpm、前端源码编译均不属于生产步骤。

## 2. Git 与实例数据边界

| 对象 | 管理方式 |
| --- | --- |
| 后端及前端源码、迁移、脚本、默认配置、依赖 manifest/lock | 产品 Git 跟踪 |
| 发版构建的 admin、MT platform 静态文件 | 与对应源码一起提交，生产由 Git 原样采用 |
| vendor、node_modules、开发构建缓存 | 实例或开发依赖，不提交 |
| .env、凭据、证书、仓库授权 | 实例秘密，未跟踪且忽略 |
| uploads、DB/卷、安装身份、防重装标记及运行数据 | 原位保留，程序更新不覆盖 |

只读仓库授权由目标环境秘密机制提供，不进入 Git、URL 或日志。忽略规则不等于已跟踪秘密自动退出 Git。禁止 reset --hard、clean、清库、删安装锁或无边界目录覆盖解决冲突。

## 3. 生产首次安装

1. 核产品固定 main/tag、全新空源码目录与空库。已有或 partial 实例按其实际状态处理，不借首装重建数据。
2. 从产品 Git 获取定版提交，确认已跟踪的 admin/index.html 及适用 platform/index.html，连同静态资源完整到位。生产不安装前端依赖、不构建前端。
3. 准备生产配置、固定 PHP 依赖和运行环境。挂载式 Docker 的 `server/docker/scripts/start.sh -p <实例项目名>` 复用原生 prepare-vendor：新完整 APP 的 vendor 缺失时按原 Composer 锁准备、检查并封印；已完成且有效则复用，已有损坏 vendor 或 installed 实例不在线覆盖。固定 PHP/Nginx 镜像、配置和原 Compose 项目沿该实例登记采用。
4. 启动 PHP/public，通过[安装页面向导](installation.md)完成安装和真实后台访问。保留当次安装身份与防重装保护。

依赖安装边界仍校验 PHP ZIP 的来源、内容摘要、锁和已安装包。原始 Web TGZ 在发行机器构建时校验；PHP owner 启动和 HTTP 安装预检不要求挂载 Web TGZ，也不重新读取 PHP ZIP。运行声明仍校验版本、Edition、来源，安装预检仍比对 Composer manifest、锁、已装包版本／来源／本地 dist 元数据及迁移服务。模块启动与部署准入从同一锁核对后端声明及 PHP 包，不读取 web/platform 源码；源码创建、升级和包采用继续执行完整源码校验，详见[模块入口](module-development.md)。不要通过临时挂载、伪造身份或删校验推进安装。

## 4. 已有生产原目录更新

固定产品 main 提交 → 审阅程序及受管静态差异、核恢复边界 → 按实际影响排空／停写 → 原目录 Git 更新 → 按变化准备 PHP 依赖 → 执行尚未应用的迁移 → 必要缓存／进程采用 → 真实后台及本次业务确认。

静态文件由同一个 Git 提交更新；无生产前端构建或额外静态包。配置、秘密、上传、安装身份和业务数据保持原位，不重放 seed，不改已应用迁移历史。代码回退不等于数据恢复；开放写入后不自动回灌旧库覆盖新数据。

## 5. 执行入口与失败边界

完成目标、恢复和维护窗口核对后，在生产 APP 根执行：

```sh
scripts/deploy-update.sh --target=<审核main的完整提交>
# 挂载式 Docker 实例：
scripts/deploy-update.sh --target=<审核main的完整提交> --compose-project=<原实例项目名>
```

入口要求真实产品根、main、无受管修改，fetch 后目标仍等于审核的 origin/main，只允许 ff-only 及相同 Edition；目标须已包含该 Edition 的受管后台入口。pending 绑定旧／新提交、Edition 和原 Compose 项目。生产分支只采用 Git 静态文件，不调用 Node、npm 或 pnpm。

Docker 模式复用原生 Compose 参数及适用 overlay、原 vendor receipt，正常停 nginx/php，在同一 php 服务的 owner 工作目录和网络执行原生 module:compile、migrate:run、clear --cache，再复用 start.sh 恢复。MySQL/卷保留；其他业务写者由 owner 预先排空。宿主 PHP 模式的停写和进程采用由调用者管理。

已安装 Docker 实例的 Composer 输入变化仍明确拒绝，发生在程序采用／停服前；该维护能力未实现时，不借 start/prepared、伪造身份或手工 seal 绕过。无锁变化且 vendor 有效才复用。宿主模式仍按锁以 no-scripts/no-plugins 准备并执行现有 service discovery/vendor publish。

失败保留 pending 与实际效果，先对账程序、PHP 依赖、静态文件、迁移和进程，不盲重发或自动恢复旧库。脚本成功不替代真实业务确认。本文不授予 main/tag、公开发布、生产、资金或破坏性数据操作权限。
