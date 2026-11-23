# 模块开发应用质量与测试入口

本文件维护 `peanut-module-code` 仓库内**实际存在**的代码检查、静态校验与测试入口。已退役的宿主私有脚本（如 `scripts/ci-server-check.sh`、`scripts/package-release-files.py`）与旧租约环境变量（`*_LEASE_ID`）不再适用于本仓；日常开发与验证直接使用原生锁与本仓实际入口。

## 一、源码格式与架构边界检查

- **源码格式化**：`node scripts/format-source.mjs`
- **模块边界检查**：`php scripts/check-module-boundaries.php`
- **源码布局检查**：`php scripts/check-source-layout.php`
- **PHP 静态分析**：`scripts/phpstan-tenant-guard` 与 `scripts/phpstan-quality`

## 二、服务端测试（PHP）

- **PHP 测试入口**：`scripts/run-php-test`
- **官方模块测试目录**：`server/tests/Modules/`
- **单文件语法检查**：`php -l <path/to/file.php>`

## 三、前端类型检查与测试

- **Web 后台**：`python3 scripts/project-env --tools node,pnpm run -- pnpm --dir web type:check`（包含原生品牌输入同步）
- **UniApp 端**：`python3 scripts/project-env --tools node,pnpm run -- pnpm --dir uniapp type:check`（包含原生模块页面准备）
- **Platform / PC 端**：按对应目录 `package.json` 中的 `type:check` / `build` 脚本执行
- **文章 HTML 浏览器测试**：`node scripts/tests/article-html-browser-test.mjs`
- **模块发布自动化测试**：`python3 -m unittest scripts/tests/test_module_release_automation.py`
- **产品化端到端测试目录**：
  - `web/tests/Productization/`
  - `platform/tests/Productization/`
  - `pc/tests/Productization/`
  - `uniapp/tests/Productization/`

> 注意：普通开发、构建、启动和原生迁移不使用自写租约；多执行者按实际写集与原生数据库事务/锁协调。

## 四、PC 模块组合的 SSR 与浏览器验证

完成本应用的原生依赖与模块准备后，使用 `NUXT_PC_RENDER_MODE=hybrid python3 scripts/project-env --tools node,pnpm run -- pnpm --dir pc build` 构建一次。Article、Member 等模块的贡献须真实存在；薄模板构建不能代替模块页面行为。随后按相同原生 `.output` 串行执行 `pc/tests/Productization/ssr-multitenant.test.mjs` 与 `pc/tests/Productization/browser-hydration.test.mjs`，均通过 `python3 scripts/project-env --tools node run -- node --test <测试文件>` 调用。

两项均要求显式设置 `PEANUT_RESOURCE_REGISTRY`（应用外的 Project 资源登记绝对路径）、`PC_SSR_PROJECT_ROOT`（本应用 `pc` 绝对路径）、`PC_SSR_SOURCE_COMMIT`（当前完整 Git HEAD）、`PC_SSR_OUTPUT_SHA256`（按测试 `outputDigest()` 算法核定的原生产物摘要）及本轮已有 `TMPDIR`。HTTP 项使用新的空 `PC_SSR_EVIDENCE_DIR`；浏览器项另需登记版本的现有 `PC_BROWSER_PLAYWRIGHT_ROOT` 与新的空 `PC_BROWSER_EVIDENCE_DIR`。证据和临时目录分别位于本应用 `.local/evidence/`、`.local/tmp/`。

两项复用登记的回环 listener，必须串行；资源、版本、来源、摘要或端口不符即失败。测试保留真实请求、页面错误、产物前后摘要和浏览器截图，只清理自己创建的 Node、合成上游和浏览器。此处验证模块页面 SSR、隐私头、Host 隔离与客户端接管；合成 API 不能证明真实 PHP、数据库、登录权限或完整发行资格。
