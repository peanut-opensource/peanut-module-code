# Peanut Module Code development entry

This checkout is the application `peanut-business/peanut-module-code`. Use the source, dependency locks and public documentation shipped here; no separate maintainer repository or workstation is required for application or module development.

## Start here

Read [the development standard](docs/development/standard.md), then locate the relevant contract through [the documentation index](docs/README.md). Use [the quality-tool instructions](tools/quality/README.md) and each affected client's own package scripts for validation. Read the affected implementation, module declarations and existing tests before changing them. Do not infer a completed capability or a passed test from a document or configuration alone.

Confirm the actual checkout, branch, worktree changes and installed dependency paths before editing. Preserve existing work and use a separate feature branch or worktree for source changes. Never overwrite unrelated changes or force shared history. A change request does not implicitly authorize publication, deployment, paid operations, or changes to customer data.

## Project tool environment

Read [the project toolchain guide](docs/development/project-toolchain.md). `tools/toolchain.json` owns the project tool requirements; `.local/toolchain/host.json` privately binds this machine's installed programs and caches using `tools/toolchain.local.example.json`. Do not commit machine paths or credentials. pnpm and Composer requirements come from their existing version authorities, not duplicated guesses.

Use `python3 scripts/project-env requirements` to read requirements, explicitly `prepare` the registered local environment, then run authorized commands through `python3 scripts/project-env exec -- ...`. `doctor` is read-only and may request preparation; neither doctor nor exec installs missing tools. Preparation is not permission to test, build, start services or publish. Preserve downstream toolchain edits with the normal managed-file conflict process.

GitHub CI is optional and separately versioned through Recipes; this application does not need GitHub or a maintainer's private repository. The toolchain guide distinguishes the catalog default from a pending Recipe version. Do not overwrite existing workflows or Recipe baselines to adopt an update.

## Source and dependency boundaries

The backend application and business modules live under `server/`; `web`, `platform`, `pc` and `uniapp` are distinct clients when included in this application profile. Module dependencies, public capabilities, routes and frontend contributions come from the checked-in declarations. Cross-module calls use explicitly exported operations or stable results, not another module's internal Models, repositories or private tables. Reuse the existing container, ORM, authorization, errors, tasks and upgrade mechanism rather than introducing parallel frameworks.

Install dependencies with the relevant native lock and declared package manager before validating code. Missing dependencies or an unexpected source path must be reported, not silently replaced with a different version or a developer's global installation. Development checks in `tools/quality` are separate from runtime dependencies. Do not commit installed `vendor`, `node_modules`, caches, secrets or build logs.

## Changes, checks and upgrades

Preserve the documented identity, tenant, permission, transaction, soft-delete, file and error contracts. Keep source, templates, imports, types and public documentation consistent. Regenerate affected API/SDK data and module artifacts through their existing tools; never edit checksums to make a check pass. Use the applicable configured formatter, compile/type checks and positive/negative behavior tests. Report the actual source and dependency identity, command, exit status, failures and work not executed. Static or synthetic tests do not prove live database, browser or recovery behavior.

`.peanut/application-manifest.json` records managed and application-owned paths and baseline digests. Do not mark an entire application or client as custom to prevent upstream fixes. New customer modules and pages remain customer work; local changes to managed files require the documented conflict-aware upgrade process. This shared entry is managed with the same baseline protection; do not overwrite local edits during an upgrade.

Use the shipped installation instructions for a first installation and the `scripts/scaffold-upgrade` flow for upstream source changes and `scripts/deploy-update.sh` for product Git updates. Do not re-run first-install initialization as an upgrade, execute unverified target-package code, or replace real upgrade/recovery checks with a fresh database. Inspect `resources/project-resources.json` and obtain the required resource authorization and exclusive lease before running services or accessing a database. Never borrow an unknown/shared resource or erase existing data to simplify a test.
