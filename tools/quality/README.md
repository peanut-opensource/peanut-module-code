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

- **Web 后台**：`cd web && npx vue-tsc --noEmit --skipLibCheck`
- **UniApp 端**：`cd uniapp && npx vue-tsc --noEmit --skipLibCheck`
- **Platform / PC 端**：按对应目录 `package.json` 中的 `type:check` / `build` 脚本执行
- **文章 HTML 浏览器测试**：`node scripts/tests/article-html-browser-test.mjs`
- **模块发布自动化测试**：`python3 -m unittest scripts/tests/test_module_release_automation.py`
- **产品化端到端测试目录**：
  - `web/tests/Productization/`
  - `platform/tests/Productization/`
  - `pc/tests/Productization/`
  - `uniapp/tests/Productization/`

> 注意：普通开发、构建、启动和原生迁移不使用自写租约；多执行者按实际写集与原生数据库事务/锁协调。
