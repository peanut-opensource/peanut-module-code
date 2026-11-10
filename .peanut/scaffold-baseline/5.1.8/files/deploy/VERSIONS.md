# 部署与构建版本来源

| 对象 | 权威来源 | 说明 |
| --- | --- | --- |
| 产品源码版本 | release-versions.json | 开发身份，不证明已发行 |
| APP版本与上游基线 | .peanut/application-manifest.json及APP Git | 二开版本与上游版本分别记录 |
| PHP与扩展 | server/composer.json、server/composer.lock、实际固定镜像 | 以该版本平台要求和运行检查为准 |
| Composer/Node/包管理器 | tools/toolchain.json及本机私有绑定 | project-env doctor核已准备工具；prepare另行显式执行 |
| PHP/Nginx/MySQL镜像 | server/docker的对应配方和实际镜像ID | tag不是不可变镜像证明 |
| 浏览器静态 | 同次APP制包构建到server/public | 生产不临时运行前端开发构建 |

普通APP默认server-only制包，详细合同见docs/public/application-release.md。本地与CI调用同一版本原生脚本并固定依赖；不得以此表写一个长期不更新的重复版本矩阵。SSR、原生终端及可选服务另按实际支持范围验证，不从SPA构建成功推定其可用。
