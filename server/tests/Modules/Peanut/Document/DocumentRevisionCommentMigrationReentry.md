# Document 评论迁移的 MySQL 重入检查

## 修复范围

任务：DOCUMENT-MYSQL-SCHEMA-REENTRY-35。

迁移 `20261107100007_document_revision_comments.php` 的首次原生执行已生成评论线程、消息和操作三表。MySQL 8.4.10 的实际 `SHOW CREATE TABLE` 会显式输出外键辅助索引、TEXT 字符集和 CHECK 外层括号，并省略可空 TEXT 的默认 NULL 声明。原来的 `CREATE_SQL` 未完全采用这些等价表示，因此现有严格检查会拒绝自身生成的表。

本次仅校正 `CREATE_SQL`：

- 线程表为 `quote_text` 写明 `CHARACTER SET utf8mb4`，显式声明原本由 MySQL 创建的 `fk_document_comment_revision` 索引。
- 消息表为 `body` 写明同一字符集，采用实际可空 TEXT 声明，并补齐 CHECK 表达式的外层括号。
- 操作表显式声明原本由 MySQL 创建的两个线程、消息外键辅助索引。

迁移文件名、类名、`up()`、`down()` 和 `schemaDefinition()` 保持原始字节。没有放宽规范化规则、索引列顺序、约束、可空性或排序规则检查。该修正供后续 Document 候选采用，不修改历史发行归档或已应用的迁移账本。

## 固定夹具来源

`fixtures/document-comment-show-create-mysql-8.4.10.json` 的三个字符串直接取自真实升级证据 `document12-execute-three-schema-proof-01.json` 的 `tables[table].actual`，提取前核对源文件 SHA-256：

`037387b4dd09f3285a1c592a45c5a15c4928da092b5d3eff8bdf9a953e9e2b25`

夹具仅保留来源身份与原始 DDL 字符串，不从待测 CREATE 常量生成，不复制生产规范化函数，也不转换为 SQLite 结构。

## 测试装配和范围

`DocumentRevisionCommentMigrationReentryTest.php` 由正常 Composer autoload 加载实际迁移类，调用其 `up()`。PHPUnit 为原有 `PdoAdapter`、`PDO` 和 `PDOStatement` 提供受控替身，不调用 PDO 构造器或真实数据库。替身核对表检查和 SHOW CREATE 的顺序、读取模式、游标关闭与 CREATE 次数，实际 schema 比较由迁移实现完成。

| 组 | 用例数 | 检查结果 |
| --- | ---: | --- |
| 已有三个真实表定义 | 1 | 三表均被接受，零 CREATE |
| 不兼容结构 | 5 | 缺少外键索引、减弱 CHECK、复合索引列换序、改变可空性、改变排序规则均拒绝 |
| 前表缺失、后表不兼容 | 1 | 后表检查失败，已收集的前表仍零 CREATE |
| 三表均缺失 | 1 | 完成三个存在性检查后，按线程、消息、操作的外键依赖顺序 CREATE |

全缺失组核对实际发给 adapter 的建表顺序和父表引用。此处不运行 MySQL DDL，不证明新发行包安装、真实数据库重迁移或运行环境升级。

## 定向运行

从 Code 根目录使用已有登记环境：

```sh
python3 scripts/project-env exec -- php server/vendor/bin/phpunit \
  --no-configuration --do-not-cache-result \
  --fail-on-warning --fail-on-risky --colors=never \
  --bootstrap server/vendor/autoload.php \
  server/tests/Modules/Peanut/Document/DocumentRevisionCommentMigrationReentryTest.php
```

红例使用相同命令并增加 `--filter testExistingMysqlTablesAreAcceptedWithoutCreate`，针对修复前原始生产源码执行。

## 2026-11-07 结果

- 原始生产源码 SHA-256：`ca02f6181a2eb73be9cb54e755a45f1fc50b476c471bab7b0d53a996b3473988`。有效红例为 1 个测试、7 个断言、1 个错误，实际迁移抛出 `INITIAL_SCHEMA_MISMATCH: pa_document_comment_thread`。
- 修正生产源码 SHA-256：`1ec8de48c644b67b76afa609fa7e865bc8fcce99c48196d42864f511dc73a4cb`。最终绿例为 8 个测试、122 个断言，原生退出码 0。
- 实际环境为已登记的 `340692f22d55`，PHP 8.3.24、PHPUnit 12.5.31；没有额外环境覆写或重新 prepare。
- 正常 PHP-CS-Fixer 3.95.27 对指定两条 PHP 路径按现有配置取交集。配置排除迁移文件，实际格式检查和修复范围为新测试；生产迁移未被格式器改写。初次检查退出 8，格式修复后最终检查退出 0，0/1 文件需要修改。
- 格式器 stderr 保留了 Code 根缺少 composer.json 的最低 PHP 版本探测提示，未为该提示增加配置或修改依赖。

原始 stdout、stderr、完整 argv、执行前后 SHA、生产差异和最终回执保存在本工作树 `.local/007-document-mysql-schema-reentry-20261107/`。有效红例和格式检查失败分别保留。冻结文档时的一次 stdin 编码解析失败也独立保留，该次解析失败未执行写入。后续包版本、受管采用与真实运行验收由主控负责。
