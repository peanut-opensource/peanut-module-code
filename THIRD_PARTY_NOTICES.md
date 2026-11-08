# Peanut Module Code Third-Party Notices

Generated for Peanut Module Code 5.1.4 on 2026-11-08.

Peanut Module Code is licensed under Apache-2.0: Copyright 2026 application owner. Third-party components remain governed by their own licenses.

## Distribution boundary

- The normative GitHub Release distributes this repository's source. It does not attach prebuilt PHP/Nginx images; the fixed core packages are published separately in their public registries.
- Production Compose builds static management, PC and H5 assets and installs the 54 Composer production packages listed below. No `node_modules` directory is copied into the final images.
- The exhaustive package/version/license/source inventory for the seven locked dependency graphs is `RELEASE_SBOM.spdx.json` (SPDX 2.3). The two quality-tool graphs are development inputs, separate from product runtime. Build-only entries are retained there so source-release recipients can reproduce the build and its notices.
- Each installed dependency may include additional license or notice files. Those files remain authoritative for that dependency and must not be removed from redistributed dependency archives.

## Material source and framework attributions

| Source | License | Attribution and use in this release |
|---|---|---|
| Arco Design Pro Vue | MIT | The initial management client used Arco Design Pro Vue material; applicable upstream MIT attribution is retained. Source: https://github.com/arco-design/arco-design-pro-vue |
| LikeAdmin 1.9.4 | MIT | Used as the documented behavioral parity reference. This notice does not claim the application is a clean-room implementation. Source: https://github.com/likeadmin-likeshop/likeadmin_php |
| ThinkPHP 8 | Apache-2.0 | Backend framework. Its upstream notice is also retained at `server/LICENSE.txt`. Source: https://github.com/top-think/framework |
| `peanut-admin/core` | Apache-2.0 | Composer core package locked at v5.0.3 (f2b0435f577c5119f1dfc58d34c274ccb149c477). Source: https://github.com/peanut-business/peanut-admin-core-php |
| Peanut Module Code Core Web | Apache-2.0 | npm core packages locked as @peanut-admin/client@5.0.5, @peanut-admin/vue@5.0.5, @peanut-admin/ui-vue@5.0.5, @peanut-admin/nuxt@5.0.5, @peanut-admin/uniapp@5.0.5, @peanut-admin/testing@5.0.5. Source: https://github.com/peanut-business/peanut-admin-core-web |

## License handling

- MIT, ISC, BSD, 0BSD, MIT-0, Apache-2.0 and Zlib notices are preserved through this file, the SPDX inventory and the upstream package sources recorded there.
- MPL-2.0 entries are build inputs in the current Nuxt and development-tool lock graphs; no standalone package or modified MPL source is shipped as a release attachment. If a future release distributes those files, it must add the MPL source/notice obligations for that artifact.
- CC0, CC-BY, BlueOak-1.0.0 and Python-2.0 entries are identified below and in the SPDX inventory; attribution-bearing data must keep its upstream credit when redistributed.
- Compound expressions retain the upstream choice exactly. `node-forge@1.4.0` is recorded as `BSD-3-Clause OR GPL-2.0`; this release relies on the permissive BSD-3-Clause option and does not claim a GPL grant for Peanut Module Code.
- `@tybys/wasm-util@0.10.3` and `@napi-rs/lzma-linux-x64-gnu@1.5.1` publish MIT metadata but no separate copyright line or NOTICE in the inspected upstream artifact. Their package/version/source is recorded without inventing an attribution.

## Locked-license summary

