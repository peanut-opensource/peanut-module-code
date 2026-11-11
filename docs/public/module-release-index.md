# Official GitHub Module releases

The public module author repository is peanut-opensource/peanut-module-code:
https://github.com/peanut-opensource/peanut-module-code.

All peanut.* modules have independently versioned TAR artifacts containing PHP, migrations, permissions, menus, API contracts and any declared client source. Custom.* application-specific modules are excluded. npm/Packagist resolve dependencies of a verified module, not the mixed PHP/Vue module package itself.

Every catalog snapshot is a GitHub Release with a fixed tag: v1.0.0 for stable, v1.0.0-rc.1 for prerelease. It contains module-release-index.json and a separately versioned TAR for each module. The index protocol is peanut.module-release-index.v1. It binds the fixed source commit, repository and tag, plus independent module key, version, asset, SHA-256, size, Edition, Kernel constraint and dependencies. An unchanged module need not change its own version when the catalog snapshot changes.

The index alone is NOT a trust anchor. CLI must verify the index attachment bytes and each TAR bytes against the matching official GitHub API Release assets[].digest (sha256), and verify the expected exact official download URLs. The index SHA is an additional consistency check. Draft, mismatched channel, unknown publisher, missing digest, unsafe names/paths, incompatible Edition or install downgrade are rejected. A local TAR remains available with separately trusted SHA-256.

Default selection uses the latest compatible published stable module. Explicit prerelease channel or version is needed for RC modules. The default application module composition is separate from the scaffold source; it is a proposal for the existing native module installer, not an automatic permission grant. Installed modules are governed by the application's lock and database lifecycle, not by the scaffold's default module versions.

Release preparation at the fixed source commit uses the existing native peanut module check and peanut module pack operations:

    python3 scripts/build-module-release-index --source-root /absolute/peanut-module-code --output /absolute/new-release-dir --tag v1.0.0-rc.1 --channel prerelease --source-commit YOUR_FIXED_40HEX_COMMIT

This producer refuses module source drift (historical recorded-baseline metadata is allowed), inspects TAR path safety and native inventory and writes the index. It does not publish or grant permissions; GitHub release publication remains a separate approved operation. RC catalog qualification does not assert full business or production E2E.
