# Peanut 文档入口

本文导航随APP交付的公开资料。实现、生成合同、文档和交付清单必须对应同一来源；文档存在不代表安装、升级或业务验证已通过。

## 产品使用：创建、开发、发行与部署

- [产品使用资料](public/README.md)：按角色进入。
- [产品开发与定版流程](public/README.md#产品开发与定版)：脚手架模板、产品 `dev` 开发、审核后定版，以及生产 Git 流程的目标与当前状态。
- [发行渠道与版本身份](public/release-channels.md)：脚手架、APP、Core和CLI版本分开。
- [首次安装与实例配置](public/installation.md)：新实例与已有实例分开。
- [产品 Git 交付与生产更新](public/application-release.md)：定版、首次部署、原目录更新、数据保护及实现差距。
- [API合同与SDK](public/api-and-sdk.md)、[模块开发与交付](public/module-development.md)。

## 二开开发：规范与源码

[开发规范](development/standard.md)维护共用约定；[应用约定](development/application-conventions.md)、[Controller与命名空间](development/controller-access-and-namespaces.md)、[PHP/ThinkPHP](development/php-thinkphp-guidelines.md)解释具体写法。

[当前代码导读](development/current-code-lifecycle.md)、[模块布局](module-layout.md)、[工具链](development/project-toolchain.md)、[开发者中心与文档生成](development/developer-center-and-documentation.md)、[菜单导航](development/menu-navigation.md)提供实际入口。

## 产品架构与扩展合同

- [能力地图](architecture/overview.md)、[默认交付与职责](architecture/default-delivery-and-ownership.md)。
- [Core与应用覆盖](architecture/core-capabilities-and-application-overrides.md)、[框架扩展生命周期](architecture/framework-extension-and-lifecycle.md)、[CLI与可选Recipes](architecture/cli-and-recipes.md)。
- [身份与组织](architecture/identity-organization-access.md)、[租户模块与Scope](architecture/tenant-modules-and-data-scopes.md)、[模块开发](architecture/module-development-delivery.md)。
- [业务文档与协同](architecture/document-collaboration-standard.md)、[内容工具与格式适配](architecture/content-surface-and-format-adapters.md)、[文件可见性与受控源站](architecture/file-visibility-sources.md)。
- [平台服务绑定](architecture/service-bindings.md)、[事件任务](architecture/events-and-tasks.md)。

## 不随APP分发的维护资料

维护者的[模板生产、资格与既有包技术合同](maintenance/release-producers.md)、[本地身份凭据维护](maintenance/identity-credentials.md)、[授权 Workflow 身份消费者](maintenance/workflow-authorized-fixture.md)在源码仓`docs/maintenance/`；内部需求决定、资源实值、任务和运行回执在私有Project。它们不是产品使用者的必读前置。历史发行快照和APP旧baseline保留其原版本，不改成当前事实，也不从历史任务书恢复执行。

API与模块清单由原生结构化源生成；没有第二份手工API真值。公开文档站只展示固定来源，不拥有另一套技术正文。