| Ecosystem | Declared license | Lock entries |
|---|---|---:|
| composer | `Apache-2.0` | 24 |
| composer | `BSD-3-Clause` | 24 |
| composer | `MIT` | 34 |
| pc | `Apache-2.0` | 21 |
| pc | `BlueOak-1.0.0` | 14 |
| pc | `BSD-2-Clause` | 11 |
| pc | `BSD-3-Clause` | 9 |
| pc | `BSD-3-Clause OR GPL-2.0` | 1 |
| pc | `CC-BY-4.0` | 1 |
| pc | `CC0-1.0` | 3 |
| pc | `ISC` | 52 |
| pc | `MIT` | 792 |
| pc | `MIT OR Apache-2.0` | 1 |
| pc | `MIT OR CC0-1.0` | 1 |
| pc | `MIT-0` | 3 |
| pc | `MPL-2.0` | 12 |
| pc | `MPL-2.0 OR Apache-2.0` | 1 |
| platform | `Apache-2.0` | 4 |
| platform | `BSD-2-Clause` | 1 |
| platform | `BSD-3-Clause` | 2 |
| platform | `ISC` | 3 |
| platform | `MIT` | 143 |
| quality-composer | `BSD-3-Clause` | 2 |
| quality-composer | `MIT` | 36 |
| quality-js | `Apache-2.0` | 3 |
| quality-js | `BlueOak-1.0.0` | 4 |
| quality-js | `BSD-2-Clause` | 1 |
| quality-js | `BSD-3-Clause` | 1 |
| quality-js | `ISC` | 15 |
| quality-js | `MIT` | 108 |
| quality-js | `MPL-2.0` | 12 |
| uniapp | `Apache-2.0` | 48 |
| uniapp | `BlueOak-1.0.0` | 1 |
| uniapp | `BSD-2-Clause` | 12 |
| uniapp | `BSD-3-Clause` | 20 |
| uniapp | `CC-BY-4.0` | 1 |
| uniapp | `CC0-1.0` | 1 |
| uniapp | `ISC` | 49 |
| uniapp | `MIT` | 878 |
| uniapp | `MIT AND Zlib` | 1 |
| uniapp | `MIT OR CC0-1.0` | 1 |
| web | `0BSD` | 2 |
| web | `Apache-2.0` | 17 |
| web | `BlueOak-1.0.0` | 1 |
| web | `BSD-2-Clause` | 23 |
| web | `BSD-3-Clause` | 9 |
| web | `CC-BY-3.0` | 1 |
| web | `CC-BY-4.0` | 1 |
| web | `CC0-1.0` | 3 |
| web | `ISC` | 47 |
| web | `MIT` | 676 |
| web | `MIT OR Apache-2.0` | 1 |
| web | `MIT OR CC0-1.0` | 6 |
| web | `MPL-2.0 OR Apache-2.0` | 1 |
| web | `Python-2.0` | 1 |

## Composer production packages

These 54 packages are installed with `composer install --no-dev` in the production PHP image.

