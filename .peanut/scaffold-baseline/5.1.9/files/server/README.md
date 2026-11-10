# Peanut Module Code Server

本目录是 Peanut Module Code 的 ThinkPHP 8 后端，不是独立 ThinkPHP starter。开发源码从仓库根目录的 `docs/public/README.md` 开始；server-only实例阅读其来源版本的公开说明。生成APP不需要独立docs-site工程。维护者规则、候选治理和部署审批不随生成应用发布，也不是运行应用的私有依赖。

不要在这里执行上游 `composer create-project`、假定 `localhost:8000`，或绕过项目资源登记启动
服务。第三方 ThinkPHP 的许可证和依赖信息仍以 `LICENSE.txt`、`composer.json` 与锁文件为准。

## 后台配置

`server/.env` 是 PHP 后台的唯一配置源。首次使用时从 `server/.env.example` 复制，并在其中
维护 `APP_*`、`DB_*`、JWT、部署模式以及 Tenant/Platform 配置。部署编排使用 `server/docker/.env` 管理
端口和镜像，不得重复这些后台字段；源码开发栈的根环境文件仅服务其明确的开发编排，不代替生产配置。`server/.env` 必须是普通文件且权限为 `0600`。

隔离测试使用同目录下的一次性 `server/.env.<run-id>`。禁止使用 `PHP_DB_*` 绕过文件配置；
当进程变量与所选后台配置文件不一致时，启动会 fail-closed，且错误不输出配置值。
