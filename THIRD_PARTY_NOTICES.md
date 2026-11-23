# Peanut Module Code Third-Party Notices

Generated for Peanut Module Code 1.0.0 on 2026-11-23.

Application source: https://github.com/peanut-opensource/peanut-module-code. See the application's LICENSE and NOTICE for its own terms and attribution. Third-party components remain governed by their own licenses.

## Distribution boundary

- This inventory covers the application source and components resolved in the locks listed below. It does not describe the contents of a particular deployed image or archive.
- The application Composer lock includes production and development dependencies. The four client locks cover static build inputs. The two quality-tool locks cover development tooling.
- Exact package versions, licenses and sources for all seven dependency graphs are listed in `RELEASE_SBOM.spdx.json` (SPDX 2.3).

| Dependency graph | Lock entries |
|---|---:|
| composer | 115 |
| web | 858 |
| platform | 147 |
| pc | 803 |
| uniapp | 822 |
| quality-composer | 38 |
| quality-js | 144 |
- Each installed dependency may include additional license or notice files. Those files remain authoritative for that dependency and must not be removed from redistributed dependency archives.

## Material source and framework attributions

| Source | License | Attribution and use in this release |
|---|---|---|
| Arco Design Pro Vue | MIT | The initial management client used Arco Design Pro Vue material; applicable upstream MIT attribution is retained. Source: https://github.com/arco-design/arco-design-pro-vue |
| LikeAdmin 1.9.4 | MIT | Used as the documented behavioral parity reference. This notice does not claim the application is a clean-room implementation. Source: https://github.com/likeadmin-likeshop/likeadmin_php |
| ThinkPHP 8 | Apache-2.0 | Backend framework. Its upstream notice is also retained at `server/LICENSE.txt`. Source: https://github.com/top-think/framework |
| `peanut-admin/core` | Apache-2.0 | Composer core package locked at 5.1.3-rc.23 (cf56b4adc42f021444e42c1a8be3ec66b06a665d). Source: https://github.com/peanut-business/peanut-admin-core-php |
| Peanut Admin Core Web | Apache-2.0 | npm core packages locked as @peanut-admin/client@5.1.3-rc.24, @peanut-admin/vue@5.1.3-rc.24, @peanut-admin/ui-vue@5.1.3-rc.24, @peanut-admin/nuxt@5.1.3-rc.24, @peanut-admin/uniapp@5.1.3-rc.24, @peanut-admin/testing@5.1.3-rc.24. Source: https://github.com/peanut-business/peanut-admin-core-web |

## License handling

- MIT, ISC, BSD, 0BSD, MIT-0, Apache-2.0 and Zlib notices are preserved through this file, the SPDX inventory and the upstream package sources recorded there.
- License expressions and attributions in the inventory apply to the locked versions listed there. Preserve applicable attributions and follow upstream license terms for the components included in any redistribution.
- For components offered under multiple licenses, the SPDX inventory preserves the alternatives declared by the upstream package.

## Locked-license summary

| Ecosystem | Declared license | Lock entries |
|---|---|---:|
| composer | `Apache-2.0` | 40 |
| composer | `BSD-3-Clause` | 24 |
| composer | `MIT` | 51 |
| pc | `0BSD` | 1 |
| pc | `Apache-2.0` | 21 |
| pc | `BlueOak-1.0.0` | 12 |
| pc | `BSD-2-Clause` | 4 |
| pc | `BSD-3-Clause` | 10 |
| pc | `BSD-3-Clause OR GPL-2.0` | 1 |
| pc | `CC-BY-4.0` | 2 |
| pc | `CC0-1.0` | 1 |
| pc | `ISC` | 41 |
| pc | `LicenseRef-BSD-ambiguous` | 1 |
| pc | `MIT` | 691 |
| pc | `MIT OR Apache-2.0` | 1 |
| pc | `MIT OR CC0-1.0` | 1 |
| pc | `MIT-0` | 3 |
| pc | `MPL-2.0` | 12 |
| pc | `MPL-2.0 OR Apache-2.0` | 1 |
| platform | `Apache-2.0` | 4 |
| platform | `BSD-2-Clause` | 1 |
| platform | `BSD-3-Clause` | 2 |
| platform | `ISC` | 1 |
| platform | `MIT` | 139 |
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
| uniapp | `ISC` | 40 |
| uniapp | `MIT` | 697 |
| uniapp | `MIT AND Zlib` | 1 |
| uniapp | `MIT OR CC0-1.0` | 1 |
| web | `0BSD` | 3 |
| web | `Apache-2.0` | 19 |
| web | `BlueOak-1.0.0` | 1 |
| web | `BSD-2-Clause` | 23 |
| web | `BSD-3-Clause` | 11 |
| web | `CC-BY-3.0` | 1 |
| web | `CC-BY-4.0` | 1 |
| web | `CC0-1.0` | 3 |
| web | `ISC` | 56 |
| web | `MIT` | 730 |
| web | `MIT OR Apache-2.0` | 1 |
| web | `MIT OR CC0-1.0` | 6 |
| web | `MPL-2.0 OR Apache-2.0` | 2 |
| web | `Python-2.0` | 1 |