| Package | Version | License | Source |
|---|---|---|---|
| `aliyuncs/oss-sdk-php` | `v2.7.3` | `MIT` | https://github.com/aliyun/aliyun-oss-php-sdk.git |
| `aws/aws-crt-php` | `v1.2.7` | `Apache-2.0` | https://github.com/awslabs/aws-crt-php.git |
| `aws/aws-sdk-php` | `3.401.5` | `Apache-2.0` | https://github.com/aws/aws-sdk-php.git |
| `composer/semver` | `3.4.4` | `MIT` | https://github.com/composer/semver.git |
| `dragonmantank/cron-expression` | `v3.6.0` | `MIT` | https://github.com/dragonmantank/cron-expression.git |
| `firebase/php-jwt` | `v7.1.0` | `BSD-3-Clause` | https://github.com/googleapis/php-jwt.git |
| `guzzlehttp/command` | `1.5.3` | `MIT` | https://github.com/guzzle/command.git |
| `guzzlehttp/guzzle` | `7.15.3` | `MIT` | https://github.com/guzzle/guzzle.git |
| `guzzlehttp/guzzle-services` | `1.7.3` | `MIT` | https://github.com/guzzle/guzzle-services.git |
| `guzzlehttp/promises` | `2.5.2` | `MIT` | https://github.com/guzzle/promises.git |
| `guzzlehttp/psr7` | `2.13.0` | `MIT` | https://github.com/guzzle/psr7.git |
| `guzzlehttp/uri-template` | `v1.0.10` | `MIT` | https://github.com/guzzle/uri-template.git |
| `league/flysystem` | `3.35.3` | `MIT` | https://github.com/thephpleague/flysystem.git |
| `league/flysystem-local` | `3.31.0` | `MIT` | https://github.com/thephpleague/flysystem-local.git |
| `league/mime-type-detection` | `1.17.0` | `MIT` | https://github.com/thephpleague/mime-type-detection.git |
| `league/uri` | `7.8.1` | `MIT` | https://github.com/thephpleague/uri.git |
| `league/uri-interfaces` | `7.8.1` | `MIT` | https://github.com/thephpleague/uri-interfaces.git |
| `masterminds/html5` | `2.11.0` | `MIT` | https://github.com/Masterminds/html5-php.git |
| `mtdowling/jmespath.php` | `2.9.2` | `MIT` | https://github.com/jmespath/jmespath.php.git |
| `opis/json-schema` | `2.6.0` | `Apache-2.0` | https://github.com/opis/json-schema.git |
| `opis/string` | `2.1.0` | `Apache-2.0` | https://github.com/opis/string.git |
| `opis/uri` | `1.1.0` | `Apache-2.0` | https://github.com/opis/uri.git |
| `peanut-admin/core` | `v5.0.3` | `Apache-2.0` | https://github.com/peanut-business/peanut-admin-core-php.git |
| `peanut-business/peanut-content-tools` | `1.0.2` | `Apache-2.0` | app/modules/peanut/content_tools |
| `peanut-business/peanut-file` | `1.0.44` | `Apache-2.0` | app/modules/peanut/file |
| `peanut-business/peanut-identity` | `4.0.0-rc.59` | `Apache-2.0` | app/modules/peanut/identity |
| `peanut-business/peanut-integration` | `1.0.29` | `Apache-2.0` | app/modules/peanut/integration |
| `peanut-business/peanut-ops` | `1.0.33` | `Apache-2.0` | app/modules/peanut/ops |
| `peanut-business/peanut-reference-codes` | `1.0.26` | `Apache-2.0` | app/modules/peanut/reference_codes |
| `peanut-business/peanut-settings` | `1.0.27` | `Apache-2.0` | app/modules/peanut/settings |
| `peanut-business/peanut-task` | `1.0.32` | `Apache-2.0` | app/modules/peanut/task |
| `psr/container` | `2.0.2` | `MIT` | https://github.com/php-fig/container.git |
| `psr/http-client` | `1.0.3` | `MIT` | https://github.com/php-fig/http-client.git |
| `psr/http-factory` | `1.1.0` | `MIT` | https://github.com/php-fig/http-factory.git |
| `psr/http-message` | `2.0` | `MIT` | https://github.com/php-fig/http-message.git |
| `psr/log` | `3.0.2` | `MIT` | https://github.com/php-fig/log.git |
| `psr/simple-cache` | `3.0.0` | `MIT` | https://github.com/php-fig/simple-cache.git |
| `qcloud/cos-sdk-v5` | `v2.6.17` | `MIT` | https://github.com/tencentyun/cos-php-sdk-v5.git |
| `qiniu/php-sdk` | `v7.4.0` | `MIT` | https://github.com/qiniu/php-sdk.git |
| `ralouphie/getallheaders` | `3.0.3` | `MIT` | https://github.com/ralouphie/getallheaders.git |
| `symfony/deprecation-contracts` | `v3.7.1` | `MIT` | https://github.com/symfony/deprecation-contracts.git |
| `symfony/filesystem` | `v7.4.23` | `MIT` | https://github.com/symfony/filesystem.git |
| `symfony/html-sanitizer` | `v7.4.23` | `MIT` | https://github.com/symfony/html-sanitizer.git |
| `symfony/polyfill-ctype` | `v1.37.0` | `MIT` | https://github.com/symfony/polyfill-ctype.git |
| `symfony/polyfill-mbstring` | `v1.38.2` | `MIT` | https://github.com/symfony/polyfill-mbstring.git |
| `symfony/polyfill-php80` | `v1.37.0` | `MIT` | https://github.com/symfony/polyfill-php80.git |
| `topthink/framework` | `v8.1.4` | `Apache-2.0` | https://github.com/top-think/framework.git |
| `topthink/think-container` | `v3.0.2` | `Apache-2.0` | https://github.com/top-think/think-container.git |
| `topthink/think-filesystem` | `v3.0.0` | `Apache-2.0` | https://github.com/top-think/think-filesystem.git |
| `topthink/think-helper` | `v3.1.12` | `Apache-2.0` | https://github.com/top-think/think-helper.git |
| `topthink/think-migration` | `v3.1.1` | `Apache-2.0` | https://github.com/top-think/think-migration.git |
| `topthink/think-multi-app` | `v1.1.1` | `Apache-2.0` | https://github.com/top-think/think-multi-app.git |
| `topthink/think-orm` | `v4.0.51` | `Apache-2.0` | https://github.com/top-think/think-orm.git |
| `topthink/think-validate` | `v3.0.7` | `Apache-2.0` | https://github.com/top-think/think-validate.git |

