# Official GitHub Module releases

The public module author repository is peanut-opensource/peanut-module-code:
https://github.com/peanut-opensource/peanut-module-code.

All peanut.* modules have independently versioned TAR artifacts containing PHP, migrations, permissions, menus, API contracts and any declared client source. Custom.* application-specific modules are excluded. npm/Packagist resolve dependencies of a verified module, not the mixed PHP/Vue module package itself.

Every catalog snapshot is a GitHub Release with a fixed tag: v1.0.0 for stable, v1.0.0-rc.1 for prerelease. It contains module-release-index.json and a separately versioned TAR for each module. The index protocol is peanut.module-release-index.v1. It binds the fixed source commit, repository and tag, plus independent module key, version, asset, SHA-256, size, Edition, Kernel constraint and dependencies. An unchanged module need not change its own version when the catalog snapshot changes.

The index alone is NOT a trust anchor. CLI must verify the index attachment bytes and each TAR bytes against the matching official GitHub API Release assets[].digest (sha256), and verify the expected exact official download URLs. The index SHA is an additional consistency check. Draft, mismatched channel, unknown publisher, missing digest, unsafe names/paths, incompatible Edition or install downgrade are rejected. A local TAR remains available with separately trusted SHA-256.

Default selection uses the latest compatible published stable module. Explicit prerelease channel or version is needed for RC modules. The default application module composition is separate from the scaffold source; it is a proposal for the existing native module installer, not an automatic permission grant. Installed modules are governed by the application's lock and database lifecycle, not by the scaffold's default module versions.

Release preparation at the fixed source commit uses the existing native check and pack commands. The producer invokes `server/module`, the same JSON bridge used by `peanut module`; it does not boot a separate authoring implementation or install the CLI from an unpinned online source. Run with the selected application's Edition (the author repository is multi-tenant):

    PHP_DEPLOYMENT_MODE=multi-tenant PHP_APP_ENV=development PHP_APP_DEBUG=true \
      python3 scripts/build-module-release-index --source-root /absolute/peanut-module-code --output /absolute/new-release-dir --tag v1.0.0-rc.1 --channel prerelease --source-commit YOUR_FIXED_40HEX_COMMIT

The producer requires a full Git checkout at that SHA, checks it again after packaging, and refuses source drift (historical recorded-baseline metadata is allowed). It checks the selected catalog's dependency closure with native `PackageCheck::dependencyOrder`, including version constraints and cycles; a dependency must also cover every Edition declared by its consumer. The existing module boundary checker audits the selected official modules' public exports and foreign table access. Custom modules are available only as source ownership information, never selected or packaged for the official catalog.

Each module is packed once, then native check verifies that supplied archive and its independent SHA, including all seven author checks. The producer audits canonical manifests, single-module paths, TAR types, native inventory, size, secret filenames and Apache-2.0 declarations in module.json, Composer and every packaged frontend package.json. Existing packaged LICENSE/COPYING files must identify Apache 2.0. This is a check of the shipped declarations and files, not a grant of rights to undeclared third-party content. Neither the host application's LICENSE nor custom.* content is included or treated as an official-module license. Bundled third-party license changes require review before admission; this producer does not silently approve another license.

`.github/workflows/module-release.yml` has two explicit operations, with all module releases serialized:

- **Build (`publish=false`)**: the 40-hex `source_commit` must equal the selected workflow ref's SHA. Ubuntu 24.04 prepares PHP 8.3 with all locked runtime extensions (including bcmath), Python 3.12, and the SHA-verified Composer 2.10.2 from `scripts/project-composer`. Both `server/composer.lock` and `tools/quality/composer.lock` are validated and installed with no dev packages, plugins or application/test hooks. Missing or incompatible dependencies fail; platform requirements are never ignored. Native check/pack, public contracts and history audit must all succeed before uploading the TARs, index and `build-receipt.json`. Node/pnpm and frontend compilation are not used: these are source Module packages; the existing PHP bridge owns check/pack. The broad application toolchain's suggested Node/Python versions are not additional release dependencies. No DB, cache or application service is started.
- **Publish (`publish=true`)**: run on main with its exact current SHA, an approved existing annotated tag pointing to that SHA, and `qualification_run_id` for a successful build of the same SHA and workflow. The job downloads those CI originals and checks the receipt and run identity. It does not install dependencies or rebuild packages. Only this job gets `contents:write`; the short-lived GitHub token is passed only to GitHub steps, never to the packer or archives. The repository owner must first enable GitHub immutable releases and confirm it with repository variable `MODULE_RELEASE_IMMUTABLE=true`. The variable records that administrative approval; public `immutable:true` is independently required on readback.

`scripts/publish-module-release` audits every prior public and draft catalog with paginated GitHub APIs. The index's independent API digest and corresponding TAR asset metadata must agree. Any changed metadata or bytes for an existing key+version are rejected, even under a new catalog tag. Existing release tags are never overwritten; there is no clobber or automatic deletion. An API error is a failure, not an empty history. The publisher creates a draft, reads back every asset's exact official URL, size, API digest and bytes, then publishes and repeats the checks with `immutable:true`. A partial failure retains its original draft/tag/run for reconciliation; inspect that handle before taking any further publication action. Repeating a publish dispatch cannot replace it.

After the workflow is admitted on the default branch and a fixed source is selected, build without publishing:

```sh
gh workflow run module-release.yml --repo peanut-opensource/peanut-module-code --ref main \
  -f source_commit="$SOURCE_COMMIT" -f tag=v1.0.0-rc.1 -f channel=prerelease -f publish=false
gh run list --repo peanut-opensource/peanut-module-code --workflow module-release.yml --limit 5
gh run watch "$BUILD_RUN_ID" --repo peanut-opensource/peanut-module-code --exit-status
```

Only after the source/main/tag and public-release approvals, and the immutable-release setting, are satisfied:

```sh
gh workflow run module-release.yml --repo peanut-opensource/peanut-module-code --ref main \
  -f source_commit="$SOURCE_COMMIT" -f tag=v1.0.0-rc.1 -f channel=prerelease \
  -f publish=true -f qualification_run_id="$BUILD_RUN_ID"
```

The workflow must exist on the default branch before GitHub accepts its first workflow_dispatch. A dev push or a local artifact is not a CI/public-release result. Current source has 22 official modules in RC with a closed dependency graph. Filtering out prerelease module versions leaves 18 stable modules with 15 direct dependencies on the absent Identity; stable must fail before emitting an index. Do not relabel Identity or silently mix RC into stable. RC artifact checks do not assert full business, consumer installation, database or production E2E qualification.
