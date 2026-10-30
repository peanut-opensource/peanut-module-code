# 发行渠道、来源与版本

完整生命周期见[产品开发与定版流程](README.md#产品开发与定版)。脚手架保留 ST、MT 两种模板包；创建和吸收模板升级继续由 Peanut CLI 完成。产品源码在自己的 Git `dev` 分支开发，定版需人工审核后进入 `main` 并打 tag。生产采用产品 `main` 的固定提交，尚未完整接通；边界见[产品 Git 交付](application-release.md)。

## 1. 从哪里取得产品

正式发行以官方GitHub Release的固定tag、来源提交、附件清单和可信摘要为准。开发源码版本由`release-versions.json#source_product_version`读取，不能从本页日期或示例推断已发布。历史实例按自己采用的版本阅读资料；CI构建成功、本地文件存在和正式公开发行分别记录。

| 对象 | 来源与用途 |
| --- | --- |
| 脚手架模板 | 官方固定发行来源，用于创建APP及提供上游升级输入 |
| APP开发源码 | APP自己的Git仓，承载业务、自有版本和上游baseline |
| APP生产来源 | APP自己的定版 `main` 提交/tag；原目录更新链尚待工程接通 |
| Core包 | Composer/npm固定公开版本及锁定摘要；开发联调不冒充正式包 |
| CLI | 独立工具版本，不等同脚手架或APP版本 |

脚手架、PHP Core、Web Core、APP和CLI各有独立版本；Web Core六包及内部依赖保持同一Web Core版本。脚手架升版不要求未变化的Core跟随升版。同一版本仍须核commit/tree及内容，不覆盖已发布附件。正式APP交付消费适用的已发行Core精确版本和原生锁；内部候选不能冒充正式渠道。

## 2. 创建和上游升级

当前开发CLI已实现直接读取正式模板压缩包，也可显式传入本地`--archive`和`--manifest`，在严格空目标目录创建APP；参数以实际采用的CLI版本help和说明为准，源码实现不表示该版本已公开发行。正式来源核GitHub Release、候选锁、固定Code commit/tree、Edition清单及TGZ长度/SHA，创建后保存模板来源、归档摘要和受管baseline。本地包保留development来源，不能标记为已正式发布。升级的无变化判断同时比较已记录的模板归档摘要；该归档固定Code、PHP Core与Web Core的实际组合，Code commit相同但Core投影改变时仍进入原生三方计划。开发Git来源允许相同或后继commit及不低于当前的模板版本，保留固定来源、baseline、定制冲突与恢复保护；正式同版本异制品仍拒绝。

完整开发模板的来源关系经原生验证后，已有完整受管baseline证明的模块源码可随固定模板更新，不要求仅为源码同步递增独立模块版本；Git后继与便携local snapshot都使用各自现有来源验证。模块降版、独立不可变模块包同版本异内容、无受管证明及客户模块的保护继续保留，来源选择不替代完整当前／目标模块图和客户兼容检查。

`peanut upgrade`在开发APP中吸收上游：`--path`选择APP，`--ref`固定目标，`--check`只检查，`--plan`保存计划。apply/recover继续同一来源和计划，不重新选最新版。省略版本的渠道及主版本范围由所用CLI版本决定，不允许静默跨主版本。APP名称、独立版本、自有业务、配置和定制不能被上游重置。

原生源码入口：

```sh
php /absolute/my-app/scripts/scaffold-upgrade plan --instance-root=/absolute/my-app --package=/absolute/authenticated-upstream
```

用返回的同一plan_path继续apply、verify或recover。旧baseline、APP当前文件和目标上游作三方比较；冲突明确解决，未知或部分写入先对账。源码升级不等于依赖、数据库、前端构建或生产采用成功。

## 3. APP发行与生产更新

生产首次在固定空目录、空库取得产品定版 `main`，准备锁定依赖、必要构建、配置并通过[安装页面向导](installation.md)完成安装；已有实例在原目录更新固定 `main`，按变化处理依赖、构建和未应用迁移。具体程序/数据边界、恢复和实现差距见[交付合同](application-release.md)。

首次安装通常使用网页向导。已有原生控制台安装入口供已准备依赖与配置、明确采用该方式的维护场景使用；从 APP 根目录运行，先执行预检且仅在结果为 ready 时安装。依赖、一次性管理员凭据文件及完整命令见[安装说明](installation.md#3-安装)。

```sh
php server/database/install.php --preflight
PEANUT_INSTALLATION_ENV_FILE=/absolute/private/initial-admin.env php server/database/install.php
php server/database/install.php --status
```

B 部署包、`peanut package` 和 `release/apps` 退出日常生产环节。现有包生产者、固定 Git 输入与 `update.sh` 仍保留实际消费者的技术合同，不因文档改动删代码，也不作为新流程的额外用户步骤。源码升级不等于生产更新；Git 能退版本不意味着数据库可自动回退。

## 4. 来源与失败保护

取得来源后先核可信摘要与路径安全，再核APP/Edition/版本、逐文件清单和依赖。未知包旁自行计算的摘要不是可信来源。不要执行未认证目标包的更新入口，不复制其他APP的vendor或作无约束依赖更新。

恢复消费原计划和真实journal。激活前按实际阶段成对恢复程序、依赖、配置身份及必要数据；激活后不自动回灌旧数据库。失败、拒绝和UNKNOWN保留，不通过重建APP、删锁或改账本重置事实。

上游Core发布、候选封存、main/tag证明和资格工具在源码仓docs/maintenance中维护，不属于普通APP教程。本文不授予正式发行、生产、客户数据或费用操作权限。