## Composer production packages

The application Composer lock lists 87 production packages. Development dependencies are reported separately in the SBOM.

| Package | Version | License | Source |
|---|---|---|---|
| `aliyuncs/oss-sdk-php` | `v2.7.3` | `MIT` | https://github.com/aliyun/aliyun-oss-php-sdk.git |
| `aws/aws-crt-php` | `v1.2.7` | `Apache-2.0` | https://github.com/awslabs/aws-crt-php.git |
| `aws/aws-sdk-php` | `3.401.5` | `Apache-2.0` | https://github.com/aws/aws-sdk-php.git |
| `composer/semver` | `3.4.4` | `MIT` | https://github.com/composer/semver.git |
| `custom/custom-ai-access` | `1.0.1-rc.3` | `Apache-2.0` | NOASSERTION |
| `custom/custom-ai-assistant` | `1.2.5-rc.2` | `Apache-2.0` | NOASSERTION |
| `dragonmantank/cron-expression` | `v3.6.0` | `MIT` | https://github.com/dragonmantank/cron-expression.git |
| `firebase/php-jwt` | `v7.1.0` | `BSD-3-Clause` | https://github.com/googleapis/php-jwt.git |
| `guzzlehttp/command` | `1.5.3` | `MIT` | https://github.com/guzzle/command.git |
| `guzzlehttp/guzzle` | `7.16.5` | `MIT` | https://github.com/guzzle/guzzle.git |
| `guzzlehttp/guzzle-services` | `1.7.3` | `MIT` | https://github.com/guzzle/guzzle-services.git |
| `guzzlehttp/promises` | `2.6.2` | `MIT` | https://github.com/guzzle/promises.git |
| `guzzlehttp/psr7` | `2.13.6` | `MIT` | https://github.com/guzzle/psr7.git |
| `guzzlehttp/uri-template` | `v1.0.10` | `MIT` | https://github.com/guzzle/uri-template.git |
| `hyperf/context` | `v3.2.0` | `MIT` | https://github.com/hyperf/context.git |
| `hyperf/contract` | `v3.2.0` | `MIT` | https://github.com/hyperf/contract.git |
| `hyperf/engine` | `v2.15.0` | `MIT` | https://github.com/hyperf/engine.git |
| `hyperf/engine-contract` | `v1.14.0` | `MIT` | https://github.com/hyperf/engine-contract.git |
| `hyperf/macroable` | `v3.2.0` | `MIT` | https://github.com/hyperf/macroable.git |
| `hyperf/pimple` | `v2.2.2` | `MIT` | https://github.com/hyperf-cloud/pimple-integration.git |
| `league/flysystem` | `3.35.3` | `MIT` | https://github.com/thephpleague/flysystem.git |
| `league/flysystem-local` | `3.31.0` | `MIT` | https://github.com/thephpleague/flysystem-local.git |
| `league/mime-type-detection` | `1.17.0` | `MIT` | https://github.com/thephpleague/mime-type-detection.git |
| `league/uri` | `7.8.1` | `MIT` | https://github.com/thephpleague/uri.git |
| `league/uri-interfaces` | `7.8.1` | `MIT` | https://github.com/thephpleague/uri-interfaces.git |
| `masterminds/html5` | `2.11.0` | `MIT` | https://github.com/Masterminds/html5-php.git |
| `mtdowling/jmespath.php` | `2.9.2` | `MIT` | https://github.com/jmespath/jmespath.php.git |
| `openspout/openspout` | `v4.33.0` | `MIT` | https://github.com/openspout/openspout.git |
| `opis/json-schema` | `2.6.0` | `Apache-2.0` | https://github.com/opis/json-schema.git |
| `opis/string` | `2.1.0` | `Apache-2.0` | https://github.com/opis/string.git |
| `opis/uri` | `1.1.0` | `Apache-2.0` | https://github.com/opis/uri.git |
| `peanut-admin/core` | `5.1.3-rc.23` | `Apache-2.0` | https://github.com/peanut-business/peanut-admin-core-php.git |
| `peanut-business/artifact-revision` | `4.0.0` | `Apache-2.0` | NOASSERTION |
| `peanut-business/entitlement-quota` | `4.0.0` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-article` | `1.0.21-rc.18` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-chart` | `1.0.3` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-chart-examples` | `1.0.5-rc.1` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-collaboration` | `1.0.21-rc.3` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-content-tools` | `1.0.4-rc.1` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-document` | `1.0.28-rc.10` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-file` | `1.0.50-rc.19` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-identity` | `4.0.1-rc.15` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-import-export` | `1.0.37-rc.7` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-integration` | `1.0.31-rc.2` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-member` | `1.0.28-rc.7` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-notification` | `1.0.33-rc.2` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-oauth` | `1.0.13-rc.4` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-ops` | `1.0.35-rc.6` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-payment` | `1.0.30-rc.3` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-reference-codes` | `1.0.29-rc.4` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-rich-text` | `1.0.19-rc.1` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-settings` | `1.0.30-rc.1` | `Apache-2.0` | NOASSERTION |
| `peanut-business/peanut-task` | `1.0.36-rc.7` | `Apache-2.0` | NOASSERTION |
| `peanut-business/workflow` | `4.0.2-rc.7` | `Apache-2.0` | NOASSERTION |
| `pimple/pimple` | `v3.6.2` | `MIT` | https://github.com/silexphp/Pimple.git |
| `psr/container` | `2.0.2` | `MIT` | https://github.com/php-fig/container.git |
| `psr/event-dispatcher` | `1.0.0` | `MIT` | https://github.com/php-fig/event-dispatcher.git |
| `psr/http-client` | `1.0.3` | `MIT` | https://github.com/php-fig/http-client.git |
| `psr/http-factory` | `1.1.0` | `MIT` | https://github.com/php-fig/http-factory.git |
| `psr/http-message` | `2.0` | `MIT` | https://github.com/php-fig/http-message.git |
| `psr/log` | `3.0.2` | `MIT` | https://github.com/php-fig/log.git |
| `psr/simple-cache` | `3.0.0` | `MIT` | https://github.com/php-fig/simple-cache.git |
| `qcloud/cos-sdk-v5` | `v2.6.17` | `MIT` | https://github.com/tencentyun/cos-php-sdk-v5.git |
| `qiniu/php-sdk` | `v7.4.0` | `MIT` | https://github.com/qiniu/php-sdk.git |
| `ralouphie/getallheaders` | `3.0.3` | `MIT` | https://github.com/ralouphie/getallheaders.git |
| `symfony/deprecation-contracts` | `v3.8.1` | `MIT` | https://github.com/symfony/deprecation-contracts.git |
| `symfony/event-dispatcher` | `v7.4.17` | `MIT` | https://github.com/symfony/event-dispatcher.git |
| `symfony/event-dispatcher-contracts` | `v3.8.1` | `MIT` | https://github.com/symfony/event-dispatcher-contracts.git |
| `symfony/filesystem` | `v7.4.23` | `MIT` | https://github.com/symfony/filesystem.git |
| `symfony/html-sanitizer` | `v7.4.23` | `MIT` | https://github.com/symfony/html-sanitizer.git |
| `symfony/lock` | `v7.4.23` | `MIT` | https://github.com/symfony/lock.git |
| `symfony/options-resolver` | `v7.4.22` | `MIT` | https://github.com/symfony/options-resolver.git |
| `symfony/polyfill-ctype` | `v1.37.0` | `MIT` | https://github.com/symfony/polyfill-ctype.git |
| `symfony/polyfill-mbstring` | `v1.38.2` | `MIT` | https://github.com/symfony/polyfill-mbstring.git |
| `symfony/polyfill-php80` | `v1.43.0` | `MIT` | https://github.com/symfony/polyfill-php80.git |
| `symfony/rate-limiter` | `v7.4.23` | `MIT` | https://github.com/symfony/rate-limiter.git |
| `topthink/framework` | `v8.1.4` | `Apache-2.0` | https://github.com/top-think/framework.git |
| `topthink/think-container` | `v3.0.2` | `Apache-2.0` | https://github.com/top-think/think-container.git |
| `topthink/think-filesystem` | `v3.0.0` | `Apache-2.0` | https://github.com/top-think/think-filesystem.git |
| `topthink/think-helper` | `v3.1.12` | `Apache-2.0` | https://github.com/top-think/think-helper.git |
| `topthink/think-migration` | `v3.1.1` | `Apache-2.0` | https://github.com/top-think/think-migration.git |
| `topthink/think-multi-app` | `v1.1.1` | `Apache-2.0` | https://github.com/top-think/think-multi-app.git |
| `topthink/think-orm` | `v4.0.51` | `Apache-2.0` | https://github.com/top-think/think-orm.git |
| `topthink/think-validate` | `v3.0.7` | `Apache-2.0` | https://github.com/top-think/think-validate.git |
| `yansongda/artful` | `v1.1.5` | `MIT` | https://github.com/yansongda/artful.git |
| `yansongda/pay` | `v3.7.20` | `MIT` | https://github.com/yansongda/pay.git |
| `yansongda/supports` | `v4.0.12` | `MIT` | https://github.com/yansongda/supports.git |

