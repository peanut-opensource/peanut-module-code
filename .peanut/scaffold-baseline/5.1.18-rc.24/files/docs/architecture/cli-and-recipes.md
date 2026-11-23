# Peanut CLI 与可选交付配方

## 职责

CLI是独立工具产品，提供config/release/create/upgrade及状态、诊断入口；package保留为既有APP包消费者的命令。Code提供模板、APP身份、受管baseline、原生安装/升级及生产工具合同；Core不导入CLI，也不复制业务工具实现。普通APP不依赖维护者Project。

模板包用于创建APP及上游源码升级；APP在自己的Git开发与定版，生产采用固定main提交，完整协调尚待接通，见[产品Git交付](../public/application-release.md)。CLI、脚手架、APP和Recipe版本独立，不能把一处版本升级当作全部运行环境已更新。

## 当前命令与简化目标

对应CLI版本的help和README是参数真值。当前已有create、upgrade、doctor/status及可选recipe入口；旧v0.1.0 MVP关于“完整create/upgrade尚未实现”的文字不再作为当前能力说明。命令存在不证明全部路径已运行验收。

当前开发源码支持官方模板压缩包或显式本地包直接创建、严格空目标目录，以及同格式模板的固定来源升级计划；实际发行版本以CLI发行回执为准。create只生成新APP，不替代安装/升级/部署。已有APP通过固定来源、三方计划和恢复入口升级，不重建目录消除定制冲突；命令存在不表示具体APP已经采用或业务验收通过。

## Recipe的边界

Recipe是可选CI配置，来源在CLI仓的recipes目录，各版本有manifest和baseline。它不能成为创建APP的强制前置，不默认配置远端Secrets、权限、数据库或服务。

初次安装先核文件占用和归属；既有文件即使内容相同也不得静默收编。Recipe文件归recipe owner，用户改动保留；后续更新应复用同一三方比较及恢复机制，不复制第二个升级引擎。当前未实现的更新/卸载/远端下载保持明确缺口，不把add当覆盖升级。

当前开发catalog将GitHub职责拆成`scaffold-ci`和`app-ci`，安装时分别核脚手架inventory/版本合同或APP manifest。前者串行调用与本地相同的原生生产者，生成两Edition模板及配套清单；后者仍调用APP的server-only制包器，是尚未适配产品Git交付的既有实现，不作为日常生产步骤。CLI仓自己的CI只发行CLI工具。候选工作流上传构建结果，正式GitHub附件仍由通过资格的获准CI发布；不能把候选artifact当正式发行。

GitHub配方只有显式触发才运行，不默认修改Secrets、权限或生产。已退役的混合`github-ci`源码从当前catalog移除；已发布Git历史与APP已安装baseline保留。新Recipe遇到既有工作流即拒绝，不通过add覆写定制或收编旧owner。脚手架仓自己的Recipe状态不进入生成APP；APP选择自己的Recipe并维护独立baseline。

## 诊断与失败

Doctor检查对应工具、锁和配置，不安装依赖、不读取秘密、不连接DB或证明服务健康。Status报告实际APP来源、独立版本、Recipe定制和升级状态；目录存在不能被解释为成功或失败。

创建/升级在精确目标内工作，拒绝越界链接与未知占用。中断先读同一计划/安装记录，不重复派工、移除原APP或改变运行身份。工作区路径和公开来源保持独立；工具实现、配置、实际采用和验证结果分别记录。