## Non-default and compound license entries

The following deduplicated package/version entries are outside the common MIT, Apache-2.0, BSD-2-Clause, BSD-3-Clause, ISC and 0BSD set. The full inventory, including every common-license entry, is in `RELEASE_SBOM.spdx.json`.

| Ecosystem | Package | Version | License | Source |
|---|---|---|---|---|
| pc | `chownr` | `3.0.0` | `BlueOak-1.0.0` | https://registry.npmmirror.com/chownr/-/chownr-3.0.0.tgz |
| pc | `glob` | `13.0.6` | `BlueOak-1.0.0` | https://registry.npmmirror.com/glob/-/glob-13.0.6.tgz |
| pc | `isexe` | `4.0.0` | `BlueOak-1.0.0` | https://registry.npmmirror.com/isexe/-/isexe-4.0.0.tgz |
| quality-js | `jackspeak` | `3.4.3` | `BlueOak-1.0.0` | https://registry.npmjs.org/jackspeak/3.4.3 |
| pc | `lru-cache` | `11.5.2` | `BlueOak-1.0.0` | https://registry.npmmirror.com/lru-cache/-/lru-cache-11.5.2.tgz |
| pc | `minimatch` | `10.2.6` | `BlueOak-1.0.0` | https://registry.npmmirror.com/minimatch/-/minimatch-10.2.6.tgz |
| quality-js | `minipass` | `7.1.3` | `BlueOak-1.0.0` | https://registry.npmjs.org/minipass/7.1.3 |
| quality-js | `package-json-from-dist` | `1.0.1` | `BlueOak-1.0.0` | https://registry.npmjs.org/package-json-from-dist/1.0.1 |
| quality-js | `path-scurry` | `1.11.1` | `BlueOak-1.0.0` | https://registry.npmjs.org/path-scurry/1.11.1 |
| pc | `path-scurry` | `2.0.2` | `BlueOak-1.0.0` | https://registry.npmmirror.com/path-scurry/-/path-scurry-2.0.2.tgz |
| web | `sax` | `1.6.1` | `BlueOak-1.0.0` | https://registry.npmjs.org/sax/1.6.1 |
| pc | `tar` | `7.5.22` | `BlueOak-1.0.0` | https://registry.npmmirror.com/tar/-/tar-7.5.22.tgz |
| pc | `yallist` | `5.0.0` | `BlueOak-1.0.0` | https://registry.npmmirror.com/yallist/-/yallist-5.0.0.tgz |
| pc | `node-forge` | `1.4.0` | `BSD-3-Clause OR GPL-2.0` | https://registry.npmmirror.com/node-forge/-/node-forge-1.4.0.tgz |
| web | `spdx-exceptions` | `2.5.0` | `CC-BY-3.0` | https://registry.npmjs.org/spdx-exceptions/2.5.0 |
| pc | `caniuse-lite` | `1.0.30001807` | `CC-BY-4.0` | https://registry.npmmirror.com/caniuse-lite/-/caniuse-lite-1.0.30001807.tgz |
| web | `caniuse-lite` | `1.0.30001806` | `CC-BY-4.0` | https://registry.npmjs.org/caniuse-lite/1.0.30001806 |
| web | `@csstools/selector-specificity` | `2.2.0` | `CC0-1.0` | https://registry.npmjs.org/%40csstools%2Fselector-specificity/2.2.0 |
| pc | `@speed-highlight/core` | `1.2.23` | `CC0-1.0` | https://registry.npmmirror.com/@speed-highlight/core/-/core-1.2.23.tgz |
| pc | `mdn-data` | `2.0.28` | `CC0-1.0` | https://registry.npmmirror.com/mdn-data/-/mdn-data-2.0.28.tgz |
| pc | `mdn-data` | `2.27.1` | `CC0-1.0` | https://registry.npmmirror.com/mdn-data/-/mdn-data-2.27.1.tgz |
| web | `mdn-data` | `2.0.14` | `CC0-1.0` | https://registry.npmjs.org/mdn-data/2.0.14 |
| web | `spdx-license-ids` | `3.0.23` | `CC0-1.0` | https://registry.npmjs.org/spdx-license-ids/3.0.23 |
| uniapp | `string-hash` | `1.1.3` | `CC0-1.0` | https://registry.npmmirror.com/string-hash/-/string-hash-1.1.3.tgz |
| uniapp | `pako` | `1.0.11` | `MIT AND Zlib` | https://registry.npmmirror.com/pako/-/pako-1.0.11.tgz |
| pc | `@cloudflare/kv-asset-handler` | `0.4.2` | `MIT OR Apache-2.0` | https://registry.npmmirror.com/@cloudflare/kv-asset-handler/-/kv-asset-handler-0.4.2.tgz |
| web | `JSONStream` | `1.3.5` | `MIT OR Apache-2.0` | https://registry.npmjs.org/JSONStream/1.3.5 |
| pc | `type-fest` | `5.8.0` | `MIT OR CC0-1.0` | https://registry.npmmirror.com/type-fest/-/type-fest-5.8.0.tgz |
| uniapp | `type-fest` | `0.21.3` | `MIT OR CC0-1.0` | https://registry.npmmirror.com/type-fest/-/type-fest-0.21.3.tgz |
| web | `type-fest` | `0.18.1` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/0.18.1 |
| web | `type-fest` | `0.20.2` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/0.20.2 |
| web | `type-fest` | `0.6.0` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/0.6.0 |
| web | `type-fest` | `0.8.1` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/0.8.1 |
| web | `type-fest` | `1.4.0` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/1.4.0 |
| web | `type-fest` | `4.41.0` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/4.41.0 |
| pc | `@csstools/selector-resolve-nested` | `3.1.0` | `MIT-0` | https://registry.npmmirror.com/@csstools/selector-resolve-nested/-/selector-resolve-nested-3.1.0.tgz |
| pc | `@csstools/selector-specificity` | `5.0.0` | `MIT-0` | https://registry.npmmirror.com/@csstools/selector-specificity/-/selector-specificity-5.0.0.tgz |
| pc | `postcss-nesting` | `13.0.2` | `MIT-0` | https://registry.npmmirror.com/postcss-nesting/-/postcss-nesting-13.0.2.tgz |
| quality-js | `lightningcss` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss/1.33.0 |
| quality-js | `lightningcss-android-arm64` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-android-arm64/1.33.0 |
| quality-js | `lightningcss-darwin-arm64` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-darwin-arm64/1.33.0 |
| quality-js | `lightningcss-darwin-x64` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-darwin-x64/1.33.0 |
| quality-js | `lightningcss-freebsd-x64` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-freebsd-x64/1.33.0 |
| quality-js | `lightningcss-linux-arm-gnueabihf` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-linux-arm-gnueabihf/1.33.0 |
| quality-js | `lightningcss-linux-arm64-gnu` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-linux-arm64-gnu/1.33.0 |
| quality-js | `lightningcss-linux-arm64-musl` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-linux-arm64-musl/1.33.0 |
| quality-js | `lightningcss-linux-x64-gnu` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-linux-x64-gnu/1.33.0 |
| quality-js | `lightningcss-linux-x64-musl` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-linux-x64-musl/1.33.0 |
| quality-js | `lightningcss-win32-arm64-msvc` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-win32-arm64-msvc/1.33.0 |
| quality-js | `lightningcss-win32-x64-msvc` | `1.33.0` | `MPL-2.0` | https://registry.npmjs.org/lightningcss-win32-x64-msvc/1.33.0 |
| web | `dompurify` | `3.4.17` | `MPL-2.0 OR Apache-2.0` | https://registry.npmjs.org/dompurify/3.4.17 |
| web | `argparse` | `2.0.1` | `Python-2.0` | https://registry.npmjs.org/argparse/2.0.1 |

## Obtaining license texts

The SPDX identifiers above resolve through https://spdx.org/licenses/. Exact package sources and versions are recorded in `RELEASE_SBOM.spdx.json`; Composer-installed packages also retain their upstream license files in `server/vendor` after deployment. For a redistributed binary or image, include this file, the SBOM and any additional license files required by the packages actually embedded in that artifact.