## Non-default and compound license entries

The following deduplicated package/version entries are outside the common MIT, Apache-2.0, BSD-2-Clause, BSD-3-Clause, ISC and 0BSD set. The full inventory, including every common-license entry, is in `RELEASE_SBOM.spdx.json`.

| Ecosystem | Package | Version | License | Source |
|---|---|---|---|---|
| pc | `@isaacs/nonrecursive-glob-match` | `1.1.0` | `BlueOak-1.0.0` | https://registry.npmjs.org/%40isaacs%2Fnonrecursive-glob-match/1.1.0 |
| pc | `chownr` | `3.0.0` | `BlueOak-1.0.0` | https://registry.npmjs.org/chownr/3.0.0 |
| pc | `glob` | `14.0.6` | `BlueOak-1.0.0` | https://registry.npmjs.org/glob/14.0.6 |
| pc | `isexe` | `4.0.0` | `BlueOak-1.0.0` | https://registry.npmjs.org/isexe/4.0.0 |
| quality-js | `jackspeak` | `3.4.3` | `BlueOak-1.0.0` | https://registry.npmjs.org/jackspeak/3.4.3 |
| pc | `lru-cache` | `11.5.2` | `BlueOak-1.0.0` | https://registry.npmjs.org/lru-cache/11.5.2 |
| pc | `lru-cache` | `11.5.3` | `BlueOak-1.0.0` | https://registry.npmjs.org/lru-cache/11.5.3 |
| pc | `minimatch` | `10.2.6` | `BlueOak-1.0.0` | https://registry.npmjs.org/minimatch/10.2.6 |
| pc | `minimatch` | `11.0.9` | `BlueOak-1.0.0` | https://registry.npmjs.org/minimatch/11.0.9 |
| quality-js | `minipass` | `7.1.3` | `BlueOak-1.0.0` | https://registry.npmjs.org/minipass/7.1.3 |
| quality-js | `package-json-from-dist` | `1.0.1` | `BlueOak-1.0.0` | https://registry.npmjs.org/package-json-from-dist/1.0.1 |
| pc | `path-scurry` | `3.0.0` | `BlueOak-1.0.0` | https://registry.npmjs.org/path-scurry/3.0.0 |
| quality-js | `path-scurry` | `1.11.1` | `BlueOak-1.0.0` | https://registry.npmjs.org/path-scurry/1.11.1 |
| web | `sax` | `1.6.1` | `BlueOak-1.0.0` | https://registry.npmjs.org/sax/1.6.1 |
| pc | `tar` | `7.5.22` | `BlueOak-1.0.0` | https://registry.npmjs.org/tar/7.5.22 |
| pc | `yallist` | `5.0.0` | `BlueOak-1.0.0` | https://registry.npmjs.org/yallist/5.0.0 |
| pc | `node-forge` | `1.4.0` | `BSD-3-Clause OR GPL-2.0` | https://registry.npmjs.org/node-forge/1.4.0 |
| web | `spdx-exceptions` | `2.5.0` | `CC-BY-3.0` | https://registry.npmjs.org/spdx-exceptions/2.5.0 |
| pc | `caniuse-lite` | `1.0.30001807` | `CC-BY-4.0` | https://registry.npmjs.org/caniuse-lite/1.0.30001807 |
| pc | `caniuse-lite` | `1.0.30001827` | `CC-BY-4.0` | https://registry.npmjs.org/caniuse-lite/1.0.30001827 |
| web | `caniuse-lite` | `1.0.30001806` | `CC-BY-4.0` | https://registry.npmjs.org/caniuse-lite/1.0.30001806 |
| web | `@csstools/selector-specificity` | `2.2.0` | `CC0-1.0` | https://registry.npmjs.org/%40csstools%2Fselector-specificity/2.2.0 |
| pc | `@speed-highlight/core` | `1.2.23` | `CC0-1.0` | https://registry.npmjs.org/%40speed-highlight%2Fcore/1.2.23 |
| web | `mdn-data` | `2.0.14` | `CC0-1.0` | https://registry.npmjs.org/mdn-data/2.0.14 |
| web | `spdx-license-ids` | `3.0.23` | `CC0-1.0` | https://registry.npmjs.org/spdx-license-ids/3.0.23 |
| uniapp | `string-hash` | `1.1.3` | `CC0-1.0` | https://registry.npmjs.org/string-hash/1.1.3 |
| pc | `speakingurl` | `14.0.1` | `LicenseRef-BSD-ambiguous` | https://registry.npmjs.org/speakingurl/14.0.1 |
| uniapp | `pako` | `1.0.11` | `MIT AND Zlib` | https://registry.npmjs.org/pako/1.0.11 |
| pc | `@cloudflare/kv-asset-handler` | `0.4.2` | `MIT OR Apache-2.0` | https://registry.npmjs.org/%40cloudflare%2Fkv-asset-handler/0.4.2 |
| web | `JSONStream` | `1.3.5` | `MIT OR Apache-2.0` | https://registry.npmjs.org/JSONStream/1.3.5 |
| pc | `type-fest` | `5.8.0` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/5.8.0 |
| uniapp | `type-fest` | `0.21.3` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/0.21.3 |
| web | `type-fest` | `0.18.1` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/0.18.1 |
| web | `type-fest` | `0.20.2` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/0.20.2 |
| web | `type-fest` | `0.6.0` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/0.6.0 |
| web | `type-fest` | `0.8.1` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/0.8.1 |
| web | `type-fest` | `1.4.0` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/1.4.0 |
| web | `type-fest` | `4.41.0` | `MIT OR CC0-1.0` | https://registry.npmjs.org/type-fest/4.41.0 |
| pc | `@csstools/selector-resolve-nested` | `3.1.0` | `MIT-0` | https://registry.npmjs.org/%40csstools%2Fselector-resolve-nested/3.1.0 |
| pc | `@csstools/selector-specificity` | `5.0.0` | `MIT-0` | https://registry.npmjs.org/%40csstools%2Fselector-specificity/5.0.0 |
| pc | `postcss-nesting` | `13.0.2` | `MIT-0` | https://registry.npmjs.org/postcss-nesting/13.0.2 |
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
| web | `dompurify` | `3.4.14` | `MPL-2.0 OR Apache-2.0` | https://registry.npmjs.org/dompurify/3.4.14 |
| web | `argparse` | `2.0.1` | `Python-2.0` | https://registry.npmjs.org/argparse/2.0.1 |

## Obtaining license texts

The SPDX identifiers above resolve through https://spdx.org/licenses/. Exact package sources and versions are recorded in `RELEASE_SBOM.spdx.json`; Composer-installed packages also retain their upstream license files in `server/vendor` after deployment. For a redistributed binary or image, include this file, the SBOM and any additional license files required by the packages actually embedded in that artifact.
