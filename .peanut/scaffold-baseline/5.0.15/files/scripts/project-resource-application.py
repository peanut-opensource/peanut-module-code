#!/usr/bin/env python3
"""Closed maintainer admission for one registered APP preinstall/first-install operation.

No command, authority directory, credential value, environment override or runtime
root is accepted from the caller. Ordinary APP installation remains its own CLI.
"""
from __future__ import annotations

import argparse
import contextlib
from datetime import datetime
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import runpy
import shlex
import stat
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parent.parent
PROTOCOL = "peanut.application-first-install-operation.v1"
BINDING = "application-operation"
CONTRACT = "application-operation-sha256"
STATE = "application-operation.json"
FAILED_INSTALL_OPERATION = "ex00005-mt-firstinstall-roletext-20261023"
FAILED_INSTALL_EVIDENCE_SHA256 = "6485c0b05a30d905644888fac232f54406cf29e40bd8542e71a15d38bee280f7"
FAILED_INSTALL_SOURCE_SHA256 = "e00f7f979bbc16b688340f7580156fcd5366d4964a002469d7a89de1d83b3251"


def need(value, message):
    if not value:
        raise RuntimeError(message)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def regular(path, private=False):
    path = Path(path)
    need(path.is_absolute() and path.resolve() == path and not path.is_symlink(), "unsafe operation file")
    info = path.stat()
    need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, "operation file must be regular and single-link")
    if private:
        need(info.st_uid == os.getuid() and stat.S_IMODE(info.st_mode) == 0o600, "private operation file must be owner-only")
    return path.read_bytes()


def git(root, *args):
    env = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
    env["GIT_OPTIONAL_LOCKS"] = "0"
    result = subprocess.run(["/usr/bin/git", "-C", str(root), *args], capture_output=True, check=False, env=env)
    need(result.returncode == 0 and not result.stderr.strip(), "operation Git identity unavailable")
    return result.stdout


def canonical(value):
    path = Path(value)
    need(path.is_absolute() and path.resolve() == path and not path.is_symlink()
         and not any(ord(char) < 32 for char in str(path)), "operation path is not canonical")
    return path


def json_unique(raw):
    def pairs(rows):
        result = {}
        for key, value in rows:
            need(key not in result, "duplicate operation JSON key")
            result[key] = value
        return result
    return json.loads(raw, object_pairs_hook=pairs)


def pristine_installation_status(raw):
    try:
        status = json_unique(raw)
    except (ValueError, UnicodeError) as error:
        raise RuntimeError("native first-install status response is not JSON; retain lease and child output digests") from error
    need(isinstance(status, dict) and status.get("state") == "uninstalled"
         and status.get("code") == "INSTALL_READY", "APP is not a pristine native first-install target")
    return status


def table(path, count):
    rows = [line.split("\t") for line in regular(path).decode().splitlines()]
    need(rows and all(len(row) == count for row in rows), "invalid operation lease table")
    need(len({row[0] for row in rows}) == len(rows), "duplicate operation lease table key")
    return rows


def resource_map(rows):
    result = {}
    for digest, key, value in rows:
        need(digest == sha((key + "\t" + value).encode()), "operation lease resource digest mismatch")
        result.setdefault(key, set()).add(value)
    return result


def project_authority():
    """Physical current registry and logical primary Project for review custody."""
    registry = canonical(os.environ.get("PEANUT_RESOURCE_REGISTRY", ""))
    registry_sha = sha(regular(registry))
    resolver = runpy.run_path(str(ROOT / "scripts/project-resource-registry"))
    resolved = resolver["maintainer_source"](registry_sha)
    need(resolved == registry, "operation registry authority mismatch")
    return registry, resolver["maintainer_project"](), resolver, registry_sha


def failed_install_review(operation, project, directory, metadata, resources, state, proof, digest):
    """Retire one evidenced failed status parse, without admitting an install or recovery."""
    need(operation["stable_resource_id"] == FAILED_INSTALL_OPERATION
         and state.get("phase") == operation["phase"] == "install"
         and state.get("status") == "UNKNOWN" and state.get("failure") == "JSONDecodeError"
         and state.get("actual_process_terminal") is True and state.get("child_pid") is None
         and type(state.get("child_exit_code")) is int and state["child_exit_code"] == 1,
         "historical failed install is not the exact stopped status parse")
    need(proof.get("verdict") == "FAILED_INSTALL_RELEASE_ONLY"
         and proof.get("failure_point") == "status_response_parse_before_installer"
         and proof.get("unknown_preserved") is True and proof.get("release_only") is True
         and proof.get("no_pending_process") is True
         and proof.get("status_stdout") == "NOT_AVAILABLE",
         "failed install review must preserve UNKNOWN without execution authority")
    evidence_ref = proof.get("original_execution_evidence", {})
    need(isinstance(evidence_ref, dict) and set(evidence_ref) == {"path", "sha256"}
         and evidence_ref["sha256"] == FAILED_INSTALL_EVIDENCE_SHA256,
         "failed install original evidence digest differs")
    evidence_path = canonical(evidence_ref["path"])
    need(project in evidence_path.parents, "failed install evidence must belong to Project")
    evidence_raw = regular(evidence_path, private=True)
    need(sha(evidence_raw) == FAILED_INSTALL_EVIDENCE_SHA256,
         "failed install original evidence changed")
    evidence = json_unique(evidence_raw)
    snapshots = evidence["native_state_snapshot"]
    need(set(snapshots) == {"application-operation.json", "metadata.tsv", "resources.tsv"},
         "failed install original lease snapshot incomplete")
    for name in (STATE, "metadata.tsv", "resources.tsv"):
        snapshot = snapshots[name]
        raw = regular(directory / name, private=name == STATE)
        need(snapshot["reference"]["sha256"] == sha(raw)
             and snapshot["exact_bytes_utf8"].encode() == raw,
             "failed install lease differs from original execution evidence")
    need(json_unique(snapshots[STATE]["exact_bytes_utf8"].encode()) == state
         and evidence["inputs"]["operation_id"] == FAILED_INSTALL_OPERATION
         and evidence["inputs"]["operation_digest"] == digest
         and evidence["inputs"]["owner"] == metadata["owner"]
         and evidence["inputs"]["Code_source"]["commit"] == operation["tool_source"]["commit"],
         "failed install execution input identity changed")
    source = git(ROOT, "show", operation["tool_source"]["commit"] + ":scripts/project-resource-application.py")
    need(sha(source) == FAILED_INSTALL_SOURCE_SHA256
         and operation["tool_source"]["files"]["scripts/project-resource-application.py"] == FAILED_INSTALL_SOURCE_SHA256,
         "failed install status-before-installer source differs")
    # The fixed historical source was reviewed: status accepted exit 1, parsed
    # JSON before dispatching the ordinary installer. Its exact hash is the gate.
    jobs = evidence["native_evidence"]
    expected = (("claim_terminal", evidence["claim_Job"], "completed", 0),
                ("entry_terminal", evidence["install_Job"], "failed", 1))
    need(evidence.get("claim_exit") == 0 and evidence["failure"].get("exit_code") == 1
         and evidence["failure"].get("native_failure") == "JSONDecodeError"
         and evidence["failure"].get("last_child_exit") == 1
         and evidence["failure"].get("actual_process_terminal") is True
         and evidence["failure"].get("child_pid") is None
         and evidence.get("actual_installer_count") == 0
         and evidence["budgets"].get("ordinary_installer_calls_all_history") == 0,
         "failed install execution receipt does not prove pre-installer failure")
    for kind, job_id, status, exit_code in expected:
        result = jobs[kind]["structuredContent"]
        items = result["output"]["items"]
        need(result.get("success") is True and len(items) == 1
             and items[0].get("job_id") == job_id and items[0].get("status") == status
             and items[0].get("terminal") is True
             and type(items[0].get("exit_code")) is int and items[0]["exit_code"] == exit_code,
             "failed install native Job pair is not terminal and exact")
    observation = state.get("metadata_readonly", {})
    result = observation.get("result", {})
    witness = canonical(observation.get("witness", ""))
    need(observation.get("status") == "COMPLETED"
         and observation == evidence.get("actual_metadata")
         and result.get("database") == operation["database"]["name"]
         and result.get("server_uuid") == operation["database"]["server_uuid"]
         and result.get("version") == operation["database"]["version"]
         and result.get("principal") == "root@localhost"
         and type(result.get("schema_rows")) is int and result["schema_rows"] == 1
         and result.get("active_roles") == "NONE"
         and type(result.get("partial_revokes")) is int and result["partial_revokes"] == 0
         and all(type(result.get(key)) is int and result[key] == 0
                 for key in ("tables_views", "triggers", "routines", "events"))
         and evidence["private_witness"]["path"] == str(witness)
         and evidence["private_witness"]["sha256"] == observation.get("witness_sha256")
         and sha(regular(witness, private=True)) == observation["witness_sha256"],
         "failed install metadata witness/zero-object observation changed")
    marker_root = canonical(Path(operation["application"]["root"]) / "server/private/installation")
    need(marker_root.is_dir(), "failed install marker parent unavailable")
    for name in ("installed.json", "executing.json", "baseline.json", "migration.json"):
        marker = marker_root / name
        need(not os.path.lexists(marker) and evidence["APP_after"]["markers"].get(name) is False,
             "failed install marker exists or original marker observation changed")
    need(proof.get("native_jobs") == [{"job_id": job_id, "status": status, "exit_code": exit_code}
                                       for _, job_id, status, exit_code in expected],
         "failed install review Job pair differs from original evidence")
    return {"original_execution_evidence_sha256": sha(evidence_raw),
            "failure_point": proof["failure_point"], "unknown_preserved": True, "release_only": True}


def terminal_registry(operation, document, project, common, registry_sha, terminal):
    """A reviewed stopped operation retires its original proof, never gains a window."""
    metadata, resources, state, reviewed = terminal
    failed_install = operation.get("stable_resource_id") == FAILED_INSTALL_OPERATION
    failed = state.get("status") == "UNKNOWN"
    need(state.get("protocol") == "peanut.application-operation-state.v1"
         and state.get("phase") == operation["phase"]
         and (operation["phase"] == "protect" or failed_install and operation["phase"] == "install")
         and state.get("status") in ("COMPLETED", "UNKNOWN") and state.get("actual_process_terminal") is True
         and state.get("child_pid") is None
         and (state.get("child_exit_code") == 0 if not failed_install else state.get("child_exit_code") == 1)
         and (not failed or isinstance(state.get("failure"), str) and bool(state["failure"]))
         and metadata["owner"] == metadata["thread"] == state.get("owner") == operation["owner"]
         and metadata["lease"] == state.get("lease"), "historical release requires the exact stopped operation")
    digest = sha(json.dumps(operation, sort_keys=True, separators=(",", ":")).encode())
    need(resources.get(CONTRACT) == {digest} and state.get("operation_sha256") == digest,
         "terminal operation digest changed")
    path = canonical(operation["terminal_review"]["path"])
    need(project in path.parents, "terminal review must belong to the registered Project")
    raw = regular(path, private=True)
    ref = git(project, "rev-parse", "HEAD").decode().strip()
    need(git(project, "show", ref + ":" + str(path.relative_to(project))) == raw,
         "terminal review must be the exact versioned Project result")
    proof = json_unique(raw)
    directory = common / "peanut-admin-resource-leases/leases" / metadata["lease"]
    need(proof.get("lease") == metadata["lease"]
         and proof.get("state_sha256") == sha(regular(directory / STATE, private=True))
         and proof.get("metadata_sha256") == sha(regular(directory / "metadata.tsv"))
         and proof.get("resources_sha256") == sha(regular(directory / "resources.tsv"))
         and proof.get("operation_sha256") == digest
         and proof.get("reviewer_thread") not in (None, "", metadata["owner"])
         and proof.get("actual_process_terminal") is True
         and proof.get("source_authority_at_start") is True,
         "independent terminal review does not bind the original stopped proof")
    jobs = proof.get("native_jobs")
    if failed_install:
        review_details = failed_install_review(operation, project, directory, metadata, resources, state, proof, digest)
    else:
        need(proof.get("verdict") == "SAFE_TO_RELEASE" and proof.get("no_pending_or_unknown") is True,
             "stopped protect needs its original safe terminal review")
        need(isinstance(jobs, list) and jobs and all(isinstance(row, dict)
             and set(row) == {"job_id", "status", "exit_code"}
             and re.fullmatch(r"wc_job_[A-Za-z0-9_-]+", row["job_id"])
             and type(row["exit_code"]) is int
             and (row["status"] == "completed" and row["exit_code"] == 0
                  or failed and row["status"] == "failed" and row["exit_code"] == 1)
             for row in jobs)
             and (not failed or any(row["status"] == "failed" for row in jobs)),
             "terminal review lacks known terminal native Job evidence")
    if failed and not failed_install:
        failure = proof.get("failed_protect", {})
        need(set(failure) == {"state_status", "protection_accepted", "partial_outputs"}
             and failure["state_status"] == "UNKNOWN" and failure["protection_accepted"] is False,
             "failed protect needs explicit non-success preservation review")
        output = canonical(operation["output_root"])
        info = output.stat()
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == os.getuid()
             and stat.S_IMODE(info.st_mode) == 0o700,
             "failed protect output custody changed")
        partial = failure["partial_outputs"]
        need(isinstance(partial, list) and partial and all(isinstance(row, dict)
             and set(row) == {"path", "sha256", "bytes"} for row in partial),
             "failed protect partial-output closure missing")
        paths = [canonical(row["path"]) for row in partial]
        need(len(set(paths)) == len(paths) and all(path.parent == output for path in paths)
             and set(paths) == set(output.iterdir()), "failed protect partial-output closure changed")
        for path, row in zip(paths, partial):
            raw_partial = regular(path, private=True)
            need(type(row["bytes"]) is int and len(raw_partial) == row["bytes"]
                 and sha(raw_partial) == row["sha256"], "failed protect partial bytes changed")
    original_ref = proof.get("registry_commit", "")
    need(re.fullmatch(r"[0-9a-f]{40}", original_ref), "terminal registry commit is invalid")
    git(project, "merge-base", "--is-ancestor", original_ref, "refs/remotes/origin/dev")
    need(git(project, "show", original_ref + ":repos.yaml") == git(project, "show", "HEAD:repos.yaml"),
         "original published repository mapping changed")
    original_raw = git(project, "show", original_ref + ":resources/project-resources.json")
    original_sha = sha(original_raw)
    need(resources.get("registry-sha256") == {original_sha} and proof.get("registry_sha256") == original_sha,
         "terminal registry blob differs from the original lease")
    original = json_unique(original_raw)
    need(original.get("schema_version") == 1 and original.get("project_id") == document.get("project_id")
         and original.get("authority", {}).get("role") == "maintainer",
         "terminal registry is not the original maintainer document")
    matches = [row for row in original["resources"].get("application_operations", [])
               if row.get("stable_resource_id") == operation["stable_resource_id"]]
    need(matches == [operation], "original published operation differs")
    head = git(ROOT, "rev-parse", "HEAD").decode().strip()
    primary = common.parent
    need(git(primary, "branch", "--show-current").strip() == b"dev"
         and git(primary, "rev-parse", "HEAD").strip() == git(primary, "rev-parse", "refs/remotes/origin/dev").strip(),
         "release executor needs normally delivered Code dev")
    git(ROOT, "merge-base", "--is-ancestor", operation["tool_source"]["commit"], head)
    git(ROOT, "merge-base", "--is-ancestor", head, "refs/remotes/origin/dev")
    required = {"scripts/project-resource-lease", "scripts/project-resource-application.py", "scripts/project-resource-registry"}
    executor = proof.get("release_source", {})
    need(set(executor) == {"root", "common_dir", "commit", "files"}
         and executor["root"] == str(ROOT) and executor["common_dir"] == str(common)
         and executor["commit"] == head and set(executor["files"]) == required
         and not git(ROOT, "status", "--porcelain=v1", "--untracked-files=all"),
         "terminal review does not bind the clean published release executor")
    for name, digest in executor["files"].items():
        raw_file = regular(ROOT / name)
        need(sha(raw_file) == digest and git(ROOT, "show", head + ":" + name) == raw_file,
             "release executor fingerprint changed")
    reviewed.update(sha256=sha(raw), reviewer_thread=proof["reviewer_thread"], project_commit=ref,
                    registry_commit=original_ref, registry_sha256=original_sha, release_source=executor,
                    native_jobs=jobs)
    if failed and not failed_install:
        reviewed["failed_protect"] = failure
    if failed_install:
        reviewed["failed_install"] = review_details
    return original, original_sha


def authority(operation_id, *, terminal=None):
    need(re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,63}", operation_id), "invalid application operation ID")
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    registry, project, resolver, registry_sha = project_authority()
    document = json_unique(regular(registry))
    operations = document["resources"].get("application_operations", [])
    matches = [row for row in operations if row.get("stable_resource_id") == operation_id]
    need(len(matches) == 1, "application operation is not uniquely registered")
    operation = matches[0]
    registry_document = resolver["load_registry"]()
    historical_release = terminal is not None and (
        terminal[1].get("registry-sha256") != {registry_sha} or operation["tool_source"]["root"] != str(ROOT)
        or operation_id == FAILED_INSTALL_OPERATION and terminal[2].get("status") == "UNKNOWN")
    if historical_release:
        registry_document, registry_sha = terminal_registry(operation, registry_document, project, common, registry_sha, terminal)
    need(operation.get("protocol") == PROTOCOL and operation.get("phase") in ("protect", "install", "maintain"),
         "unsupported application operation")
    required_keys = {"stable_resource_id", "protocol", "phase", "tool_source", "application",
                     "owner", "thread", "max_seconds", "database", "configuration", "output_root",
                     "receipt_root", "php_executable", "terminal_review"}
    required_keys |= {"protect": {"backup_client"}, "install": {"protection", "credentials", "metadata_readonly"},
                      "maintain": {"maintenance", "installation"}}[operation["phase"]]
    if "retained_authority" in operation:
        required_keys.add("retained_authority")
    need(set(operation) == required_keys, "application operation fields differ from the closed phase contract")
    php = canonical(operation["php_executable"])
    need(php.name == "php" and php.is_file() and os.access(php, os.X_OK), "registered native PHP unavailable")
    need(set(operation["terminal_review"]) == {"path"}
         and project in canonical(operation["terminal_review"]["path"]).parents,
         "terminal review must be a fixed Project versioned result")
    if operation["phase"] == "protect":
        client = operation["backup_client"]
        need(set(client) == {"ssh", "ssh_alias", "docker", "container", "mysql", "mysqldump",
                             "credential_path", "user"}, "native backup client closure differs")
        canonical(client["ssh"])
        for key in ("docker", "mysql", "mysqldump", "credential_path"):
            path = Path(client[key])
            need(path.is_absolute() and ".." not in path.parts
                 and not any(ord(char) < 32 for char in str(path)), "remote backup client path invalid")
        need(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", client["ssh_alias"])
             and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", client["container"])
             and re.fullmatch(r"[a-z_][a-z0-9_-]*", client["user"]) and client["user"] != "root",
             "native backup client identity invalid")
    elif operation["phase"] == "install":
        need(set(operation["credentials"]) == {"path", "sha256"}
             and set(operation["protection"]) == {"manifest", "manifest_sha256", "review", "review_sha256"},
             "first-install protection/credential closure differs")
        for path_key, digest_key in (("manifest", "manifest_sha256"), ("review", "review_sha256")):
            need(sha(regular(canonical(operation["protection"][path_key]), private=True))
                 == operation["protection"][digest_key], "fixed first-install protection input changed")
        need(sha(regular(canonical(operation["credentials"]["path"]), private=True))
             == operation["credentials"]["sha256"], "fixed installation credential file changed")
    tool = operation["tool_source"]
    tool_root = canonical(tool["root"]) if historical_release else ROOT
    need(canonical(tool["root"]) == tool_root and canonical(tool["common_dir"]) == common
         and canonical(git(tool_root, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip()) == common,
         "registered central source/common-dir mismatch")
    need(common == canonical(str(common.parent / ".git")) and common.parent.name == "peanut-admin-code",
         "application operation must use the central Code lock registry")
    need(git(tool_root, "rev-parse", "HEAD").decode().strip() == tool["commit"], "operation tool commit changed")
    need(not git(tool_root, "status", "--porcelain=v1", "--untracked-files=all"), "operation tool source must be clean")
    required = {"scripts/project-resource-lease", "scripts/project-resource-application.py",
                "scripts/project-resource-registry"}
    need(set(tool["files"]) == required, "operation tool closure differs")
    for name, digest in tool["files"].items():
        raw = regular(tool_root / name)
        need(sha(raw) == digest and git(tool_root, "show", tool["commit"] + ":" + name) == raw,
             "operation tool file changed")
    need(operation["owner"] == operation["thread"], "operation requires its real native owner/thread")
    need(type(operation["max_seconds"]) is int and 60 <= operation["max_seconds"] <= 3600,
         "operation execution bound invalid")
    app = operation["application"]
    app_root = canonical(app["root"])
    need(app_root != ROOT and common.parent not in app_root.parents, "application must be a separate registered Git")
    need(canonical(git(app_root, "rev-parse", "--show-toplevel").decode().strip()) == app_root,
         "application Git root changed")
    if historical_release:
        # Resource retirement verifies the original immutable APP record. It does
        # not consume the APP's new source/configuration as execution authority.
        need(git(app_root, "rev-parse", app["commit"] + "^{tree}").decode().strip() == app["tree"],
             "original application tree changed")
        git(app_root, "merge-base", "--is-ancestor", app["commit"], "HEAD")
        manifest = git(app_root, "show", app["commit"] + ":.peanut/application-manifest.json")
    else:
        need(git(app_root, "rev-parse", "HEAD").decode().strip() == app["commit"]
             and git(app_root, "rev-parse", "HEAD^{tree}").decode().strip() == app["tree"],
             "application commit/tree changed")
        need(not git(app_root, "diff", "--name-only", "HEAD")
             and not git(app_root, "diff", "--cached", "--name-only"), "APP tracked source changed")
        manifest = regular(app_root / ".peanut/application-manifest.json")
    need(sha(manifest) == app["manifest_sha256"], "application manifest changed")
    identity = json_unique(manifest)
    need(identity.get("protocol") == "peanut.application-scaffold.v2"
         and identity["application"]["edition"] == app["edition"]
         and identity["application"]["slug"] == app["slug"]
         and identity["application"]["package_identity"] == app["package_identity"],
         "application identity changed")
    for name, digest in operation["configuration"].items():
        need(name in ("server/.env", "server/private/resources/project-resources.json"),
             "unsupported private configuration binding")
        if historical_release:
            need(re.fullmatch(r"[0-9a-f]{64}", digest), "original application configuration binding invalid")
        else:
            need(sha(regular(app_root / name, private=True)) == digest, "application private configuration changed")
    need(set(operation["configuration"]) == {"server/.env", "server/private/resources/project-resources.json"},
         "complete private application configuration required")
    if operation["phase"] == "maintain":
        maintenance = operation["maintenance"]
        fields = {"kind", "session", "backup_tool", "metadata_tool", "review"}
        need(maintenance["kind"] in ("plugin", "application-schema"), "unsupported native maintenance kind")
        if maintenance["kind"] == "plugin":
            fields |= {"operation", "plugin_key"}
            need(maintenance["operation"] in ("install", "upgrade")
                 and re.fullmatch(r"[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*", maintenance["plugin_key"]),
                 "native plugin target invalid")
        need(set(maintenance) == fields and re.fullmatch(r"wc_sess_[A-Za-z0-9_-]+", maintenance["session"])
             and re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,127}", maintenance["backup_tool"])
             and (maintenance["metadata_tool"] == "" or re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,127}", maintenance["metadata_tool"])),
             "closed native maintenance target/actor/tool contract differs")
        canonical(maintenance["review"])
        need(project in Path(maintenance["review"]).parents,
             "maintenance review must be a fixed versioned Project result")
        for name, digest in operation["installation"].items():
            need(name in ("installed.json", "baseline.json")
                 and sha(regular(app_root / "server/private/installation" / name, private=True)) == digest,
                 "fixed installed APP identity changed")
        need(set(operation["installation"]) == {"installed.json", "baseline.json"},
             "complete installed APP anchors required")
    db = operation["database"]
    db_matches = [row for row in registry_document["resources"]["databases"]
                  if row.get("stable_resource_id") == db["resource_id"]]
    need(len(db_matches) == 1, "operation database is not uniquely registered")
    registered = db_matches[0]
    need(registered.get("application_identity", {}).get("root") == str(app_root)
         and registered["database"] == db["name"]
         and registered["upstream_endpoint"] == db["upstream_endpoint"]
         and registered["lease_gate"] == "permanent-app-development",
         "operation database/APP/endpoint registration mismatch")
    need(registered["version"] == db["version"]
         and registered["allocation_evidence"]["server_uuid"] == db["server_uuid"], "database allocation identity changed")
    need(db["environment"] in registered["environments"] and db["consumer"] == "host"
         and registered.get("environment") != "production", "operation is not dedicated development")
    if operation["phase"] == "install":
        install_metadata_contract(operation, registry_document, registered)
    for name in ("output_root", "receipt_root"):
        path = canonical(operation[name])
        need(path.parent.is_dir() and path != app_root and app_root not in path.parents
             and path != ROOT and ROOT not in path.parents
             and path != tool_root and tool_root not in path.parents, "operation output overlaps source")
    retained_parent(operation, common, require_live=False)
    return operation, sha(json.dumps(operation, sort_keys=True, separators=(",", ":")).encode()), common, registry_sha


def retained_parent(operation, common, *, require_live=True, registry_sha=None):
    """Read the original authority; renewal may change time, never its identity/scope."""
    if "retained_authority" not in operation:
        return None
    binding = operation["retained_authority"]
    need(isinstance(binding, dict) and set(binding) == {"common_dir", "lease", "gate", "resources_sha256"}
         and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", binding["lease"])
         and re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,63}", binding["gate"]),
         "retained parent binding differs from closed contract")
    app = operation["application"]
    app_common = canonical(git(app["root"], "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    parent_common = canonical(binding["common_dir"])
    need(parent_common in (common, app_common), "retained parent crosses an unrelated lock domain")
    directory = parent_common / "peanut-admin-resource-leases/leases" / binding["lease"]
    metadata = dict(table(directory / "metadata.tsv", 2))
    need(set(metadata) == {"lease", "owner", "thread", "candidate", "candidate_repository", "parent_lease",
                           "gate", "worktree", "created_at", "expires_at", "status"},
         "retained parent metadata schema differs")
    now = time.time()
    need(metadata["lease"] == binding["lease"] and metadata["owner"] == operation["owner"]
         and metadata["thread"] == operation["thread"] and metadata["status"] == "ACTIVE"
         and metadata["parent_lease"] == "" and metadata["gate"] == binding["gate"]
         and metadata["candidate"] == app["commit"]
         and metadata["candidate_repository"] == metadata["worktree"] == app["root"]
         and int(metadata["created_at"]) <= now
         and int(metadata["expires_at"]) > int(metadata["created_at"])
         and (not require_live or now < int(metadata["expires_at"])),
         "retained parent owner/APP/candidate/gate/expiry differs")
    need(sha(regular(directory / "resources.tsv")) == binding["resources_sha256"],
         "retained parent resource scope changed")
    rows = table(directory / "resources.tsv", 3)
    resources = resource_map(rows)
    if "registry-sha256" in resources:
        # Explicit old digest is used only by the native cross-snapshot custody
        # reader after it has authenticated the complete original registration.
        # Execution/renewal callers retain their current-registry default.
        if registry_sha is None:
            registry = canonical(os.environ.get("PEANUT_RESOURCE_REGISTRY", ""))
            registry_sha = sha(regular(registry))
        need(re.fullmatch(r"[0-9a-f]{64}", registry_sha) is not None
             and resources["registry-sha256"] == {registry_sha},
             "retained parent registry identity differs from current registry")
    need(resources.get("worktree") == {app["root"]}
         and resources.get("application-root") == {app["root"]}
         and resources.get("gate") == {binding["gate"]}, "retained parent lacks exact APP protection")
    # Only these two exact physical resources may be retained. The operation
    # acquires its source, DB, outputs and all other authority independently.
    retained = {(kind, app["root"]) for kind in ("worktree", "application-root")}
    for digest, kind, value in rows:
        if kind == "registry-sha256":
            continue
        lock = parent_common / "peanut-admin-resource-leases/resources" / digest
        for field, expected in (("lease", binding["lease"]), ("type", kind), ("value", value)):
            need(regular(lock / field).decode().strip() == expected, "retained parent physical holder changed")
    return parent_common, metadata, retained


def retained_guard_value(operation, common, lease_id):
    return json.dumps({"common_dir": str(common), "lease": lease_id,
                       "operation": operation["stable_resource_id"]}, sort_keys=True, separators=(",", ":"))


def retained_guard(operation, common, lease_id, action="verify", expires=None, *, allow_expired_parent=False):
    """A native APP child protects the other lock domain; it grants no DB rights.

    Central mutex is always acquired first. A partial/crashed reservation remains
    an explicit native child for normal reconciliation, including after expiry.
    """
    parent = retained_parent(operation, common, require_live=not allow_expired_parent)
    if parent is None or parent[0] == common:
        return
    parent_common, metadata, _ = parent
    binding = operation["retained_authority"]
    root = parent_common / "peanut-admin-resource-leases"
    directory = root / "leases" / lease_id
    value = retained_guard_value(operation, common, lease_id)
    resources = {"retained-operation": value, "gate": "retained-application-operation"}
    expected = dict(metadata, lease=lease_id, parent_lease=binding["lease"], gate="retained-application-operation")
    with mutex(parent_common):
        retained_parent(operation, common, require_live=not allow_expired_parent)
        if action == "reserve":
            need(not directory.exists() and not directory.is_symlink(), "retained operation child already exists; reconcile")
            # Each reservation is an ordinary exact native resource, with its
            # actual holder. No parent lock is transferred, removed or relabelled.
            locks = [(root / "resources" / sha((kind + "\t" + item).encode()), kind, item)
                     for kind, item in resources.items()]
            need(all(not lock.exists() and not lock.is_symlink() for lock, _, _ in locks),
                 "retained operation child resource conflict")
            directory.mkdir(mode=0o700)
            expected.update(created_at=str(int(time.time())), expires_at=str(expires))
            for lock, kind, item in locks:
                lock.mkdir(mode=0o700)
                for field, text in (("lease", lease_id), ("type", kind), ("value", item)):
                    (lock / field).write_text(text + "\n")
            (directory / "resources.tsv").write_text("".join(
                sha((kind + "\t" + item).encode()) + "\t" + kind + "\t" + item + "\n"
                for kind, item in resources.items()))
            (directory / "metadata.tsv").write_text("".join(key + "\t" + item + "\n" for key, item in expected.items()))
        actual = dict(table(directory / "metadata.tsv", 2))
        need(all(actual.get(key) == item for key, item in expected.items() if key not in ("created_at", "expires_at"))
             and int(actual["created_at"]) <= time.time()
             and int(actual["expires_at"]) <= int(metadata["expires_at"]), "retained operation child identity differs")
        if action == "verify" and expires is not None and not allow_expired_parent:
            need(int(actual["expires_at"]) >= expires, "retained operation child window is shorter than operation")
        rows = table(directory / "resources.tsv", 3)
        need(resource_map(rows) == {key: {item} for key, item in resources.items()}, "retained operation child scope differs")
        for digest, kind, item in rows:
            lock = root / "resources" / digest
            for field, text in (("lease", lease_id), ("type", kind), ("value", item)):
                need(regular(lock / field).decode().strip() == text, "retained operation child physical holder changed")
        if action == "renew":
            actual["expires_at"] = str(expires)
            need(expires <= int(metadata["expires_at"]), "retained operation child TTL exceeds parent")
            temporary = directory / (".metadata." + str(os.getpid()))
            temporary.write_text("".join(key + "\t" + item + "\n" for key, item in actual.items()))
            temporary.replace(directory / "metadata.tsv")


def physical_holder(operation, common, lease_id, kind, value):
    # Custody mapping grants no execution rights; its caller admits execution.
    parent = retained_parent(operation, common, require_live=False)
    if parent and parent[0] == common and (kind, value) in parent[2]:
        return operation["retained_authority"]["lease"]
    return lease_id


def expected_resources(operation, digest, registry_sha):
    app, db = operation["application"], operation["database"]
    endpoint = db["upstream_endpoint"]
    scope = endpoint["host"] + ":" + str(endpoint["port"]) + "/" + db["name"]
    access = json.dumps({"schema": scope, "operation": "exclusive", "complete": True, "tables": []},
                        sort_keys=True, separators=(",", ":"))
    result = {BINDING: {operation["stable_resource_id"]}, CONTRACT: {digest},
            "registry-sha256": {registry_sha}, "resource-id": {db["resource_id"]},
            "endpoint": {endpoint["endpoint_id"]}, "database": {db["name"]},
            "mysql-db": {db["name"]}, "mysql-schema": {scope}, "mysql-access": {access},
            "environment": {db["environment"]}, "consumer": {"host"},
            "application-root": {app["root"]}, "worktree": {app["root"], operation["tool_source"]["root"]},
            "output-dir": {operation["output_root"], operation["receipt_root"]},
            "gate": {"permanent-app-development"},
            "application-registry-sha256": {operation["configuration"]["server/private/resources/project-resources.json"]},
            "application-manifest-sha256": {app["manifest_sha256"]},
            "application-env-sha256": {operation["configuration"]["server/.env"]}}
    if "retained_authority" in operation:
        result["retained-parent"] = {json.dumps(operation["retained_authority"], sort_keys=True, separators=(",", ":"))}
    if operation["phase"] == "install":
        result["tooling-resource-id"] = {operation["metadata_readonly"]["tool"]}
        result["workflow-session"] = {operation["metadata_readonly"]["session"]}
    if operation["phase"] == "maintain":
        m = operation["maintenance"]
        result["workflow-session"] = {m["session"]}
        result["tooling-resource-id"] = {item for item in (m["backup_tool"], m["metadata_tool"]) if item}
        result["output-dir"].add(str(Path(operation["output_root"]) / "backup"))
        if m["metadata_tool"]:
            result["output-dir"].add(str(Path(app["root"]) / "server/private/installation/source-development/effect-metadata"))

    return result


def validate(metadata, resources, *, allow_expired_parent=False, terminal=None):
    ids = resources.get(BINDING, set())
    need(len(ids) == 1, "application operation binding is ambiguous")
    operation, digest, common, registry_sha = authority(next(iter(ids)), terminal=terminal)
    app = operation["application"]
    need(metadata["candidate"] == app["commit"] and metadata["candidate_repository"] == app["root"]
         and metadata["worktree"] == app["root"] and metadata["owner"] == operation["owner"]
         and metadata["thread"] == operation["thread"]
         and metadata["parent_lease"] == operation.get("retained_authority", {}).get("lease", "")
         and metadata["gate"] == "permanent-app-development", "operation lease identities changed")
    need(resources == expected_resources(operation, digest, registry_sha), "operation exclusive resource closure differs")
    parent = retained_parent(operation, common, require_live=not allow_expired_parent)
    if parent and "expires_at" in metadata and not allow_expired_parent:
        need(int(metadata["expires_at"]) <= int(parent[1]["expires_at"]), "operation TTL exceeds retained parent")
    return operation, common


def lease(lease_id, allow_expired=False, *, recovery_step=None, terminal_review=None):
    need(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", lease_id), "invalid operation lease ID")
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    directory = common / "peanut-admin-resource-leases/leases" / lease_id
    metadata = dict(table(directory / "metadata.tsv", 2))
    resources = resource_map(table(directory / "resources.tsv", 3))
    state = live_state(directory) if recovery_step in ("restore", "open") else None
    recovering = bool(state and state.get("recovery"))
    terminal = (metadata, resources, live_state(directory), terminal_review) if terminal_review is not None else None
    operation, expected_common = validate(metadata, resources, allow_expired_parent=allow_expired or recovering, terminal=terminal)
    need(expected_common == common and metadata["lease"] == lease_id and metadata["status"] == "ACTIVE",
         "operation lease storage mismatch")
    retained_guard(operation, common, lease_id, expires=int(metadata["expires_at"]),
                   allow_expired_parent=allow_expired or recovering)
    need(allow_expired or int(metadata["expires_at"]) > time.time(), "operation lease expired")
    identity_fields = {"resource-id", "database-resource", "endpoint", "resource-scope", "scope", "gate",
                       "mysql-db", "database", "mysql-access", "registry-sha256", "registry-tool-sha256", "application-registry-sha256", "application-manifest-sha256", "application-env-sha256",
                       "environment", "consumer", "host", "version"}
    for digest, kind, item in table(directory / "resources.tsv", 3):
        if kind in identity_fields:
            continue
        lock = common / "peanut-admin-resource-leases/resources" / digest
        need(canonical(str(lock)).is_dir(), "operation resource lock unavailable")
        for field, expected in (("lease", physical_holder(operation, common, lease_id, kind, item)), ("type", kind), ("value", item)):
            need(regular(lock / field).decode().strip() == expected, "operation resource lock holder mismatch")
    if recovering:
        need(operation["phase"] == state.get("phase") == "maintain"
             and state.get("lease") == lease_id and state.get("owner") == metadata["owner"],
             "recovery operation identity differs")
        recovery = recovery_check(directory, metadata, state)
        need(recovery_step == ("open" if recovery["restored"] else "restore"),
             "recovery authority forbids fresh migration or rehearsal")
    return operation, directory, metadata


def live_state(directory):
    path = directory / STATE
    if not path.exists():
        return None
    value = json_unique(regular(path, private=True))
    need(value.get("protocol") == "peanut.application-operation-state.v1", "operation state is unknown")
    return value


@contextlib.contextmanager
def mutex(common):
    directory = common / "peanut-admin-resource-leases/mutex"
    acquired = False
    for _ in range(100):
        try:
            directory.mkdir()
            (directory / "pid").write_text(str(os.getpid()) + "\n")
            acquired = True
            break
        except FileExistsError:
            time.sleep(0.05)
    need(acquired, "central resource mutex busy")
    try:
        yield
    finally:
        need((directory / "pid").read_text().strip() == str(os.getpid()), "central resource mutex owner changed")
        (directory / "pid").unlink()
        directory.rmdir()


def save(path, value):
    temporary = path.with_name(path.name + "." + str(os.getpid()))
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w") as stream:
        stream.write(json.dumps(value, sort_keys=True, separators=(",", ":")) + "\n")
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, path)


def verify_native(lease_id, recovery_step=None):
    arguments = ["bash", str(ROOT / "scripts/project-resource-lease"), "show",
                 "--lease", lease_id, "--verify-locks"]
    if recovery_step in ("restore", "open"):
        _, directory, _ = lease(lease_id, recovery_step=recovery_step)
        if (live_state(directory) or {}).get("recovery"):
            arguments += ["--application-recovery", recovery_step]
    result = subprocess.run(arguments, cwd=ROOT, capture_output=True)
    need(result.returncode == 0, "native application lease verification rejected")


def process_birth(pid):
    result = subprocess.run(["/bin/ps", "-p", str(pid), "-o", "lstart="], capture_output=True, text=True)
    need(result.returncode in (0, 1) and not result.stderr.strip(), "native process identity unavailable")
    return result.stdout.strip() if result.returncode == 0 else None


def original_process_alive(pid, birth):
    current = process_birth(pid)
    if current is None:
        return False
    need(birth is not None, "original process birth was not observed")
    return current == birth



def native_source_record(app, path):
    """Read the existing APP instance authenticated record, never a new signer."""
    import hmac
    value = json_unique(regular(canonical(str(path)), private=True))
    signature = value.pop("hmac_sha256", None)
    key = regular(app / "server/private/maintenance/update-verification.key", private=True).strip()
    need(re.fullmatch(b"[a-f0-9]{64}", key) is not None, "native APP verification key invalid")
    raw = json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode()
    need(isinstance(signature, str) and hmac.compare_digest(signature, hmac.new(key, raw, hashlib.sha256).hexdigest()),
         "native APP recovery record authentication failed")
    return value


def recovery_check(directory, metadata, state, *, parent=None):
    """A continuation retains the original binding; only its separate authority is new."""
    rebind = state.get("recovery")
    need(isinstance(rebind, dict), "original-plan recovery admission is absent")
    app = canonical(metadata["worktree"])
    reference = rebind["record"]
    raw = regular(canonical(reference["path"]), private=True)
    need(sha(raw) == reference["sha256"], "recovery rebind record changed")
    record = json_unique(raw)
    need(record["lease"] == directory.name and record["owner"] == metadata["owner"] == metadata["thread"]
         and record["resources_sha256"] == sha(regular(directory / "resources.tsv"))
         and record["binding_expires_at"] == state["binding_expires_at"]
         and int(metadata["expires_at"]) >= record["effective_expires_at"] > time.time() + 1,
         "new recovery authority changed or expired")
    original_meta = dict(table(canonical(record["metadata_path"]), 2))
    need(all(metadata[k] == v for k, v in original_meta.items() if k != "expires_at"),
         "recovery authority changed fields other than normal renewal")
    for item in record["attachments"]:
        need(sha(regular(canonical(item["path"]), private=True)) == item["sha256"],
             "original recovery attachment changed")
    context = native_source_record(app, canonical(record["workspace"]) / "source-application-context.json")
    journal_path = canonical(context["journal_path"])
    journal = native_source_record(app, journal_path)
    restored = (journal.get("phase") == "restored" and journal.get("unknown") is False
                and (journal.get("recovery_continuation") == reference
                     or (record["journal_phase"] == "restored" and sha(regular(journal_path, private=True)) == record["journal_sha256"])))
    need(journal.get("activation_started") is False and (restored or sha(regular(journal_path, private=True)) == record["journal_sha256"]),
         "original journal changed without the authorized restore")
    if parent is not None:
        import shlex
        result = subprocess.run(["/bin/ps", "-p", str(parent), "-o", "command="], capture_output=True, text=True)
        argv = shlex.split(result.stdout.strip())
        expected = "open" if restored else "restore"
        comm = subprocess.run(["/bin/ps", "-p", str(parent), "-o", "comm="], capture_output=True, text=True)
        executable = canonical(str(Path(comm.stdout.strip()).resolve()))
        need(comm.returncode == 0 and not comm.stderr and executable.name == "php"
             and sha(regular(executable)) == context["binding"]["source"]["php_binary_sha256"]
             and len(argv) >= 4 and argv[1] in ("server/think", str(app / "server/think"))
             and argv[2:4] == (["schema:application", "source-recovery"] if restored else record["native_command"]),
             "recovery authority requires the fixed native PHP/think entry and original operation")
        need(result.returncode == 0 and not result.stderr and argv
             and "--application-recovery=" + expected in argv
             and "--scope=source-application" in argv
             and all("--" + k + "=" + record["actor"][k] in argv for k in ("owner", "thread", "session", "gate")),
             "rebound authority permits only its real native restore or post-restore open")
        if not restored:
            need("--source-plan=" + record["plan_path"] in argv and "--recovery=" + record["workspace"] in argv,
                 "rebound PHP command must consume the fixed original plan/workspace")
    return {"binding_expires_at": state["binding_expires_at"],
            "effective_expires_at": record["effective_expires_at"],
            "recovery_continuation": reference, "restored": restored,
            "journal_sha256": record["journal_sha256"]}


def admit_recovery(lease_id, owner):
    """No release/reclaim: reconcile a terminal window on its normally renewed lease."""
    need(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", lease_id), "invalid original recovery lease ID")
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    directory = common / "peanut-admin-resource-leases/leases" / lease_id
    state = live_state(directory)
    need(state is not None, "original recovery window absent")
    if state.get("status") == "RECOVERY_ADMITTED":
        prior_raw = regular(canonical(state["recovery"]["record"]["path"]), private=True)
        need(sha(prior_raw) == state["recovery"]["record"]["sha256"], "previous recovery authority changed")
        prior = json_unique(prior_raw)
        need(prior["effective_expires_at"] <= time.time(), "unexpired recovery admission already pending")
    if state.get("phase") == "maintain":
        operation, directory, metadata = lease(lease_id, allow_expired=True)
        review_path = canonical(operation["terminal_review"]["path"])
        review_raw = regular(review_path, private=True)
        project = project_authority()[1]
        need(git(project, "show", "HEAD:" + str(review_path.relative_to(project))) == review_raw,
             "central recovery review must be its fixed versioned Project result")
    else:
        common, directory, metadata = same_source_lease(lease_id, allow_expired=True)
        need(state.get("phase") == "source-application", "only source APP maintenance can rebind")
        review_path = ROOT / "server/private/maintenance" / ("source-recovery-review-" + lease_id + ".json")
        review_raw = regular(review_path, private=True)
        native_source_record(ROOT, review_path)  # Authored by the independent native owner, never this adapter.
    review = json_unique(review_raw)
    need(owner == metadata["owner"] == metadata["thread"] == state["owner"]
         and review.get("protocol") == "peanut.source-application-terminal-reconciliation.v1"
         and review.get("verdict") == "ORIGINAL_PLAN_RECOVERY_ONLY"
         and review.get("reviewer_thread") not in (None, "", owner)
         and review.get("owner") == owner and review.get("lease") == lease_id
         and review.get("state_sha256") == sha(regular(directory / STATE, private=True))
         and review.get("metadata_sha256") == sha(regular(directory / "metadata.tsv"))
         and review.get("actual_process_terminal") is True and review.get("remote_effects_known") is True
         and isinstance(review.get("terminal_evidence"), list) and review["terminal_evidence"],
         "independent terminal reconciliation does not bind this exact window/new authority")
    need(not original_process_alive(state["pid"], state.get("process_birth"))
         and (state.get("child_pid") is None or not original_process_alive(state["child_pid"], state.get("child_birth"))),
         "original maintenance process remains active")
    expires = review.get("effective_expires_at")
    need(type(expires) is int and time.time() + 60 < expires <= time.time() + 3600,
         "recovery requires independently admitted current bounded authority")
    app = canonical(metadata["worktree"])
    workspace = canonical(review["workspace"])
    resources = resource_map(table(directory / "resources.tsv", 3))
    plan_path = canonical(review["plan_path"])
    backup_root = workspace.parent
    if state["phase"] == "maintain":
        output_root = canonical(operation["output_root"])
        need(workspace == output_root / "backup/application-recovery"
             and plan_path == output_root / "plan.json"
             and {str(output_root), str(backup_root)} <= resources.get("output-dir", set()),
             "central recovery workspace/plan differs from the registered maintenance producer")
        need(output_root.is_dir() and output_root.stat().st_uid == os.getuid()
             and stat.S_IMODE(output_root.stat().st_mode) == 0o700,
             "central maintenance output owner/mode changed")
    else:
        need(workspace.name == "application-recovery" and str(backup_root) in resources.get("output-dir", set()),
             "ordinary recovery workspace differs from its original leased backup output")
    for path in (backup_root, workspace):
        need(canonical(str(path)).is_dir() and path.stat().st_uid == os.getuid()
             and stat.S_IMODE(path.stat().st_mode) == 0o700, "recovery workspace owner/mode changed")
    context_path = workspace / "source-application-context.json"
    context = native_source_record(app, context_path)
    binding = context["binding"]
    native_plan = canonical(context["native_plan"]["path"])
    need(context.get("workspace") == str(workspace) and native_plan == workspace / "native-plan.json"
         and canonical(context["backup_tool"]["path"]) == workspace / "backup-tool.json",
         "recovery context/native plan/tool escaped the fixed producer workspace")
    plan = json_unique(regular(plan_path, private=True))
    original = json_unique(regular(native_plan, private=True))
    need(original.get("protocol") in ("peanut.source-development-plugin-plan.v1", "peanut.source-development-schema-plan.v1")
         and original.get("maintenance_scope") == "source-application" and original.get("execution_admitted") is False,
         "recovery requires its original native source APP plan")
    need(sha(regular(native_plan, private=True)) == context["native_plan"]["sha256"]
         and all(plan.get(k) == v for k, v in original.items())
         and original["binding"] == binding
         and binding["source"]["root"] == str(app)
         and binding["source"]["head"] == metadata["candidate"]
         and binding["authority"]["lease"] == lease_id and binding["authority"]["owner"] == owner
         and binding["authority"]["thread"] == owner and binding["authority"]["resources_sha256"] == sha(regular(directory / "resources.tsv"))
         and binding["authority"]["expires_at"] == state["binding_expires_at"],
         "recovery must retain its exact original plan/actor/APP/source/resources/expiry")
    need(git(app, "rev-parse", "HEAD").decode().strip() == binding["source"]["head"]
         and not git(app, "diff", "--name-only", "HEAD")
         and sha(regular(app / "server/.env", private=True)) == binding["environment_sha256"]
         and sha(regular(app / "server/private/resources/project-resources.json", private=True)) == binding["registry_sha256"],
         "original source/configuration changed before recovery rebind")
    journal_path = canonical(context["journal_path"])
    journal = native_source_record(app, journal_path)
    need(journal.get("activation_started") is False and journal.get("phase") in ("failed", "migrated", "applying", "restored"),
         "only an original closed preactivation mutation/restoration can reconcile")
    completed_path = workspace / "database-recovery.json"
    completed_items = []
    if completed_path.exists() or completed_path.is_symlink():
        import hmac
        completed_raw = regular(completed_path, private=True)
        key = regular(app / "server/private/maintenance/update-verification.key", private=True).strip()
        need(hmac.compare_digest(regular(completed_path.with_name(completed_path.name + ".hmac"), private=True).decode().strip(),
             hmac.new(key, completed_raw, hashlib.sha256).hexdigest()), "existing restore record authentication failed")
        completed = json_unique(completed_raw)
        process_ref = completed.get("registered_restore_process", {})
        terminal_path = canonical(process_ref["path"])
        terminal = json_unique(regular(terminal_path, private=True))
        need(completed.get("status") == "completed"
             and completed.get("backup_sha256") == sha(regular(workspace / "backup.json", private=True))
             and sha(regular(terminal_path, private=True)) == process_ref["sha256"]
             and terminal.get("operation") == "restore" and terminal.get("status") == "terminal"
             and terminal.get("rc") == 0 and terminal.get("remote_success_terminal_confirmed") is True,
             "interrupted/unknown restore cannot be replayed; require its real native terminal reconciliation")
        completed_items = [completed_path, completed_path.with_name(completed_path.name + ".hmac"), terminal_path]
    need(journal.get("phase") != "restored" or completed_items, "restored journal requires its actual provider completion")

    items = [context_path, native_plan, plan_path, workspace / "backup.json", workspace / "backup.json.hmac",
             canonical(context["backup_tool"]["path"]), review_path]
    items += [canonical(item["path"]) for item in review["terminal_evidence"]]
    items += completed_items
    if state.get("recovery"):
        items.append(canonical(state["recovery"]["record"]["path"]))
    attachments = [{"path": str(p), "sha256": sha(regular(p, private=True))} for p in items]
    # Each critical digest is explicitly approved, rather than inferred from a filename.
    approved = review.get("approved_sha256", {})
    need(all(approved.get(item["path"]) == item["sha256"] for item in attachments if item["path"] != str(review_path))
         and approved.get(str(journal_path)) == sha(regular(journal_path, private=True)),
         "independent review did not approve the actual original plan/backup/journal/evidence")
    with mutex(common):
        need(sha(regular(directory / STATE, private=True)) == review["state_sha256"]
             and sha(regular(directory / "metadata.tsv")) == review["metadata_sha256"],
             "window or new authority changed during independent admission")
        if state["phase"] == "maintain":
            lease(lease_id, allow_expired=True)
        else:
            same_source_lease(lease_id, allow_expired=True)
        archive = workspace / ("authority-rebind-" + sha(review_raw))
        need(not archive.exists(), "recovery reconciliation already consumed")
        archive.mkdir(mode=0o700)
        for name, data in (("original-state.json", regular(directory / STATE, private=True)),
                           ("metadata.tsv", regular(directory / "metadata.tsv")),
                           ("resources.tsv", regular(directory / "resources.tsv")),
                           ("original-journal.json", regular(journal_path, private=True)), ("review.json", review_raw)):
            fd = os.open(archive / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "wb") as stream: stream.write(data); stream.flush(); os.fsync(stream.fileno())
        attachments = [item for item in attachments if item["path"] != str(review_path)]
        attachments += [{"path": str(archive / name), "sha256": sha(regular(archive / name, private=True))}
                        for name in ("original-state.json", "metadata.tsv", "resources.tsv", "original-journal.json", "review.json")]
        record = {"protocol": "peanut.source-application-recovery-rebind.v1", "lease": lease_id, "owner": owner,
                  "admitted_at": int(time.time()),
                  "native_command": (["plugin:" + original["operation"], original["plugin_key"]]
                     if original["protocol"] == "peanut.source-development-plugin-plan.v1" else ["schema:application", "source-recovery"]),
                  "actor": {key: binding["authority"][key] for key in ("owner", "thread", "session", "gate")},
                  "workspace": str(workspace), "plan_path": str(plan_path), "attachments": attachments,
                  "journal_sha256": sha(regular(journal_path, private=True)), "journal_phase": journal["phase"],
                  "metadata_path": str(archive / "metadata.tsv"),
                  "resources_sha256": sha(regular(directory / "resources.tsv")),
                  "binding_expires_at": state["binding_expires_at"], "effective_expires_at": expires,
                  "original_state_sha256": review["state_sha256"], "review_sha256": sha(review_raw)}
        save(archive / "rebind.json", record)
        # This explicit native reconciliation issues new recovery-only authority;
        # ordinary renew still refuses expired leases. Preserve the exact old metadata.
        if expires > int(metadata["expires_at"]):
            replacement = "".join(k + chr(9) + (str(expires) if k == "expires_at" else v) + chr(10)
                                  for k, v in table(directory / "metadata.tsv", 2))
            temporary = directory / ("metadata.recovery-" + str(os.getpid()))
            fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "w") as stream: stream.write(replacement); stream.flush(); os.fsync(stream.fileno())
            os.replace(temporary, directory / "metadata.tsv")
        state.update(status="RECOVERY_ADMITTED", recovery={"record": {"path": str(archive / "rebind.json"),
                     "sha256": sha(regular(archive / "rebind.json", private=True))}})
        # Never fabricate COMPLETED, mutate the original plan/journal or clear its old failure.
        save(directory / STATE, state)


def window(lease_id, owner, step=None):
    """In-use state survives failures; the global mutex is held only for transitions."""
    operation, directory, metadata = lease(lease_id, recovery_step=step)
    need(owner == metadata["owner"], "operation owner mismatch")
    verify_native(lease_id, step)
    common = directory.parent.parent.parent
    with mutex(common):
        operation, directory, metadata = lease(lease_id, recovery_step=step)
        previous = live_state(directory)
        recovery = recovery_check(directory, metadata, previous) if previous and previous.get("recovery") else None
        if recovery:
            need(step == ("open" if recovery["restored"] else "restore"), "recovery authority forbids fresh migration or rehearsal")
        if previous is not None:
            need(operation["phase"] == "maintain" and previous.get("status") in (("RECOVERY_ADMITTED", "COMPLETED") if recovery else ("COMPLETED",))
                 and (recovery is not None or previous.get("actual_process_terminal") is True)
                 and previous.get("owner") == owner and previous.get("phase") == "maintain"
                 and not original_process_alive(previous["pid"], previous.get("process_birth"))
                 and (previous.get("child_pid") is None or (recovery is not None and not original_process_alive(previous["child_pid"], previous.get("child_birth"))))
                 and previous.get("operation_sha256") == next(iter(resource_map(table(directory / "resources.tsv", 3))[CONTRACT])),
                 "operation already started; reconcile its real terminal state")
        value = {"protocol": "peanut.application-operation-state.v1", "phase": operation["phase"],
                 "lease": lease_id, "owner": owner, "pid": os.getpid(),
                 "process_birth": process_birth(os.getpid()), "started_at": int(time.time()),
                 "status": "RUNNING", "actual_process_terminal": False, "child_pid": None,
                 "operation_sha256": next(iter(resource_map(table(directory / "resources.tsv", 3))[CONTRACT])),
                 "binding_expires_at": min(int(metadata["expires_at"]), int(time.time()) + operation["max_seconds"]),
                 "step": step}
        if previous is not None:
            for key in ("started_at", "binding_expires_at", "completed_step"):
                value[key] = previous[key]
        if recovery:
            value.update(recovery=previous["recovery"], started_at=int(time.time()), child_pid=None)
        need((recovery["effective_expires_at"] if recovery else value["binding_expires_at"]) > time.time() + 60,
             "original operation window expired; independent recovery rebind required")
        save(directory / STATE, value)
    return operation, directory, value


def renew_native(lease_id, owner, recovery_step=None):
    _, directory, metadata = lease(lease_id, recovery_step=recovery_step)
    need(metadata["owner"] == owner, "operation renewal owner mismatch")
    if recovery_step in ("restore", "open") and (live_state(directory) or {}).get("recovery"):
        # The existing independent recovery record is bounded. Never extend it
        # by renewing the expired original parent or its no-permission APP guard.
        return
    ttl = max(300, int(metadata["expires_at"]) - int(time.time()))
    result = subprocess.run(["bash", str(ROOT / "scripts/project-resource-lease"), "renew",
                             "--lease", lease_id, "--owner", owner, "--ttl", str(ttl)],
                            cwd=ROOT, capture_output=True, timeout=15)
    need(result.returncode == 0, "operation renewal rejected")
    lease(lease_id)


def command(operation, directory, value, lease_id, argv, *, accepted=(0,), environment=None, input_bytes=None):
    """Only fixed phase callers below construct argv; no caller-provided executable."""
    recovery_step = value.get("step") if value.get("recovery") else None
    verify_native(lease_id, recovery_step)
    current, _, metadata = lease(lease_id, recovery_step=recovery_step)
    need(current == operation, "operation changed before dispatch")
    need(int(metadata["expires_at"]) - time.time() >= 60, "operation needs renewal before dispatch")
    need(time.time() < value["started_at"] + operation["max_seconds"], "operation dispatch deadline expired")
    env = {key: item for key, item in os.environ.items()
           if key in ("HOME", "USER", "LOGNAME", "LANG", "LC_ALL", "TZ")}
    env["PATH"] = str(Path(sys.executable).parent) + ":" + str(Path(operation["php_executable"]).parent) + ":/usr/bin:/bin:/usr/sbin:/sbin"
    database = operation["database"]
    env.update({
        "PEANUT_DEPLOYMENT_TARGET": "local-development" if database["environment"] == "development" else database["environment"],
        "PEANUT_DATABASE_RESOURCE_ID": database["resource_id"],
        "PEANUT_DATABASE_CONSUMER": database["consumer"],
        "PEANUT_DATABASE_ENDPOINT_ID": database["upstream_endpoint"]["endpoint_id"],
        "PEANUT_RESOURCE_LEASE_PROOF": str(directory),
    })
    if environment:
        env.update(environment)
    child = subprocess.Popen(argv, cwd=operation["application"]["root"], env=env,
                             stdin=subprocess.PIPE if input_bytes is not None else subprocess.DEVNULL,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    value["child_pid"] = child.pid
    value["child_birth"] = process_birth(child.pid)
    save(directory / STATE, value)
    unknown = False
    while True:
        try:
            stdout, stderr = child.communicate(input=input_bytes, timeout=10)
            break
        except subprocess.TimeoutExpired:
            input_bytes = None
            try:
                # Existing resource semantics renew this exact lease, under the
                # short native mutex. No global mutex spans the child process.
                renew_native(lease_id, value["owner"], recovery_step)
            except Exception:
                unknown = True
                value["status"] = "UNKNOWN"
                save(directory / STATE, value)
            if time.time() > value["started_at"] + operation["max_seconds"]:
                unknown = True
                value["status"] = "UNKNOWN"
                save(directory / STATE, value)
            # Never force-stop a non-interruptible native install. Keep its real
            # process observed and its in-use resource boundary unreleasable.
    value["child_pid"] = None
    value["child_exit_code"] = child.returncode
    value["child_output"] = {"stdout_bytes": len(stdout), "stdout_sha256": sha(stdout),
                             "stderr_bytes": len(stderr), "stderr_sha256": sha(stderr)}
    save(directory / STATE, value)
    need(not unknown, "operation window became UNKNOWN; retain lease and native journals")
    verify_native(lease_id, recovery_step)
    need(child.returncode in accepted and len(stdout) <= 4 * 1024 * 1024
         and len(stderr) <= 4 * 1024 * 1024, "native first-install phase failed")
    return stdout



def install_metadata_sql(schema):
    need(re.fullmatch(r"[a-z][a-z0-9_]{0,63}", schema), "metadata requires one literal schema")
    return f"""USE `{schema}`;
SELECT JSON_OBJECT(
  'observed_at_utc', UTC_TIMESTAMP(6),
  'connection_id', CONNECTION_ID(),
  'database', DATABASE(),
  'server_uuid', @@server_uuid,
  'server_port', @@port,
  'version', VERSION(),
  'principal', CURRENT_USER(),
  'active_roles', CAST(CURRENT_ROLE() AS CHAR CHARACTER SET utf8mb4),
  'partial_revokes', @@GLOBAL.partial_revokes,
  'schema_rows', (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{schema}'),
  'tables_views', (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{schema}'),
  'triggers', (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{schema}'),
  'routines', (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '{schema}'),
  'events', (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = '{schema}')
) AS exact_metadata;
SHOW GRANTS FOR CURRENT_USER();"""


def install_metadata_contract(operation, document, registered):
    contract, db = operation["metadata_readonly"], operation["database"]
    need(set(contract) == {"tool", "sql_sha256", "session"}
         and re.fullmatch(r"wc_sess_[A-Za-z0-9_-]+", contract["session"]),
         "closed install metadata contract differs")
    tools = [row for row in document["resources"].get("tooling", [])
             if row.get("stable_resource_id") == contract["tool"]]
    need(len(tools) == 1, "install metadata tool is not uniquely registered")
    tool = tools[0]
    need(tool.get("execution_scope") == "same-install-operation-schema"
         and "required_management_locks" not in tool
         and tool.get("owner") == operation["owner"] == operation["thread"]
         and tool.get("database_resource_id") == db["resource_id"]
         and tool.get("database") == db["name"]
         and tool.get("namespace") == db["name"]
         and tool.get("port") == db["upstream_endpoint"]["port"]
         and tool.get("version") == "MySQL" + db["version"]
         and tool.get("transport") == "ssh-docker-exec"
         and tool.get("fallback") == "none"
         and db["environment"] in tool.get("environments", []), "install metadata scope differs")
    client = registered["management_entry"]
    for key in ("ssh_command", "ssh_alias", "docker_command", "container_name", "mysql_command"):
        need(tool.get(key) == client[key], "install metadata transport differs from registered database")
    need(canonical(client["ssh_command"]).is_file()
         and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", client["ssh_alias"])
         and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", client["container_name"]),
         "install metadata client identity invalid")
    for key in ("docker_command", "mysql_command"):
        path = Path(client[key])
        need(path.is_absolute() and ".." not in path.parts
             and not any(ord(char) < 32 for char in str(path)), "metadata remote client path invalid")
    sql = tool["SQL_templates"]["metadata_readonly"]
    need(set(tool["SQL_templates"]) == {"metadata_readonly"}
         and sha(sql.encode()) == contract["sql_sha256"], "fixed metadata SQL hash differs")
    # Only whole-line comments and the original result-column alias may differ.
    executable = "\n".join(line for line in sql.splitlines() if not line.lstrip().startswith("--")).strip()
    executable = re.sub(r"AS exact_(?:mt|st)_metadata;", "AS exact_metadata;", executable)
    need(executable == install_metadata_sql(db["name"]), "metadata SQL is not the closed exact-schema SELECT")
    return client, sql


def install_metadata(operation, directory, value, lease_id):
    # Run once inside the existing exclusive install schema window. No separate
    # management lease, released witness file, or server/retained-parent lock.
    _, _, _, registry_sha = authority(operation["stable_resource_id"])
    registry, _, resolver, current_sha = project_authority()
    need(current_sha == registry_sha, "metadata authority changed")
    document = resolver["load_registry"]()
    registered = next(row for row in document["resources"]["databases"]
                      if row.get("stable_resource_id") == operation["database"]["resource_id"])
    client, sql = install_metadata_contract(operation, document, registered)
    need("metadata_readonly" not in value, "metadata observation already consumed")
    value["metadata_readonly"] = {"status": "DISPATCHING", "sql_sha256": sha(sql.encode())}
    save(directory / STATE, value)
    mysql = [client["mysql_command"], "--no-defaults", "--no-login-paths", "--connect-timeout=5",
             "--batch", "--raw", "--skip-column-names", "-uroot", "--database=" + operation["database"]["name"]]
    inside = ('MYSQL_PWD=${MYSQL_ROOT_PASSWORD:?}; export MYSQL_PWD; exec '
              + shlex.join(["/usr/bin/timeout", "-k", "5s", "60s", *mysql]))
    remote = shlex.join([client["docker_command"], "exec", "-i", client["container_name"],
                         "/usr/bin/timeout", "-k", "5s", "65s", "/bin/sh", "-c", inside])
    raw = command(operation, directory, value, lease_id,
                  [client["ssh_command"], "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes",
                   "-o", "ConnectTimeout=5", "-o", "ServerAliveInterval=5", "-o", "ServerAliveCountMax=3",
                   client["ssh_alias"], remote], input_bytes=sql.encode())
    # Preserve raw identity/grants privately even when validation below refuses.
    witness = canonical(operation["receipt_root"]) / (lease_id + ".metadata-private.json")
    need(not witness.exists() and not witness.is_symlink(), "metadata witness already exists")
    save(witness, {"protocol": "peanut.install-metadata-readonly.v1", "lease": lease_id,
                   "owner": value["owner"], "operation_sha256": value["operation_sha256"],
                   "registry_sha256": registry_sha, "sql_sha256": sha(sql.encode()), "stdout": raw.decode()})
    lines = raw.decode().splitlines()
    need(len(lines) >= 2, "metadata identity/grants output incomplete")
    result = json_unique(lines[0])
    db = operation["database"]
    need(result["database"] == db["name"] and result["server_uuid"] == db["server_uuid"]
         and result["version"] == db["version"] and result["server_port"] == 3306
         and type(result["schema_rows"]) is int and result["schema_rows"] == 1
         and result["principal"] == "root@localhost"
         and result["active_roles"] == "NONE" and result["partial_revokes"] == 0,
         "metadata identity/roles/revocations require reconciliation")
    privileges = set()
    for grant in lines[1:]:
        match = re.fullmatch(r"GRANT (.+) ON (.+) TO (.+?)(?: WITH GRANT OPTION)?", grant)
        need(match is not None and match[3] in ("`root`@`localhost`", "'root'@'localhost'"),
             "metadata grants require direct current-principal privilege proof")
        scope = match[2]
        exact = "`" + db["name"].replace("_", "\\_") + "`.*"
        if scope not in ("*.*", "`" + db["name"] + "`.*", exact):
            continue
        names = set(match[1].split(", "))
        if "ALL PRIVILEGES" in names:
            names |= {"SELECT", "TRIGGER", "EVENT", "EXECUTE"}
        privileges |= names
    need({"SELECT", "TRIGGER", "EVENT"} <= privileges
         and bool({"CREATE ROUTINE", "ALTER ROUTINE", "EXECUTE", "SHOW_ROUTINE"} & privileges),
         "complete metadata visibility not proved")
    need(all(type(result[key]) is int and result[key] == 0
             for key in ("tables_views", "triggers", "routines", "events")),
         "first-install requires complete empty metadata; never clear objects")
    value["metadata_readonly"].update(status="COMPLETED", witness=str(witness),
                                     witness_sha256=sha(regular(witness, private=True)), result=result)
    save(directory / STATE, value)


def application_metadata(operation, directory, value, lease_id):
    app, db = operation["application"], operation["database"]
    # The APP keeps its own registry and ordinary credentials. Fixed operation
    # selections and the native proof enter only through command's process inputs.
    php = r"""require 'server/database/environment-guard.php';
$proof=getenv('PEANUT_RESOURCE_LEASE_PROOF');
$c=guardedDatabaseConfig($proof===false||trim($proof)===''?null:$proof);
$p=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset=utf8mb4',
$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>10]);
$v=json_decode($p->query("SELECT JSON_OBJECT('database',DATABASE(),'version',VERSION(),'server_uuid',@@server_uuid,'principal',CURRENT_USER(),'tables_views',(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()),'triggers',(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()),'routines',(SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()),'events',(SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()))")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
$v['host']=$c['host'];$v['port']=(string)$c['port'];echo json_encode($v),PHP_EOL;"""
    result = json_unique(command(operation, directory, value, lease_id,
                                 [operation["php_executable"], "-r", php]))
    endpoint = db["upstream_endpoint"]
    need(result["database"] == db["name"] and result["server_uuid"] == db["server_uuid"]
         and result["version"] == db["version"] and result["principal"] == db["principal"]
         and result["host"] == endpoint["host"] and result["port"] == str(endpoint["port"]),
         "native APP database identity changed")
    need(all(result[key] == 0 for key in ("tables_views", "triggers", "routines", "events")),
         "first-install requires the exact pristine schema; never clear existing objects")
    return result


def protect(operation, directory, value, lease_id):
    import shlex
    import tarfile
    app = canonical(operation["application"]["root"])
    metadata = application_metadata(operation, directory, value, lease_id)
    output = canonical(operation["output_root"])
    need(not output.exists(), "preinstall output already exists; reconcile, never overwrite")
    output.mkdir(mode=0o700)
    client = operation["backup_client"]
    need(set(client) == {"ssh", "ssh_alias", "docker", "container", "mysql", "mysqldump",
                         "credential_path", "user"}, "native backup client closure differs")
    need(client["user"] == operation["database"]["principal"].split("@", 1)[0]
         and client["user"] != "root", "native backup requires the same ordinary principal")
    ref = Path(client["credential_path"])  # Registered remote path; never resolve it on this host.
    # Only the registered remote ordinary credential reference is read. Neither
    # its value nor MYSQL_PWD is placed in command arguments or receipts.
    identity_sql = "SELECT CONCAT(@@server_uuid,'|',DATABASE(),'|',VERSION(),'|',CURRENT_USER())"
    mysql = [client["docker"], "exec", "--env", "MYSQL_PWD", client["container"], client["mysql"],
             "--user=" + client["user"], "--batch", "--skip-column-names",
             "--database=" + operation["database"]["name"], "-e", identity_sql]
    dump = [client["docker"], "exec", "--env", "MYSQL_PWD", client["container"], client["mysqldump"],
            "--user=" + client["user"], "--single-transaction", "--no-tablespaces", "--skip-triggers",
            "--set-gtid-purged=OFF", "--databases", operation["database"]["name"]]
    script = ("set -euo pipefail\n"
              "test -f " + shlex.quote(str(ref)) + "\n"
              "test ! -L " + shlex.quote(str(ref)) + "\n"
              "test \"$(/usr/bin/stat -f %Lp " + shlex.quote(str(ref)) + ")\" = 600\n"
              "set -a\n. " + shlex.quote(str(ref)) + "\nset +a\n"
              "test \"$DB_USER\" = " + shlex.quote(client["user"]) + "\n"
              "MYSQL_PWD=\"$DB_PASS\"; export MYSQL_PWD\n"
              + shlex.join(mysql) + "\n" + shlex.join(dump) + "\n")
    raw = command(operation, directory, value, lease_id,
                  [client["ssh"], "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes",
                   "-o", "ConnectTimeout=5", client["ssh_alias"], "/bin/bash", "-s"],
                  input_bytes=script.encode())
    identity, sql = raw.split(b"\n", 1)
    need(identity.decode() == "|".join(metadata[key] for key in
                                      ("server_uuid", "database", "version", "principal")),
         "native dump endpoint differs from the APP database")
    need(b"Dump completed" in sql and b"CREATE TABLE" not in sql
         and operation["database"]["name"].encode() in sql, "native empty preinstall dump incomplete")
    dump_path = output / "preinstall.sql"
    with os.fdopen(os.open(dump_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), "wb") as stream:
        stream.write(sql)
    excluded = {".local", ".git/peanut-admin-resource-leases", "server/vendor",
                "server/runtime/cache", "server/runtime/log", "server/runtime/session",
                "server/runtime/temp", "server/runtime/generator",
                "web/node_modules", "platform/node_modules", "pc/node_modules", "uniapp/node_modules"}
    records = {}
    def identity_of(path):
        info = path.lstat()
        # Tar preserves special permission bits too; the preimage and later
        # first-install check must retain sticky/setgid/setuid without masking.
        if stat.S_ISREG(info.st_mode):
            return {"kind": "file", "mode": stat.S_IMODE(info.st_mode), "sha256": sha(path.read_bytes())}
        if stat.S_ISLNK(info.st_mode):
            return {"kind": "link", "mode": stat.S_IMODE(info.st_mode), "target": os.readlink(path)}
        need(stat.S_ISDIR(info.st_mode), "unsupported protected file type")
        return {"kind": "directory", "mode": stat.S_IMODE(info.st_mode)}
    archive_path = output / "application-and-git.tar.gz"
    last_renewed = time.monotonic()
    with os.fdopen(os.open(archive_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), "wb") as stream:
        with tarfile.open(fileobj=stream, mode="w:gz", dereference=False) as archive:
            for here, dirs, files in os.walk(app, followlinks=False):
                relative = Path(here).relative_to(app)
                dirs[:] = sorted(name for name in dirs if str(relative / name) not in excluded)
                for name in dirs + sorted(files):
                    path = Path(here) / name
                    key = path.relative_to(app).as_posix()
                    records[key] = identity_of(path)
                    archive.add(path, arcname=key, recursive=False)
                    need(time.time() < value["started_at"] + operation["max_seconds"], "protection capture deadline expired")
                    if time.monotonic() - last_renewed >= 10:
                        renew_native(lease_id, value["owner"])
                        last_renewed = time.monotonic()
    for key, record in records.items():
        need(identity_of(app / key) == record, "APP changed during preinstall capture")
    with tarfile.open(archive_path, "r:gz") as archive:
        members = archive.getmembers()
        need(len(members) == len(records) and {item.name for item in members} == set(records),
             "protected archive membership differs")
        for member in members:
            record = records[member.name]
            need(member.mode == record["mode"], "protected archive mode differs")
            if member.isfile():
                need(sha(archive.extractfile(member).read()) == record["sha256"], "protected archive bytes differ")
            if member.issym():
                need(member.linkname == record["target"], "protected archive link differs")
    need(application_metadata(operation, directory, value, lease_id) == metadata,
         "pristine database changed during preinstall capture")
    manifest = {"protocol": "peanut.application-preinstall-protection.v1",
                "status": "CAPTURE_VERIFIED_NOT_RESTORE_QUALIFIED", "app_root": str(app),
                "app_head": operation["application"]["commit"], "database": operation["database"]["name"],
                "database_metadata": metadata, "source_files": records,
                "excluded_reproducible_or_runtime": sorted(excluded),
                "archive": {"path": str(archive_path), "sha256": sha(archive_path.read_bytes())},
                "database_dump": {"path": str(dump_path), "sha256": sha(sql)}}
    path = output / "protection-manifest.json"
    save(path, manifest)
    return {"manifest": str(path), "manifest_sha256": sha(regular(path, private=True)),
            "covered_items": len(records), "restore_qualified": False}



def maintenance_review(operation, directory, step, plan_path):
    """A real versioned independent review, never an untracked success assertion."""
    path = canonical(operation["maintenance"]["review"])
    project = project_authority()[1]
    raw = regular(path, private=True)
    relative = str(path.relative_to(project))
    need(git(project, "show", "HEAD:" + relative) == raw, "maintenance review must be the exact versioned Project result")
    proof = json_unique(raw)
    need(proof.get("verdict") == "SOURCE_APP_MAINTENANCE_ADMITTED"
         and proof.get("owner") == operation["owner"] and proof.get("lease") == directory.name
         and proof.get("plan_sha256") == sha(regular(plan_path, private=True))
         and step in proof.get("steps", [])
         and proof.get("reviewer_thread") not in (None, "", operation["owner"]),
         "fixed maintenance step lacks its actual independent review")


def maintain(operation, directory, value, lease_id, step):
    contract = operation["maintenance"]
    root = canonical(operation["output_root"])
    root.mkdir(mode=0o700, exist_ok=True)
    need(root.stat().st_uid == os.getuid() and stat.S_IMODE(root.stat().st_mode) == 0o700,
         "maintenance output owner/mode changed")
    plan_path = root / "plan.json"
    previous = value.get("completed_step")
    allowed = {None: {"plan"}, "plan": {"capture"} if contract["kind"] == "application-schema" else {"rehearse"},
               "capture": {"rehearse"}, "rehearse": {"rebind"} if contract["kind"] == "application-schema" else {"apply"},
               "rebind": {"apply"}, "apply": {"verify", "restore"},
               "restore": set(), "verify": {"open"}, "open": set()}
    rebound = recovery_check(directory, dict(table(directory / "metadata.tsv", 2)), value) if value.get("recovery") else None
    need(step in ({"open" if rebound["restored"] else "restore"} if rebound else allowed.get(previous, set())),
         "maintenance step was already executed or is out of order")
    if not rebound and step in ("rehearse", "apply", "restore", "open"):
        reviewed_plan = root / "admitted-plan.json" if step == "apply" and contract["kind"] == "application-schema" else plan_path
        maintenance_review(operation, directory, step, reviewed_plan)
    base = [operation["php_executable"], "server/think"]
    actor = ["--scope=source-application", "--owner=" + operation["owner"],
             "--thread=" + operation["thread"], "--session=" + contract["session"],
             "--gate=permanent-app-development"]
    metadata = ["--metadata-tool=" + contract["metadata_tool"]] if contract["metadata_tool"] else []
    backup = root / "backup"
    workspace = backup / "application-recovery"
    if step == "open":
        argv = base + ["schema:application", "source-recovery", *actor, "--application-recovery=open"]
    elif step in ("rehearse", "restore"):
        name = "plugin:" + contract["operation"] if contract["kind"] == "plugin" else "schema:application"
        target = [contract["plugin_key"]] if contract["kind"] == "plugin" else ["source-recovery"]
        argv = base + [name, *target, *actor, "--application-recovery=" + step,
                       "--source-plan=" + str(plan_path), "--recovery=" + str(workspace)]
    elif contract["kind"] == "plugin":
        argv = base + ["plugin:" + contract["operation"], contract["plugin_key"], *actor, *metadata]
        if step == "plan":
            argv += ["--dry-run", "--backup-tool=" + contract["backup_tool"], "--backup-output=" + str(backup)]
        elif step == "apply":
            argv += ["--source-plan=" + str(plan_path), "--maintenance=" + str(backup / "maintenance.json"),
                     "--recovery=" + str(backup / "recovery.json")]
        else:
            result = json_unique(regular(root / "apply.json", private=True))
            argv += ["--dry-run", "--verify-receipt=" + result["result_path"]]
            # Receipt verification has no metadata/capture arguments.
            argv = [arg for arg in argv if not arg.startswith("--metadata-tool=")]
    else:
        native = {"plan": "preflight", "capture": "backup", "rebind": "preflight", "apply": "apply", "verify": "verify"}[step]
        argv = base + ["schema:application", native, *actor, *metadata]
        if step in ("capture", "apply"):
            argv += ["--plan=" + str(root / "admitted-plan.json" if step == "apply" else plan_path)]
        if step == "capture":
            argv += ["--backup-tool=" + contract["backup_tool"], "--backup-output=" + str(backup)]
        if step in ("rebind", "apply"):
            argv += ["--maintenance=" + str(backup / "maintenance.json"), "--recovery=" + str(backup / "recovery.json")]
        if step == "verify":
            result = json_unique(regular(root / "apply.json", private=True))
            argv += ["--result=" + result["result_path"]]
    output = command(operation, directory, value, lease_id, argv,
                     environment={"PEANUT_RESOURCE_LEASE_PROOF": str(directory)})
    result = json_unique(output)
    target = plan_path if step == "plan" else root / ("admitted-plan.json" if step == "rebind" else step + ".json")
    need(not target.exists() and not target.is_symlink(), "maintenance result already exists; no replay")
    save(target, result)
    value.update(completed_step=step, status="COMPLETED", actual_process_terminal=True,
                 result={"path": str(target), "sha256": sha(regular(target, private=True))})
    return value["result"]

def execute(lease_id, owner, phase, step=None):
    selected, _, _ = lease(lease_id, recovery_step=step)
    need(selected["phase"] == phase, "closed application phase differs from registration")
    operation, directory, value = window(lease_id, owner, step)
    try:
        receipt_root = canonical(operation["receipt_root"])
        receipt_root.mkdir(mode=0o700, exist_ok=True)
        need(receipt_root.stat().st_uid == os.getuid()
             and stat.S_IMODE(receipt_root.stat().st_mode) == 0o700, "private operation receipt root required")
        need(operation["phase"] == phase, "closed application phase differs from registration")
        if phase == "maintain":
            maintain(operation, directory, value, lease_id, step)
            save(directory / STATE, value)
            print(json.dumps(value["result"]))
            return
        if phase == "protect":
            value.update(result=protect(operation, directory, value, lease_id),
                         status="COMPLETED", actual_process_terminal=True)
            save(directory / STATE, value)
            print(json.dumps({"status": "COMPLETED", "lease": lease_id, "result": value["result"]}))
            return
        # Phases stay closed: no arbitrary program, shell, hook or environment.
        protection = operation["protection"]
        manifest = json_unique(regular(canonical(protection["manifest"]), private=True))
        need(sha(regular(canonical(protection["manifest"]), private=True)) == protection["manifest_sha256"],
             "preinstall protection manifest changed")
        need(sha(regular(canonical(protection["review"]), private=True)) == protection["review_sha256"],
             "independent protection review changed")
        need(manifest["app_root"] == operation["application"]["root"]
             and manifest["app_head"] == operation["application"]["commit"]
             and manifest["database"] == operation["database"]["name"], "protection belongs to another APP")
        app = canonical(operation["application"]["root"])
        for name, record in manifest["source_files"].items():
            relative = Path(name)
            need(name and not relative.is_absolute() and ".." not in relative.parts,
                 "protected APP member path is unsafe")
            path = app / relative
            info = path.lstat()
            need(stat.S_IMODE(info.st_mode) == record["mode"], "protected APP member mode changed")
            if record["kind"] == "file":
                need(sha(regular(path)) == record["sha256"], "protected APP file changed before first-install")
            elif record["kind"] == "link":
                need(path.is_symlink() and os.readlink(path) == record["target"], "protected APP link changed")
            else:
                need(record["kind"] == "directory" and path.is_dir() and not path.is_symlink(),
                     "protected APP directory changed")
        install_metadata(operation, directory, value, lease_id)
        application_metadata(operation, directory, value, lease_id)
        for name in ("archive", "database_dump"):
            item = manifest[name]
            need(sha(regular(canonical(item["path"]), private=True)) == item["sha256"],
                 "preinstall protection artifact changed")
        credentials = operation["credentials"]
        need(sha(regular(canonical(credentials["path"]), private=True)) == credentials["sha256"],
             "one-time installation credentials changed")
        app = canonical(operation["application"]["root"])
        for name in ("installed.json", "executing.json", "baseline.json", "migration.json"):
            path = app / "server/private/installation" / name
            need(not path.exists() and not path.is_symlink(), "installation state requires reconciliation")
        php = canonical(operation["php_executable"])
        need(php.is_file() and os.access(php, os.X_OK), "registered PHP executable unavailable")
        preflight = json_unique(command(operation, directory, value, lease_id,
                                        [str(php), "server/database/install.php", "--preflight"]))
        need(preflight.get("status") == "ready", "native first-install preflight not ready")
        status_output = command(operation, directory, value, lease_id,
                                [str(php), "server/database/install.php", "--status"], accepted=(1,))
        pristine_installation_status(status_output)
        output = command(operation, directory, value, lease_id, [str(php), "server/database/install.php"],
                         environment={"PEANUT_INSTALLATION_ENV_FILE": credentials["path"]})
        result = json_unique(output)
        need((app / "server/private/installation/installed.json").is_file(), "native installed receipt missing")
        value.update(status="COMPLETED", actual_process_terminal=True, result=result)
    except BaseException as error:
        value.update(status="UNKNOWN", failure=type(error).__name__,
                     actual_process_terminal=value.get("child_pid") is None)
        save(directory / STATE, value)
        raise
    save(directory / STATE, value)
    print(json.dumps({"status": value["status"], "lease": lease_id,
                      "actual_process_terminal": True, "state_path": str(directory / STATE)}))




def same_source_lease(lease_id, allow_expired=False):
    """The ordinary APP owns its Git/resource domain; never select a foreign lock store."""
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    directory = common / "peanut-admin-resource-leases/leases" / lease_id
    metadata = dict(table(directory / "metadata.tsv", 2))
    rows = table(directory / "resources.tsv", 3)
    resources = resource_map(rows)
    registry = ROOT / "server/private/resources/project-resources.json"
    document = json_unique(regular(registry, private=True))
    manifest = json_unique(regular(ROOT / ".peanut/application-manifest.json"))
    selected = [row for row in document["resources"]["databases"]
                if row.get("stable_resource_id") in resources.get("resource-id", set())]
    need(document.get("authority", {}).get("role") == "application"
         and document.get("project_id") == manifest["application"]["slug"] and len(selected) == 1,
         "ordinary source APP database authority unavailable")
    db = selected[0]
    need(not db.get("lease_policy", {}).get("native_authority"),
         "registered central authority forbids an APP-local lock store")
    need(metadata["lease"] == lease_id and metadata["status"] == "ACTIVE"
         and metadata["parent_lease"] == "" and metadata["candidate_repository"] == metadata["worktree"] == str(ROOT)
         and metadata["candidate"] == git(ROOT, "rev-parse", "HEAD").decode().strip()
         and metadata["gate"] == db["lease_gate"] and int(metadata["created_at"]) <= time.time()
         and (allow_expired or int(metadata["expires_at"]) > time.time())
         and resources.get("registry-sha256") == {sha(regular(registry, private=True))},
         "ordinary source APP lease binding changed")
    endpoint = db["upstream_endpoint"]
    schema = str(endpoint["host"]) + ":" + str(endpoint["port"]) + "/" + db["database"]
    need(resources.get("mysql-schema") == {schema} and resources.get("endpoint") == {endpoint["endpoint_id"]}
         and resources.get("mysql-db") == resources.get("database") == {db["database"]},
         "ordinary source APP exact schema lease required")
    access = resources.get("mysql-access")
    if access:
        need(len(access) == 1 and json_unique(next(iter(access))) ==
             {"schema": schema, "operation": "exclusive", "complete": True, "tables": []},
             "ordinary source APP whole-schema exclusive access required")
    else:
        need("shared_access" not in db.get("lease_policy", {}), "shared APP needs a complete access declaration")
    identity = {"resource-id", "database-resource", "endpoint", "resource-scope", "scope", "gate", "mysql-db",
                "database", "mysql-access", "registry-sha256", "registry-tool-sha256", "environment", "consumer", "host", "version",
                "application-registry-sha256", "application-manifest-sha256", "application-env-sha256"}
    for digest, kind, item in rows:
        if kind in identity:
            continue
        lock = common / "peanut-admin-resource-leases/resources" / digest
        for field, expected in (("lease", lease_id), ("type", kind), ("value", item)):
            need(regular(lock / field).decode().strip() == expected, "ordinary APP physical lock changed")
    return common, directory, metadata


def same_source_window(lease_id, owner, close=False):
    env = dict(os.environ, PEANUT_RESOURCE_REGISTRY=str(ROOT / "server/private/resources/project-resources.json"))
    result = subprocess.run(["bash", str(ROOT / "scripts/project-resource-lease"), "show",
                             "--lease", lease_id, "--verify-locks"], cwd=ROOT, env=env, capture_output=True)
    need(result.returncode == 0, "ordinary APP native lease verification rejected")
    common, directory, metadata = same_source_lease(lease_id)
    parent = os.getppid()
    command = subprocess.run(["/bin/ps", "-p", str(parent), "-o", "comm="], capture_output=True, text=True)
    need(command.returncode == 0 and Path(command.stdout.strip()).name == "php"
         and owner == metadata["owner"], "source window requires its actual native PHP owner")
    with mutex(common):
        _, directory, metadata = same_source_lease(lease_id)
        previous = live_state(directory)
        if close:
            need(previous is not None and previous.get("phase") == "source-application"
                 and previous.get("status") == "RUNNING" and previous.get("pid") == parent
                 and original_process_alive(parent, previous.get("process_birth")),
                 "ordinary APP source window is not owned by this PHP process")
            previous.update(status="WINDOW_CLOSED", operation_terminal=True)
            save(directory / STATE, previous)
            return
        rebound = recovery_check(directory, metadata, previous, parent=parent) if previous and previous.get("recovery") else None
        need(previous is None or (previous.get("phase") == "source-application"
             and previous.get("status") in (("RECOVERY_ADMITTED", "WINDOW_CLOSED") if rebound else ("WINDOW_CLOSED",))
             and not original_process_alive(previous["pid"], previous.get("process_birth"))),
             "prior APP source window requires real terminal reconciliation")
        need(previous is None or int(rebound["effective_expires_at"] if rebound else previous["binding_expires_at"]) > time.time() + 60,
             "original source APP window expired; independent recovery rebind required")
        save(directory / STATE, {"protocol": "peanut.application-operation-state.v1", "phase": "source-application",
            "lease": lease_id, "owner": owner, "pid": parent, "process_birth": process_birth(parent),
            "child_pid": None, "status": "RUNNING", "operation_terminal": False,
            "binding_expires_at": metadata["expires_at"] if previous is None else previous["binding_expires_at"],
            **({"recovery": previous["recovery"]} if rebound else {})})


def consume_same_source(lease_id):
    common, directory, metadata = same_source_lease(lease_id)
    with mutex(common):
        same_source_lease(lease_id)
        state = live_state(directory)
        rebound = recovery_check(directory, metadata, state, parent=os.getppid()) if state and state.get("recovery") else None
        need(state is not None and state.get("phase") == "source-application"
             and state.get("status") == "RUNNING" and state.get("pid") == os.getppid()
             and original_process_alive(state["pid"], state.get("process_birth"))
             and int(state["binding_expires_at"]) <= int(metadata["expires_at"])
             and int(rebound["effective_expires_at"] if rebound else state["binding_expires_at"]) > time.time(),
             "ordinary APP recovery requires its actual live PHP window")
        return rebound or {"binding_expires_at": state["binding_expires_at"]}

def consume_application(proof, application_root, registry_path, resource_id, endpoint_id):
    """APP-owned read-only proof consumer. Never resolves or reads private Project."""
    app = canonical(application_root)
    registry_path = canonical(registry_path)
    need(registry_path == app / "server/private/resources/project-resources.json",
         "application consumer requires its own private registry")
    registry_raw = regular(registry_path, private=True)
    document = json_unique(registry_raw)
    need(document.get("authority", {}).get("role") == "application", "application registry authority required")
    databases = [row for row in document.get("resources", {}).get("databases", [])
                 if row.get("stable_resource_id") == resource_id]
    need(len(databases) == 1, "application database registration ambiguous")
    db = databases[0]
    policy = db.get("lease_policy", {})
    tool = policy.get("native_authority", {})
    common = canonical(tool["common_dir"])
    need(canonical(tool["root"]) == ROOT
         and canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip()) == common
         and git(ROOT, "rev-parse", "HEAD").decode().strip() == tool["commit"],
         "application native tool source/common-dir changed")
    closure = {"scripts/project-resource-lease", "scripts/project-resource-application.py",
               "scripts/project-resource-registry"}
    need(set(tool["files"]) == closure, "application native tool closure incomplete")
    for name, digest in tool["files"].items():
        raw = regular(ROOT / name)
        need(sha(raw) == digest and git(ROOT, "show", tool["commit"] + ":" + name) == raw,
             "application native tool bytes changed")
    need(not git(ROOT, "status", "--porcelain=v1", "--untracked-files=all"),
         "application native tool source dirty")
    proof = canonical(proof)
    need(proof.parent == common / "peanut-admin-resource-leases/leases",
         "application proof uses another lock registry")
    # A Popen child may reach its guard before the parent publishes child_pid.
    # This bounded read-only wait grants nothing; UNKNOWN is never accepted.
    for attempt in range(20):
        with mutex(common):
            state = live_state(proof)
            need(state is not None and state.get("status") == "RUNNING"
                 and state.get("phase") == "maintain", "held native APP maintenance window required")
            if state.get("child_pid") is not None:
                break
        time.sleep(0.05)
    else:
        raise RuntimeError("native APP child identity was not published")
    with mutex(common):
        state = live_state(proof)
        meta = dict(table(proof / "metadata.tsv", 2))
        rows = table(proof / "resources.tsv", 3)
        resources = resource_map(rows)
        now = time.time()
        need(state.get("status") == "RUNNING" and state.get("phase") == "maintain"
             and state.get("lease") == proof.name and state.get("owner") == meta["owner"]
             and state.get("child_pid") == os.getppid()
             and original_process_alive(state["child_pid"], state.get("child_birth"))
             and original_process_alive(state["pid"], state.get("process_birth")),
             "application verifier is outside the actual native child process")
        rebound = recovery_check(proof, meta, state, parent=os.getppid()) if state.get("recovery") else None
        need(meta["status"] == "ACTIVE" and meta["lease"] == proof.name
             and meta["owner"] == meta["thread"]
             and meta["gate"] == db["lease_gate"] == "permanent-app-development"
             and meta["candidate_repository"] == meta["worktree"] == str(app)
             and canonical(git(app, "rev-parse", "--show-toplevel").decode().strip()) == app
             and git(app, "rev-parse", "HEAD").decode().strip() == meta["candidate"]
             and int(meta["created_at"]) <= now < int(meta["expires_at"])
             and int(meta["expires_at"]) >= (rebound["effective_expires_at"] if rebound else state["binding_expires_at"]) > now,
             "application native lease identity/expiry changed")
        need(not git(app, "diff", "--name-only", "HEAD") and not git(app, "diff", "--cached", "--name-only"),
             "application tracked source changed")
        endpoint = db["upstream_endpoint"]
        schema = str(endpoint["host"]) + ":" + str(endpoint["port"]) + "/" + db["database"]
        need(endpoint["endpoint_id"] == endpoint_id and policy.get("mode") == "schema"
             and policy.get("physical_endpoint") == schema.rpartition("/")[0],
             "application exact schema endpoint changed")
        required = {"resource-id": {resource_id}, "endpoint": {endpoint_id},
                    "application-root": {str(app)}, "worktree": {str(app), str(ROOT)},
                    "mysql-schema": {schema}, "mysql-db": {db["database"]}, "database": {db["database"]},
                    "application-registry-sha256": {sha(registry_raw)},
                    "application-manifest-sha256": {sha(regular(app / ".peanut/application-manifest.json"))},
                    "application-env-sha256": {sha(regular(app / "server/.env", private=True))},
                    "mysql-access": {json.dumps({"schema": schema, "operation": "exclusive",
                                                 "complete": True, "tables": []},
                                                sort_keys=True, separators=(",", ":"))}}
        need(all(resources.get(key) == values for key, values in required.items())
             and len(resources.get(BINDING, set())) == 1
             and resources.get(CONTRACT) == {state["operation_sha256"]},
             "application native resource declaration changed")
        # Consume only native APP/lock facts, without reading private Project.
        retained = resources.get("retained-parent", set())
        need(len(retained) <= 1, "application retained parent is ambiguous")
        operation = {"application": {"root": str(app), "commit": meta["candidate"]},
                     "owner": meta["owner"], "thread": meta["thread"],
                     "stable_resource_id": next(iter(resources[BINDING]))}
        if retained:
            operation["retained_authority"] = json_unique(next(iter(retained)))
        need(meta["parent_lease"] == operation.get("retained_authority", {}).get("lease", ""),
             "application retained parent declaration changed")
        parent = retained_parent(operation, common, require_live=rebound is None)
        if parent:
            need(rebound is not None or int(meta["expires_at"]) <= int(parent[1]["expires_at"]), "application TTL exceeds retained parent")
            retained_guard(operation, common, proof.name, expires=int(meta["expires_at"]),
                           allow_expired_parent=rebound is not None)
        # The central producer admits this exclusive schema using its complete
        # policy. The APP consumes the actual physical locks under that same mutex.
        identity = {"resource-id", "database-resource", "endpoint", "resource-scope", "scope", "gate",
                    "mysql-db", "database", "mysql-access", "registry-sha256", "registry-tool-sha256", "application-registry-sha256", "application-manifest-sha256", "application-env-sha256",
                    "environment", "consumer", "host", "version"}
        for digest, kind, item in rows:
            if kind in identity:
                continue
            lock = common / "peanut-admin-resource-leases/resources" / digest
            for field, expected in (("lease", physical_holder(operation, common, proof.name, kind, item)), ("type", kind), ("value", item)):
                need(regular(lock / field).decode().strip() == expected, "application physical lock changed")
        return (rebound or {"binding_expires_at": state["binding_expires_at"]}) | {"operation_sha256": state["operation_sha256"]}

ALLOCATION_PROTOCOL = "peanut.schema-allocation.v1"
ALLOCATION_STATE = "schema-allocation.json"
ALLOCATION_PRIVILEGES = {"SELECT", "INSERT", "UPDATE", "DELETE", "CREATE", "ALTER", "DROP",
                         "INDEX", "REFERENCES", "CREATE TEMPORARY TABLES"}
ALLOCATION_GLOBAL_COLUMNS = {
    "SELECT": "Select_priv", "INSERT": "Insert_priv", "UPDATE": "Update_priv", "DELETE": "Delete_priv",
    "CREATE": "Create_priv", "ALTER": "Alter_priv", "DROP": "Drop_priv", "INDEX": "Index_priv",
    "REFERENCES": "References_priv", "CREATE TEMPORARY TABLES": "Create_tmp_table_priv",
    "CREATE USER": "Create_user_priv", "GRANT OPTION": "Grant_priv",
}


def allocation_admin_authority(result, op, databases):
    # mysql.user is the direct global static privilege authority. SHOW GRANTS
    # in MySQL8.4 deliberately expands ALL, and lists dynamic/PROXY separately.
    need(result["uuid"] == op["server_uuid"] and result["version"] == op["version"]
         and result["principal"] == "root@localhost" and result["mandatory_roles"] in ("", "NONE")
         and result["partial_revokes"] in (0, 1)
         and set(result["privileges"]) == set(ALLOCATION_GLOBAL_COLUMNS)
         and all(value == "Y" for value in result["privileges"].values()),
         "allocation required direct global privileges/grant option not proved")
    restrictions = result["restrictions"]
    need(restrictions is None or isinstance(restrictions, list), "allocation privilege restrictions unknown")
    need(result["partial_revokes"] == 1 or not restrictions, "allocation partial-revoke state differs")
    names = {database["database"] for database in databases}
    for restriction in restrictions or []:
        need(isinstance(restriction, dict) and set(restriction) == {"Database", "Privileges"}
             and isinstance(restriction["Database"], str) and isinstance(restriction["Privileges"], list)
             and all(isinstance(p, str) and re.fullmatch(r"[A-Z][A-Z ]*", p) for p in restriction["Privileges"]),
             "allocation privilege restriction shape unknown")
        denied = set(restriction["Privileges"])
        # Global SELECT proves complete SCHEMATA/account visibility only when
        # neither the target schemas nor the mysql grant tables restrict it.
        need(not (restriction["Database"] in names and denied & ALLOCATION_PRIVILEGES)
             and not (restriction["Database"] == "mysql" and "SELECT" in denied),
             "allocation target privileges/metadata visibility partially revoked")


def allocation_authority(operation_id):
    """A reviewed registry entry, not caller SQL, defines this one namespace."""
    need(isinstance(operation_id, str) and re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,63}", operation_id),
         "invalid allocation operation ID")
    registry, project, _, registry_sha = project_authority()
    document = json_unique(regular(registry))
    matches = [r for r in document["resources"].get("schema_allocations", [])
               if r.get("stable_resource_id") == operation_id]
    need(len(matches) == 1, "allocation must be uniquely registered")
    op = matches[0]
    need(set(op) - {"retry_of"} == {"stable_resource_id", "protocol", "owner", "thread", "tool_source", "lease_gate",
                     "database_resource_id", "endpoint_id", "server_uuid", "version", "account_host",
                     "credentials", "transport", "receipt_root", "php_executable", "max_seconds", "paired_operations"}
         and op["protocol"] == ALLOCATION_PROTOCOL, "allocation closed contract differs")
    pair = op["paired_operations"]
    need(isinstance(pair, list) and len(pair) == 2 and all(isinstance(i, str) for i in pair)
         and pair == sorted(set(pair)) and operation_id in pair, "allocation exact ordered pair missing")
    need(op["owner"] == op["thread"] == os.environ.get("CODEX_THREAD_ID")
         and re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,63}", op["lease_gate"]), "allocation real actor/gate differs")
    need(type(op["max_seconds"]) is int and 60 <= op["max_seconds"] <= 300, "allocation deadline invalid")
    source = op["tool_source"]
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    need(set(source) == {"root", "common_dir", "commit", "files"}
         and source["root"] == str(ROOT) and source["common_dir"] == str(common)
         and common.parent.name == "peanut-admin-code" and common.name == ".git"
         and git(ROOT, "rev-parse", "HEAD").decode().strip() == source["commit"]
         and not git(ROOT, "status", "--porcelain=v1", "--untracked-files=all"), "allocation fixed clean source differs")
    files = {"scripts/project-resource-lease", "scripts/project-resource-application.py", "scripts/project-resource-registry"}
    need(set(source["files"]) == files, "allocation source closure differs")
    for name, digest in source["files"].items():
        raw = regular(ROOT / name)
        need(sha(raw) == digest and git(ROOT, "show", source["commit"] + ":" + name) == raw,
             "allocation source bytes differ")
    rows = [r for r in document["resources"]["databases"] if r.get("stable_resource_id") == op["database_resource_id"]]
    need(len(rows) == 1, "allocation database must be uniquely registered")
    db = rows[0]
    name, user = db["database"], db["username"]
    need(re.fullmatch(r"[a-z][a-z0-9_]{0,31}", name) and user == name and user != "root",
         "allocation requires exact same-name dedicated schema/account")
    endpoint = db["upstream_endpoint"]
    policy = db["lease_policy"]
    physical = str(endpoint["host"]) + ":" + str(endpoint["port"])
    need(endpoint["endpoint_id"] == op["endpoint_id"] and db["lease_gate"] == op["lease_gate"]
         and db["host"] == endpoint["host"] and db["port"] == endpoint["port"]
         and policy["mode"] == "schema" and policy["physical_endpoint"] == physical
         and policy["physical_resource_id"] == db["physical_resource_id"]
         and db["fallback"] == "none" and db["grant_option"] is False
         and db["environments"] == ["development"] and db["version"] == op["version"],
         "allocation physical schema/endpoint identity differs")
    privileges = db["requested_schema_privileges"]
    need(isinstance(privileges, list) and len(privileges) == len(set(privileges))
         and set(privileges) == ALLOCATION_PRIVILEGES, "allocation exact privilege list differs")
    host = op["account_host"]
    need(db["account_host"] == host, "allocation account Host differs from registered policy")
    if host == "%":
        # project_authority authenticates the registration, not caller flags.
        # The exact development-only boundary above still applies; this is a
        # new, non-runtime target, never an override for an existing account.
        need(db.get("application_runtime") is False, "wildcard Host requires a registered local development allocation target")
    else:
        need(str(ipaddress.ip_address(host)) == host, "allocation requires an explicitly registered wildcard or literal IP")
    need(re.fullmatch(r"[0-9a-f-]{36}", op["server_uuid"]) and re.fullmatch(r"8\.4\.[0-9]+", op["version"]),
         "allocation server version/UUID invalid")
    transport = op["transport"]
    need(set(transport) == {"ssh_command", "ssh_alias", "docker_command", "container_name", "mysql_command"}
         and transport["ssh_command"] == "/usr/bin/ssh"
         and transport["docker_command"] == "/usr/local/bin/docker"
         and transport["mysql_command"] == "/usr/bin/mysql"
         and transport["container_name"] == db["physical_resource_id"]
         and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", transport["ssh_alias"]),
         "allocation fixed native transport differs")
    cred = op["credentials"]
    need(set(cred) == {"path", "sha256"} and project in canonical(cred["path"]).parents
         and db["credential_ref"].split(";", 1)[0] == cred["path"]
         and sha(regular(cred["path"], private=True)) == cred["sha256"], "allocation private input differs")
    root = canonical(op["receipt_root"])
    backup = canonical(str(project.parent / "backups/project/peanut-admin-code"))
    need(root.parent == backup and root.name == operation_id, "allocation receipt root differs")
    php = canonical(op["php_executable"])
    need(php.name == "php" and php.is_file() and os.access(php, os.X_OK), "allocation native PHP unavailable")
    if "retry_of" in op:
        allocation_retry(op)
    return op, db, common, registry_sha


def allocation_pair(op):
    pair = [allocation_authority(identity) for identity in op["paired_operations"]]
    need(op["stable_resource_id"] == op["paired_operations"][0], "allocate the pair through its first registered operation only")
    shared = ("owner", "thread", "tool_source", "lease_gate", "server_uuid", "version", "account_host",
              "transport", "php_executable", "max_seconds", "paired_operations")
    for item, database, common, digest in pair:
        need(all(item[key] == op[key] for key in shared) and common == pair[0][2] and digest == pair[0][3]
             and database["lease_policy"] == pair[0][1]["lease_policy"]
             and item.get("retry_of") == op.get("retry_of"), "allocation pair authority/physical service differs")
    for key in ("database_resource_id", "endpoint_id", "receipt_root"):
        need(len({item[key] for item, _, _, _ in pair}) == 2, "allocation pair targets are not distinct")
    need(len({database["database"] for _, database, _, _ in pair}) == 2, "allocation pair schemas are not distinct")
    return pair


def allocation_resources(op, db, registry_sha):
    return allocation_pair_resources(op, allocation_pair(op), registry_sha)


def allocation_pair_resources(op, pair, registry_sha):
    result = {"schema-allocation": {op["stable_resource_id"]},
              "schema-allocation-sha256": {sha(json.dumps([item for item, _, _, _ in pair], sort_keys=True).encode())},
              "registry-sha256": {registry_sha}, "gate": {op["lease_gate"]}, "worktree": {op["tool_source"]["root"]}}
    for item, database, _, digest in pair:
        need(digest == registry_sha, "allocation pair registry drift")
        schema = database["lease_policy"]["physical_endpoint"] + "/" + database["database"]
        scope = json.dumps(dict(schema=schema, operation="ddl", complete=True, tables=[]), separators=(",", ":"))
        for key, value in (("resource-id", database["stable_resource_id"]), ("endpoint", item["endpoint_id"]),
                           ("mysql-schema", schema), ("mysql-access", scope), ("database", database["database"]),
                           ("mysql-db", database["database"]), ("output-dir", item["receipt_root"]),
                           ("mysql-account", database["lease_policy"]["physical_endpoint"] + "/" + database["username"] + "@" + item["account_host"])):
            result.setdefault(key, set()).add(value)
    return result


def allocation_validate(metadata, resources):
    need(len(resources.get("schema-allocation", [])) == 1, "allocation binding missing")
    op, db, common, digest = allocation_authority(next(iter(resources["schema-allocation"])))
    need(resources == allocation_resources(op, db, digest)
         and metadata["owner"] == metadata["thread"] == op["owner"] and metadata["parent_lease"] == ""
         and metadata["candidate"] == op["tool_source"]["commit"]
         and metadata["candidate_repository"] == metadata["worktree"] == str(ROOT)
         and metadata["gate"] == op["lease_gate"], "allocation lease scope/source/actor differs")
    if "retry_of" in op:
        need(metadata["lease"] != allocation_retry(op)["lease"]
             and not (canonical(op["receipt_root"]) / (metadata["lease"] + "-terminal.json")).exists(),
             "retry cannot reuse a retired lease identity")
    return op, db, common


def allocation_lease(lease_id, owner, live=True):
    need(isinstance(lease_id, str) and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", lease_id), "invalid allocation lease")
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    directory = common / "peanut-admin-resource-leases/leases" / lease_id
    metadata = dict(table(directory / "metadata.tsv", 2))
    resources = resource_map(table(directory / "resources.tsv", 3))
    op, db, expected = allocation_validate(metadata, resources)
    need(expected == common and metadata["lease"] == lease_id and metadata["status"] == "ACTIVE"
         and metadata["owner"] == owner and int(metadata["created_at"]) <= time.time()
         and (not live or time.time() + op["max_seconds"] + 30 < int(metadata["expires_at"])),
         "allocation lease owner/time/storage differs")
    # Allocation holds an ordinary exclusive schema lock, plus every exact account/output lock.
    shared = {"resource-id", "endpoint", "gate", "mysql-db", "database", "mysql-access", "registry-sha256"}
    for key, values in resources.items():
        if key in shared:
            continue
        for value in values:
            lock = common / "peanut-admin-resource-leases/resources" / sha((key + "\t" + value).encode())
            for field, wanted in (("lease", lease_id), ("type", key), ("value", value)):
                need(regular(lock / field).decode().strip() == wanted, "allocation physical lock differs")
    return op, db, directory


def allocation_write(path, value, create=False):
    if create:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "w") as stream:
            stream.write(json.dumps(value, sort_keys=True) + "\n"); stream.flush(); os.fsync(stream.fileno())
    else:
        regular(path, private=True)
        save(path, value)
    fd = os.open(path.parent, os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def allocation_retry(op):
    binding = op["retry_of"]
    need(isinstance(binding, dict) and set(binding) == {"path", "sha256"}, "allocation retry needs original terminal receipt")
    path = canonical(binding["path"])
    raw = regular(path, private=True)
    receipt = json_unique(raw)
    need(sha(raw) == binding["sha256"] and receipt["protocol"] == "peanut.schema-allocation-zero-mutation-terminal.v1"
         and receipt["verdict"] == "ZERO_MUTATION_RECONCILED" and receipt["release_intent"] == "ORIGINAL_LOCKS_VERIFIED"
         and receipt["original_journal"]["mutation_dispatched"] is False
         and receipt["original_journal"]["status"] == "UNKNOWN", "allocation predecessor is not zero-mutation terminal")
    old = [item for item in receipt["original_operations"] if item["stable_resource_id"] == op["stable_resource_id"]]
    need(len(old) == 1 and {k:v for k,v in old[0].items() if k not in ("tool_source", "retry_of")}
         == {k:v for k,v in op.items() if k not in ("tool_source", "retry_of")}, "allocation retry changed original task/targets")
    common = canonical(op["tool_source"]["common_dir"])
    _, project, _, _ = project_authority()
    git(project, "merge-base", "--is-ancestor", receipt["review_commit"], "HEAD")
    review_path = project / "resources/operations/schema-allocation" / (receipt["lease"] + "-terminal-review.json")
    review_raw = git(project, "show", receipt["review_commit"] + ":" + str(review_path.relative_to(project)))
    review = json_unique(review_raw)
    need(receipt["review_path"] == str(review_path) and sha(review_raw) == receipt["review_sha256"]
         and review["protocol"] == "peanut.schema-allocation-zero-mutation-review.v1"
         and review["verdict"] == "RELEASE_ZERO_MUTATION" and review["owner"] == op["owner"]
         and review["lease"] == receipt["lease"] and review["reviewer_thread"] not in (None, "", op["owner"])
         and review["local_process_terminal"] is review["remote_process_terminal"] is review["zero_mutation_source_review"] is True
         and review["reconciler_source"] == receipt["producer"]
         and review["journal_sha256"] == receipt["journal_sha256"]
         and review["metadata_sha256"] == receipt["metadata_sha256"]
         and review["resources_sha256"] == receipt["resources_sha256"], "allocation terminal producer/review provenance differs")
    producer = receipt["producer"]
    need(producer == op["tool_source"], "allocation retry must use the independently reviewed terminal producer source")
    for name, digest in producer["files"].items():
        need(sha(git(common.parent, "show", producer["commit"] + ":" + name)) == digest,
             "allocation terminal producer source differs")
    git(project, "merge-base", "--is-ancestor", receipt["registry_commit"], receipt["review_commit"])
    registry_raw = git(project, "show", receipt["registry_commit"] + ":resources/project-resources.json")
    need(review["registry_commit"] == receipt["registry_commit"] and sha(registry_raw) == receipt["original_registry_sha256"],
         "allocation terminal original registry differs")
    original = json_unique(registry_raw)
    original_ops = [next(item for item in original["resources"]["schema_allocations"] if item["stable_resource_id"] == identity)
                    for identity in op["paired_operations"]]
    need(original_ops == receipt["original_operations"], "allocation terminal original operations changed")
    pair = [(item, next(db for db in original["resources"]["databases"] if db["stable_resource_id"] == item["database_resource_id"]),
             common, receipt["original_registry_sha256"]) for item in original_ops]
    rows = receipt["original_resources"]
    need(sha(receipt["metadata_bytes"].encode()) == receipt["metadata_sha256"]
         and sha(receipt["resources_bytes"].encode()) == receipt["resources_sha256"]
         and rows == [line.split("\t") for line in receipt["resources_bytes"].splitlines()]
         and resource_map(rows) == allocation_pair_resources(original_ops[0], pair, receipt["original_registry_sha256"]),
         "allocation terminal original scope is incomplete")
    need(not (common / "peanut-admin-resource-leases/leases" / receipt["lease"]).exists(),
         "original allocation lease must be normally released before retry admission")
    for _, kind, value in receipt["original_resources"]:
        lock = common / "peanut-admin-resource-leases/resources" / sha((kind + "\t" + value).encode())
        if lock.exists():
            need(regular(lock / "lease").decode().strip() != receipt["lease"], "original allocation lock still retained")
    journal_raw = regular(canonical(receipt["journal_path"]), private=True)
    need(path == canonical(receipt["journal_path"]).parent / (receipt["lease"] + "-terminal.json")
         and sha(journal_raw) == receipt["journal_sha256"] and json_unique(journal_raw) == receipt["original_journal"]
         and receipt["original_journal"]["paired_operations"] == op["paired_operations"]
         and receipt["original_journal"]["owner"] == op["owner"]
         and receipt["original_journal"]["lease"] == receipt["lease"]
         and set(receipt["original_journal"]["targets"]) == set(op["paired_operations"])
         and all(value == dict(status="PREFLIGHT", mutation_dispatched=False) for value in receipt["original_journal"]["targets"].values()),
         "original allocation failure journal changed")
    return receipt


def allocation_journal(op, lease_id):
    if "retry_of" not in op:
        return canonical(op["receipt_root"]) / ALLOCATION_STATE
    predecessor = allocation_retry(op)
    need(lease_id != predecessor["lease"], "allocation retry must use a new normally admitted lease")
    return canonical(op["receipt_root"]) / ("schema-allocation-" + lease_id + ".json")


def allocation_grant_privileges(grants, item, database, partial_revokes):
    name, user, host = database["database"], database["username"], item["account_host"]
    scope = name if partial_revokes else name.replace("_", "\\_")
    actual = set()
    for grant in grants:
        match = re.fullmatch(r"GRANT (.+) ON (.+) TO (.+)", grant)
        need(match is not None and "WITH GRANT OPTION" not in grant
             and match[3] == "`" + user + "`@`" + host + "`", "allocation grant afterimage differs")
        if match[1] == "USAGE" and match[2] == "*.*":
            continue
        need(match[2] in ("`" + scope + "`.*", "`" + name + "`.*"), "allocation grants exceed exact schema")
        actual.update(match[1].split(", "))
    need(actual == ALLOCATION_PRIVILEGES, "allocation effective direct privileges differ")
    return actual


def allocation_completed_afterimages(proof, journal, pair, observation, evidence):
    """Adopt only proven completed writes; never issue SQL or a retry permit."""
    op = pair[0][0]
    ids = op["paired_operations"]
    precheck = journal["pair_precheck"]
    need(journal["mutation_dispatched"] is True and journal["current_operation"] in ids
         and journal["last_native"]["exit_code"] == 0 and journal["last_native"]["mysql_error_codes"] == []
         and precheck["lease"] == journal["lease"] and precheck["registry_sha256"] == journal["registry_sha256"]
         and precheck["server_uuid"] == op["server_uuid"] and precheck["version"] == op["version"]
         and precheck["targets"] == {identity: dict(schema_rows=0, accounts=0) for identity in ids}
         and set(journal["targets"]) == set(ids)
         and all(target["mutation_dispatched"] is True and target["status"] in ("COMPLETED", "UNKNOWN")
                 and precheck["observed_at"] <= target["mutation_started_at"] <= journal["failed_at"]
                 for target in journal["targets"].values()), "allocation is not a dispatched pair with complete preimage")
    allocation_admin_authority(journal["admin_authority"], op, [database for _, database, _, _ in pair])
    need(set(proof["completion_evidence"]) == set(ids), "allocation completion evidence misses a target")
    adopted = {}
    for item, database, _, _ in pair:
        identity = item["stable_resource_id"]
        target = journal["targets"][identity]
        binding = proof["completion_evidence"][identity]
        if binding == {"source": "original-journal"}:
            need(target["status"] == "COMPLETED" and target["completed_at"] <= journal["failed_at"],
                 "allocation original target was not completed")
            after, privileges, login = target["after"], target["exact_privileges"], target["login"]
        else:
            need(set(binding) == {"path", "sha256"}, "allocation external completion binding differs")
            observed = evidence(binding)
            need(observed["lease"] == journal["lease"]
                 and observed["original_journal_sha256"] == proof["journal_sha256"]
                 and observed["native_verify_exit"] == observed["read_only_root_exit"] == observed["ordinary_ST_login_exit"] == 0
                 and observed["journal_unchanged"] is True and observed["no_SQL_DDL_or_account_changes"] is True
                 and journal["failed_at"] <= datetime.fromisoformat(observed["at_utc"]).timestamp() <= proof["reviewed_at"],
                 "allocation independent completion identity/time differs")
            after, privileges, login = observed["after"], observed["exact_privileges"], observed["ordinary_login"]
            # Reuse the allocator's actual direct-grant contract, not an evidence boolean.
            need(allocation_grant_privileges(observed["grants"], item, database, after["partial_revokes"]) == set(privileges),
                 "allocation independent direct grants differ")
        need(after["uuid"] == op["server_uuid"] and after["version"] == op["version"]
             and after["principal"] == "root@localhost" and after["mandatory_roles"] in ("", "NONE")
             and after["partial_revokes"] == 0
             and type(after["schema_rows"]) is int and after["schema_rows"] == 1
             and type(after["accounts"]) is int and after["accounts"] == 1
             and all(type(after[key]) is int and after[key] == 0 for key in ("objects", "triggers", "routines", "events"))
             and len(privileges) == len(ALLOCATION_PRIVILEGES) and set(privileges) == ALLOCATION_PRIVILEGES,
             "allocation successful empty-schema/privilege afterimage not proved")
        client = login.get("client_identity")
        need(isinstance(client, str) and client.startswith(database["username"] + "@")
             and len(client) > len(database["username"]) + 1
             and login == dict(database=database["database"], principal=database["username"] + "@" + item["account_host"],
                               client_identity=client, uuid=op["server_uuid"], version=op["version"]),
             "allocation completed ordinary login identity differs")
        adopted[identity] = dict(status="COMPLETED", after=after, exact_privileges=privileges, login=login,
                                 evidence=binding)
    need(observation["original_journal_sha256"] == proof["journal_sha256"]
         and observation["original_journal_unchanged"] is True, "allocation completion observation changed original journal")
    return adopted


def allocation_terminal(lease_id, owner, *, release=False):
    """Original-scope retirement only. Never SQL, renewal, a permit, or a retry."""
    need(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", lease_id) is not None
         and owner == os.environ.get("CODEX_THREAD_ID"), "allocation terminal requires the real original owner")
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    base = common / "peanut-admin-resource-leases"
    need(regular(base / "mutex/pid").decode().strip() == str(os.getppid()), "allocation terminal requires native mutex")
    directory = base / "leases" / lease_id
    _, project, _, _ = project_authority()
    review_path = project / "resources/operations/schema-allocation" / (lease_id + "-terminal-review.json")
    review_raw = regular(review_path)
    need(git(project, "show", "HEAD:" + str(review_path.relative_to(project))) == review_raw,
         "allocation terminal review must be versioned by the lawful Project writer")
    proof = json_unique(review_raw)
    completed = proof.get("protocol") == ALLOCATION_PROTOCOL and proof.get("verdict") == "ADOPT_COMPLETED_PAIR"
    terminal_protocol = ALLOCATION_PROTOCOL if completed else "peanut.schema-allocation-zero-mutation-terminal.v1"
    terminal_verdict = "PAIR_COMPLETION_ADOPTED" if completed else "ZERO_MUTATION_RECONCILED"
    journal_path = canonical(proof["journal_path"])
    terminal_path = journal_path.parent / (lease_id + "-terminal.json")
    published = json_unique(regular(terminal_path, private=True)) if terminal_path.exists() else None
    if published:
        git(project, "merge-base", "--is-ancestor", published["review_commit"], "HEAD")
        need(published["protocol"] == terminal_protocol and published["verdict"] == terminal_verdict
             and published["release_intent"] == "ORIGINAL_LOCKS_VERIFIED"
             and git(project, "show", published["review_commit"] + ":" + str(review_path.relative_to(project))) == review_raw,
             "allocation published release intent review provenance differs")
    for child in (base / "leases").glob("*/metadata.tsv"):
        if child.parent.name == lease_id:
            continue
        child_metadata = dict(table(child, 2))
        need(child_metadata.get("parent_lease") != lease_id, "allocation still has an original retained child")
    # A fully published release intent preserves original proof bytes, so a
    # crash after normal lock/metadata cleanup never requires recreating them.
    metadata_raw = regular(directory / "metadata.tsv") if (directory / "metadata.tsv").exists() else (published["metadata_bytes"].encode() if published else b"")
    resources_raw = regular(directory / "resources.tsv") if (directory / "resources.tsv").exists() else (published["resources_bytes"].encode() if published else b"")
    metadata_rows = [line.split("\t") for line in metadata_raw.decode().splitlines()]
    rows = [line.split("\t") for line in resources_raw.decode().splitlines()]
    need(len(metadata_rows) == 11 and all(len(row) == 2 for row in metadata_rows)
         and len({row[0] for row in metadata_rows}) == 11 and rows and all(len(row) == 3 for row in rows),
         "allocation original proof bytes missing or malformed")
    metadata, resources = dict(metadata_rows), resource_map(rows)
    need((completed or (proof["protocol"] == "peanut.schema-allocation-zero-mutation-review.v1"
                        and proof["verdict"] == "RELEASE_ZERO_MUTATION")) and proof["lease"] == lease_id
         and proof["owner"] == owner and proof["reviewer_thread"] not in (None, "", owner)
         and proof["local_process_terminal"] is True and proof["remote_process_terminal"] is True
         and proof["completed_afterimages_source_review" if completed else "zero_mutation_source_review"] is True
         and proof["metadata_sha256"] == sha(metadata_raw) and proof["resources_sha256"] == sha(resources_raw),
         "allocation independent terminal review differs from original proof")
    commit = proof["registry_commit"]
    need(re.fullmatch(r"[0-9a-f]{40}", commit) is not None, "allocation original registry commit invalid")
    git(project, "merge-base", "--is-ancestor", commit, "HEAD")
    registry_raw = git(project, "show", commit + ":resources/project-resources.json")
    registry_sha = sha(registry_raw)
    need(resources.get("registry-sha256") == {registry_sha}
         and git(project, "show", commit + ":repos.yaml") == git(project, "show", "HEAD:repos.yaml"),
         "allocation original registry identity/mapping differs")
    original = json_unique(registry_raw)
    need(original.get("schema_version") == 1 and original.get("project_id") == "peanut-admin"
         and original.get("authority", {}).get("role") == "maintainer", "original allocation registry authority differs")
    operation_id = proof["operation"]
    operations = [o for o in original["resources"]["schema_allocations"] if o["stable_resource_id"] == operation_id]
    need(len(operations) == 1, "original allocation operation missing")
    op = operations[0]
    ids = op["paired_operations"]
    need(len(ids) == 2 and ids == sorted(set(ids)) and operation_id == ids[0], "original allocation pair differs")
    pair = []
    for identity in ids:
        ops = [o for o in original["resources"]["schema_allocations"] if o["stable_resource_id"] == identity]
        need(len(ops) == 1 and ops[0]["paired_operations"] == ids
             and ops[0]["tool_source"] == op["tool_source"], "original allocation pair source differs")
        databases = [d for d in original["resources"]["databases"] if d["stable_resource_id"] == ops[0]["database_resource_id"]]
        need(len(databases) == 1, "original allocation database missing")
        pair.append((ops[0], databases[0], common, registry_sha))
    need(resources == allocation_pair_resources(op, pair, registry_sha)
         and metadata["owner"] == metadata["thread"] == op["owner"] == op["thread"] == owner
         and metadata["lease"] == lease_id and metadata["status"] == "ACTIVE" and metadata["parent_lease"] == ""
         and metadata["gate"] == op["lease_gate"] and int(metadata["created_at"]) < int(metadata["expires_at"]),
         "allocation original identity/resource closure differs")
    for source, root in ((op["tool_source"], canonical(op["tool_source"]["root"])), (proof["reconciler_source"], ROOT)):
        need(source["common_dir"] == str(common) and source["root"] == str(root)
             and git(root, "rev-parse", "HEAD").decode().strip() == source["commit"]
             and not git(root, "status", "--porcelain=v1", "--untracked-files=all")
             and set(source["files"]) == {"scripts/project-resource-lease", "scripts/project-resource-application.py", "scripts/project-resource-registry"},
             "allocation terminal fixed source differs")
        for name, digest in source["files"].items():
            raw = regular(root / name)
            need(sha(raw) == digest and git(root, "show", source["commit"] + ":" + name) == raw,
                 "allocation terminal fixed source bytes differ")
    need(metadata["candidate"] == op["tool_source"]["commit"]
         and metadata["candidate_repository"] == metadata["worktree"] == op["tool_source"]["root"], "original allocation candidate differs")
    shared = {"resource-id", "endpoint", "gate", "mysql-db", "database", "mysql-access", "registry-sha256"}
    expected_locks = {digest:(kind, value) for digest, kind, value in rows if kind not in shared}
    actual_locks = set()
    for lock in (base / "resources").iterdir():
        if not (lock / "lease").exists() or regular(lock / "lease").decode().strip() != lease_id:
            continue
        kind, value = (regular(lock / field).decode().strip() for field in ("type", "value"))
        need(lock.name == sha((kind + "\t" + value).encode()), "allocation terminal lock digest differs")
        actual_locks.add((kind, value))
    need(actual_locks <= set(expected_locks.values()) and (published is not None or actual_locks == set(expected_locks.values())),
         "allocation original holder has missing/extra locks without a release intent")
    lock_directories = published["lock_directories"] if published else {}
    if published:
        need(set(lock_directories) == set(expected_locks), "allocation release intent lock set differs")
    remaining_locks = []
    for digest, (kind, value) in expected_locks.items():
        lock = base / "resources" / digest
        if not lock.exists():
            need(published is not None, "original allocation lock absent before release intent")
            continue
        # After the lease directory is gone, newer owners may normally acquire
        # these keys. Replaying this completed reconciliation never touches them.
        if not directory.exists() and (lock / "lease").exists() and regular(lock / "lease").decode().strip() != lease_id:
            continue
        info = lock.lstat()
        need(stat.S_ISDIR(info.st_mode) and lock.resolve() == lock and not lock.is_symlink(), "original allocation lock path differs")
        identity = dict(device=info.st_dev, inode=info.st_ino)
        if published:
            need(lock_directories[digest] == identity, "original allocation lock directory replaced")
        else:
            lock_directories[digest] = identity
        need({p.name for p in lock.iterdir()} <= {"lease", "type", "value"}, "unexpected original allocation lock content")
        for field, expected in (("lease", lease_id), ("type", kind), ("value", value)):
            if (lock / field).exists():
                need(regular(lock / field).decode().strip() == expected, "original allocation holder differs")
            else:
                need(published is not None, "original allocation lock proof incomplete before release intent")
        remaining_locks.append(lock)
    # Original names/receipt roots remain fixed; retries use their own producer-named journal.
    expected_journal = canonical(op["receipt_root"]) / ("schema-allocation-" + lease_id + ".json" if "retry_of" in op else ALLOCATION_STATE)
    need(journal_path == expected_journal, "allocation original journal path differs")
    journal_raw = regular(journal_path, private=True)
    journal = json_unique(journal_raw)
    marker_path = directory / ALLOCATION_STATE
    marker = dict(journal=str(journal_path), owner=owner)
    if marker_path.exists():
        marker_raw = regular(marker_path, private=True)
        need(sha(marker_raw) == proof["marker_sha256"] and json_unique(marker_raw) == marker, "allocation original marker differs")
    else:
        need(published is not None, "allocation original marker absent without terminal receipt")
    need(sha(journal_raw) == proof["journal_sha256"] and journal["protocol"] == ALLOCATION_PROTOCOL
         and journal["owner"] == owner and journal["lease"] == lease_id and journal["operation"] == operation_id
         and journal["registry_sha256"] == registry_sha and journal["paired_operations"] == ids
         and journal["status"] == "UNKNOWN"
         and (completed or (journal["mutation_dispatched"] is False
                            and "pair_precheck" not in journal and "current_operation" not in journal
                            and set(journal["targets"]) == set(ids)
                            and all(value == dict(status="PREFLIGHT", mutation_dispatched=False)
                                    for value in journal["targets"].values())))
         and isinstance(journal["process_birth"], str) and journal["process_birth"]
         and not original_process_alive(journal["pid"], journal["process_birth"]),
         "allocation is not the exact terminated original failure")
    def evidence(binding):
        raw = regular(canonical(binding["path"]))
        need(sha(raw) == binding["sha256"], "allocation terminal evidence bytes changed")
        return json_unique(raw)
    observation = evidence(proof["readonly_evidence"])
    execution = evidence(proof["execution_evidence"])
    counts = observation["actual" if completed else "identity_and_target_counts"]
    need(observation["owner"] == execution["owner"] == owner and observation["lease"] == execution["lease"] == lease_id
         and observation["native_verify_exit"] == observation["query_exit"] == 0
         and counts["uuid"] == op["server_uuid"] and counts["version"] == op["version"]
         and counts["principal"] == "root@localhost" and counts["mandatory_roles"] in ("", "NONE")
         and counts["partial_revokes"] in (0, 1)
         and datetime.fromisoformat(observation["at_utc" if completed else "observed_at_utc"]).timestamp() >= journal["failed_at"]
         and proof["reviewed_at"] >= datetime.fromisoformat(observation["at_utc" if completed else "observed_at_utc"]).timestamp()
         and proof["reviewed_at"] <= time.time()
         and execution["journal_sha256"] == sha(journal_raw) and execution["journal_status"] == "UNKNOWN"
         and execution["mutation_dispatched"] is completed and execution["tool_source"] == metadata["candidate"]
         and execution["authority_sha256"] == registry_sha
         and datetime.fromisoformat(execution["finished_at_utc"]).timestamp() >= journal["failed_at"],
         "allocation independent read/terminal evidence differs")
    need(set(proof["readonly_counts"]) == set(ids), "allocation absence evidence misses a target")
    for item, database, _, _ in pair:
        fields = proof["readonly_counts"][item["stable_resource_id"]]
        need(set(fields) == {"database", "username", "schema_count_key", "account_count_key"}
             and fields["database"] == database["database"] and fields["username"] == database["username"]
             and type(counts[fields["schema_count_key"]]) is int and counts[fields["schema_count_key"]] == int(completed)
             and type(counts[fields["account_count_key"]]) is int and counts[fields["account_count_key"]] == int(completed),
             "allocation exact schema/account counts not proved")
    adopted = allocation_completed_afterimages(proof, journal, pair, observation, evidence) if completed else None
    receipt = dict(protocol=terminal_protocol, verdict=terminal_verdict,
                   release_intent="ORIGINAL_LOCKS_VERIFIED", lock_directories=lock_directories,
                   lease=lease_id, owner=owner, journal_path=str(journal_path), journal_sha256=sha(journal_raw),
                   original_journal=journal, original_operations=[item for item, _, _, _ in pair],
                   original_resources=rows, metadata_sha256=sha(metadata_raw), resources_sha256=sha(resources_raw),
                   metadata_bytes=metadata_raw.decode(), resources_bytes=resources_raw.decode(),
                   registry_commit=commit, original_registry_sha256=registry_sha,
                   review_path=str(review_path), review_sha256=sha(review_raw),
                   review_commit=published["review_commit"] if published else git(project, "rev-parse", "HEAD").decode().strip(),
                   producer=proof["reconciler_source"])
    if completed:
        receipt["adopted_targets"] = adopted
    if published:
        need(published == receipt, "allocation terminal receipt changed; reconcile original evidence")
    else:
        # Under the already verified native mutex and original output lock,
        # write/fsync a same-directory temporary, then atomically publish once.
        fd, temporary = tempfile.mkstemp(prefix="." + terminal_path.name + "-", dir=terminal_path.parent)
        try:
            with os.fdopen(fd, "w") as stream:
                stream.write(json.dumps(receipt, sort_keys=True) + "\n"); stream.flush(); os.fsync(stream.fileno())
            need(not terminal_path.exists() and not terminal_path.is_symlink(), "allocation terminal output already exists")
            os.rename(temporary, terminal_path)
            fd = os.open(terminal_path.parent, os.O_RDONLY)
            try:
                os.fsync(fd)
            finally:
                os.close(fd)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
    if release:
        # Only the exact original inodes recorded by the durable intent may be
        # finished after interruption; never remove a replacement/new holder.
        for lock in remaining_locks:
            for field in ("lease", "type", "value"):
                if (lock / field).exists():
                    (lock / field).unlink()
            lock.rmdir()
        fd = os.open(base / "resources", os.O_RDONLY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    if release and marker_path.exists():
        marker_path.unlink()
        fd = os.open(directory, os.O_RDONLY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    if release and directory.exists():
        for name in ("metadata.tsv", "resources.tsv"):
            if (directory / name).exists():
                (directory / name).unlink()
        directory.rmdir()
        fd = os.open(directory.parent, os.O_RDONLY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    return terminal_path


def allocation_execute(lease_id, owner):
    op, db, directory = allocation_lease(lease_id, owner)
    pair = allocation_pair(op)
    verify_native(lease_id)
    root = canonical(op["receipt_root"])
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    need(root.stat().st_uid == os.getuid() and stat.S_IMODE(root.stat().st_mode) == 0o700, "allocation receipt custody differs")
    journal = allocation_journal(op, lease_id)
    marker = directory / ALLOCATION_STATE
    # The operation-wide O_EXCL journal survives lease release; a new lease cannot replay it.
    for item, _, _, _ in pair[1:]:
        other = canonical(item["receipt_root"]) / ALLOCATION_STATE
        need(not other.exists() and not other.is_symlink(), "paired target has an existing journal; reconcile original operation")
    value = dict(protocol=ALLOCATION_PROTOCOL, operation=op["stable_resource_id"], lease=lease_id,
                 owner=owner, status="PREFLIGHT", started_at=time.time(), mutation_dispatched=False,
                 registry_sha256=pair[0][3], paired_operations=op["paired_operations"],
                 targets={item["stable_resource_id"]: dict(status="PREFLIGHT", mutation_dispatched=False)
                          for item, _, _, _ in pair},
                 pid=os.getpid(), process_birth=process_birth(os.getpid()))
    if "retry_of" in op:
        value["retry_of"] = op["retry_of"]
    allocation_write(journal, value, create=True)
    allocation_write(marker, {"journal": str(journal), "owner": owner}, create=True)
    deadline = time.monotonic() + op["max_seconds"]
    def run(argv, data):
        allocation_lease(lease_id, owner)
        verify_native(lease_id)
        seconds = int(deadline - time.monotonic())
        need(seconds > 0, "allocation deadline expired")
        env = {k: v for k, v in os.environ.items() if k in ("HOME", "USER", "LOGNAME", "LANG", "LC_ALL", "SSH_AUTH_SOCK")}
        env["PATH"] = "/usr/bin:/bin:/usr/sbin:/sbin"
        p = subprocess.run(argv, input=data, capture_output=True, timeout=seconds, env=env, cwd=ROOT)
        value["last_native"] = dict(exit_code=p.returncode, stdout_sha256=sha(p.stdout), stderr_sha256=sha(p.stderr),
                                    mysql_error_codes=[int(code) for code in re.findall(rb"ERROR ([0-9]{1,5}) ", p.stderr)])
        allocation_write(journal, value)
        need(p.returncode == 0 and len(p.stdout) <= 65536, "allocation native process failed; inspect private journal")
        return p.stdout
    def mysql(sql):
        t = op["transport"]
        inside = 'MYSQL_PWD=${MYSQL_ROOT_PASSWORD:?}; export MYSQL_PWD; exec ' + shlex.join([
            t["mysql_command"], "--no-defaults", "--no-login-paths", "--default-character-set=utf8mb4",
            "--connect-timeout=5", "--batch", "--raw", "--skip-column-names", "-uroot"])
        remote = shlex.join([t["docker_command"], "exec", "-i", t["container_name"], "/bin/sh", "-c", inside])
        return run([t["ssh_command"], "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes", "-o", "ConnectTimeout=5",
                    "-o", "ServerAliveInterval=5", "-o", "ServerAliveCountMax=3", t["ssh_alias"], remote], sql.encode())
    identity = ("'uuid',@@server_uuid,'version',VERSION(),'principal',CURRENT_USER(),"
                "'partial_revokes',@@GLOBAL.partial_revokes,'mandatory_roles',@@GLOBAL.mandatory_roles")
    def inventory_for(database):
        return ("'schema_rows',(SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" + database["database"] + "'),"
                "'accounts',(SELECT COUNT(*) FROM mysql.user WHERE User='" + database["username"] + "')")

    def allocate_target(item, database, credentials):
        name, user, host = database["database"], database["username"], item["account_host"]
        target = value["targets"][item["stable_resource_id"]]
        inventory = inventory_for(database)
        before = json_unique(mysql("SELECT JSON_OBJECT(" + identity + "," + inventory + ");\n"))
        need(before["uuid"] == op["server_uuid"] and before["version"] == op["version"]
             and before["principal"] == "root@localhost" and before["partial_revokes"] in (0, 1)
             and before["mandatory_roles"] in ("", "NONE"), "allocation live server/principal differs")
        target["before"] = before
        if before["schema_rows"] != 0 or before["accounts"] != 0:
            target["status"] = "EXISTING_RECONCILIATION_REQUIRED"
            value["status"] = "UNKNOWN" if value["mutation_dispatched"] else "EXISTING_RECONCILIATION_REQUIRED"
            allocation_write(journal, value)
            return False
        scope = name if before["partial_revokes"] else name.replace("_", "\\_")
        sql = ("CREATE DATABASE `" + name + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
               "CREATE USER '" + user + "'@'" + host + "' IDENTIFIED BY '" + credentials["DB_PASS"] + "';\n"
               "GRANT " + ", ".join(database["requested_schema_privileges"]) + " ON `" + scope + "`.* TO '" + user + "'@'" + host + "';\n")
        # Each target retains its own durable UNKNOWN before dispatch. The pair
        # is ordered DDL, not a transaction or an automatic rollback promise.
        target.update(status="UNKNOWN", mutation_dispatched=True, mutation_started_at=time.time())
        value.update(status="UNKNOWN", mutation_dispatched=True, current_operation=item["stable_resource_id"])
        allocation_write(journal, value)
        mysql(sql)
        counts = ",".join("'" + label + "',(SELECT COUNT(*) FROM information_schema." + table_name + " WHERE " + column + "='" + name + "')"
                          for label, table_name, column in (("objects", "TABLES", "TABLE_SCHEMA"), ("triggers", "TRIGGERS", "TRIGGER_SCHEMA"),
                                                           ("routines", "ROUTINES", "ROUTINE_SCHEMA"), ("events", "EVENTS", "EVENT_SCHEMA")))
        after = json_unique(mysql("SELECT JSON_OBJECT(" + identity + "," + inventory + "," + counts + ");\n"))
        need(after["uuid"] == before["uuid"] and after["version"] == before["version"]
             and after["schema_rows"] == after["accounts"] == 1
             and all(after[k] == 0 for k in ("objects", "triggers", "routines", "events")), "allocation empty afterimage differs")
        grants = mysql("SHOW GRANTS FOR '" + user + "'@'" + host + "';\n").decode().splitlines()
        actual = allocation_grant_privileges(grants, item, database, after["partial_revokes"])
        php = r'''$c=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);try{$p=new PDO('mysql:host='.$c['DB_HOST'].';port='.$c['DB_PORT'].';dbname='.$c['DB_NAME'].';charset=utf8mb4',$c['DB_USER'],$c['DB_PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);echo $p->query("SELECT JSON_OBJECT('database',DATABASE(),'principal',CURRENT_USER(),'client_identity',USER(),'uuid',@@server_uuid,'version',VERSION())")->fetchColumn();}catch(Throwable $e){exit(1);}'''
        login = json_unique(run([op["php_executable"], "-r", php], json.dumps(credentials).encode()))
        client_identity = login.get("client_identity")
        need(isinstance(client_identity, str) and client_identity.startswith(user + "@")
             and len(client_identity) > len(user) + 1
             and login == dict(database=name, principal=user + "@" + host, client_identity=client_identity,
                               uuid=op["server_uuid"], version=op["version"]),
             "allocation ordinary account live identity differs")
        target.update(status="COMPLETED", after=after, login=login, exact_privileges=sorted(actual), completed_at=time.time())
        allocation_write(journal, value)
        return True

    try:
        inputs = []
        for item, database, _, _ in pair:
            credentials = {}
            for line in regular(item["credentials"]["path"], private=True).decode().splitlines():
                key, separator, text = line.partition("=")
                need(separator and key not in credentials, "allocation credential format differs")
                credentials[key] = text
            need(set(credentials) == {"DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASS"}
                 and credentials["DB_HOST"] == database["host"] and credentials["DB_PORT"] == str(database["port"])
                 and credentials["DB_NAME"] == database["database"] and credentials["DB_USER"] == database["username"]
                 and re.fullmatch(r"[A-Za-z0-9_-]{32,128}", credentials["DB_PASS"]), "allocation exact private connection input differs")
            inputs.append(credentials)
        # The normal lease already covers both exact schemas and both accounts.
        # Observe both pairs in one privileged read before the first DDL, and
        # retain that assertion while the very same pair lease remains held.
        inventory = ",".join("'" + item["stable_resource_id"] + "',JSON_OBJECT(" + inventory_for(database) + ")"
                             for item, database, _, _ in pair)
        before = json_unique(mysql("SELECT JSON_OBJECT(" + identity + ",'targets',JSON_OBJECT(" + inventory + "));\n"))
        need(before["uuid"] == op["server_uuid"] and before["version"] == op["version"]
             and before["principal"] == "root@localhost" and before["partial_revokes"] in (0, 1)
             and before["mandatory_roles"] in ("", "NONE"),
             "allocation live server/principal differs")
        columns = ",".join("'" + privilege + "'," + column for privilege, column in ALLOCATION_GLOBAL_COLUMNS.items())
        admin = json_unique(mysql("SELECT JSON_OBJECT(" + identity + ",'privileges',JSON_OBJECT(" + columns
                                 + "),'restrictions',JSON_EXTRACT(User_attributes,'$.Restrictions')) "
                                 "FROM mysql.user WHERE User='root' AND Host='localhost';\n"))
        allocation_admin_authority(admin, op, [database for _, database, _, _ in pair])
        value["admin_authority"] = admin
        value["before"] = before
        if any(before["targets"][item["stable_resource_id"]] != dict(schema_rows=0, accounts=0) for item, _, _, _ in pair):
            value["status"] = "EXISTING_RECONCILIATION_REQUIRED"
            allocation_write(journal, value)
            print(json.dumps({"status": value["status"], "receipt": str(journal), "mutation_dispatched": False}))
            return
        value["pair_precheck"] = dict(observed_at=time.time(), server_uuid=before["uuid"], version=before["version"],
                                      lease=lease_id, registry_sha256=pair[0][3], targets=before["targets"])
        allocation_write(journal, value)
        for (item, database, _, _), credentials in zip(pair, inputs):
            if not allocate_target(item, database, credentials):
                print(json.dumps({"status": value["status"], "receipt": str(journal), "mutation_dispatched": value["mutation_dispatched"]}))
                return
        value.update(status="COMPLETED", completed_at=time.time())
        allocation_write(journal, value)
        print(json.dumps({"status": "COMPLETED", "receipt": str(journal), "databases": [database["database"] for _, database, _, _ in pair], "ordinary_login_verified": True}))
    except BaseException:
        value.update(status="UNKNOWN", failed_at=time.time())
        allocation_write(journal, value)
        raise RuntimeError("allocation UNKNOWN; retain original journal/lease; no automatic retry") from None


def allocation_release(lease_id, owner):
    # Terminal-only cleanup; no SQL, registry rebind, retry or UNKNOWN override.
    need(isinstance(lease_id, str) and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", lease_id), "invalid allocation release lease")
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    directory = common / "peanut-admin-resource-leases/leases" / lease_id
    if not (directory / "metadata.tsv").exists():
        allocation_terminal(lease_id, owner, release=True)
        return
    metadata = dict(table(directory / "metadata.tsv", 2))
    marker = directory / ALLOCATION_STATE
    if metadata["candidate_repository"] != str(ROOT):
        allocation_terminal(lease_id, owner, release=True)
        return
    if marker.exists():
        reference = json_unique(regular(marker, private=True))
        if (canonical(reference["journal"]).parent / (lease_id + "-terminal.json")).exists():
            allocation_terminal(lease_id, owner, release=True)
            return
    else:
        project = project_authority()[1]
        review = project / "resources/operations/schema-allocation" / (lease_id + "-terminal-review.json")
        if review.exists():
            reference = json_unique(regular(review))
            if (canonical(reference["journal_path"]).parent / (lease_id + "-terminal.json")).exists():
                allocation_terminal(lease_id, owner, release=True)
                return
    op, _, directory = allocation_lease(lease_id, owner, live=False)
    marker = directory / ALLOCATION_STATE
    if not marker.exists():
        return
    reference = json_unique(regular(marker, private=True))
    need(reference["journal"] == str(allocation_journal(op, lease_id)), "allocation journal path differs")
    journal = json_unique(regular(canonical(reference["journal"]), private=True))
    need(reference["owner"] == journal["owner"] == owner and journal["lease"] == lease_id
         and journal["operation"] == op["stable_resource_id"]
         and not original_process_alive(journal["pid"], journal.get("process_birth"))
         and journal["status"] in ("COMPLETED", "EXISTING_RECONCILIATION_REQUIRED"), "allocation is not safely terminal")
    need(journal["paired_operations"] == op["paired_operations"]
         and set(journal["targets"]) == set(op["paired_operations"]), "allocation terminal pair differs")
    if journal["status"] == "COMPLETED":
        need(journal["mutation_dispatched"] is True
             and all(target["status"] == "COMPLETED" and target["mutation_dispatched"] is True
                     for target in journal["targets"].values()), "allocation target is not complete")
    else:
        need(journal["mutation_dispatched"] is False
             and all(target["mutation_dispatched"] is False for target in journal["targets"].values()),
             "partial allocation retains its original pair boundary")
    marker.unlink()  # The durable operation-wide journal remains; only the lease pointer is retired.


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("allocation-resolve", "allocation-execute", "allocation-release", "allocation-reconcile", "resolve", "verify", "release-check", "execute", "consume", "recovery-admit", "recovery-info", "source-window-enter", "source-window-close", "retained-reserve", "retained-verify", "retained-renew", "parent-verify"))
    parser.add_argument("--operation")
    parser.add_argument("--lease")
    parser.add_argument("--owner")
    parser.add_argument("--phase", choices=("protect", "install", "maintain"))
    parser.add_argument("--step", choices=("plan", "capture", "rehearse", "rebind", "apply", "verify", "restore", "open"))
    parser.add_argument("--metadata")
    parser.add_argument("--resources")
    parser.add_argument("--parent-lease")
    parser.add_argument("--candidate")
    parser.add_argument("--candidate-repository")
    parser.add_argument("--thread")
    parser.add_argument("--expires", type=int)
    for key in ("proof", "application-root", "registry", "resource-id", "endpoint-id"):
        parser.add_argument("--" + key)
    args = parser.parse_args()
    if args.action.startswith("allocation-"):
        allowed = {"action", "owner", "operation"} if args.action == "allocation-resolve" else {"action", "owner", "lease"}
        need(all(key in allowed or value is None for key, value in vars(args).items()), "unsupported allocation arguments")
        if args.action == "allocation-resolve":
            op, db, _, digest = allocation_authority(args.operation)
            need(args.owner == op["owner"], "allocation resolution owner differs")
            print(json.dumps({k: sorted(v) for k, v in allocation_resources(op, db, digest).items()}))
        elif args.action == "allocation-execute":
            allocation_execute(args.lease, args.owner)
        elif args.action == "allocation-reconcile":
            path = allocation_terminal(args.lease, args.owner, release=True)
            print(json.dumps({"verdict": json_unique(regular(path, private=True))["verdict"], "receipt": str(path)}))
        else:
            allocation_release(args.lease, args.owner)
        return
    if args.action in ("retained-reserve", "retained-verify", "retained-renew", "parent-verify"):
        need(args.step is None or (args.action in ("retained-verify", "parent-verify")
                                  and args.step in ("restore", "open")), "retained inspection step invalid")
        if args.step:
            operation, directory, _ = lease(args.lease, recovery_step=args.step)
            common = directory.parent.parent.parent
            need(operation["stable_resource_id"] == args.operation and live_state(directory).get("recovery"),
                 "retained inspection requires exact admitted original recovery")
        else:
            operation, _, common, _ = authority(args.operation)
        need(args.owner == operation["owner"], "retained operation owner mismatch")
        parent = retained_parent(operation, common, require_live=args.step is None)
        need(parent is not None, "operation has no registered retained parent")
        if args.action == "parent-verify":
            need(args.parent_lease == operation["retained_authority"]["lease"]
                 and args.thread == operation["thread"]
                 and args.candidate == operation["application"]["commit"]
                 and args.candidate_repository == args.application_root == operation["application"]["root"],
                 "retained operation parent identities differ")
            print("same-domain" if parent[0] == common else "app-domain")
        else:
            need(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", args.lease), "invalid retained child ID")
            if args.action != "retained-verify":
                need(regular(common / "peanut-admin-resource-leases/mutex/pid").decode().strip() == str(os.getppid()),
                     "retained child mutation requires actual native central mutex")
                need(time.time() < args.expires <= int(parent[1]["expires_at"]), "retained child TTL exceeds parent")
            retained_guard(operation, common, args.lease, args.action.removeprefix("retained-"), args.expires,
                           allow_expired_parent=args.step is not None)
    elif args.action == "recovery-admit":
        admit_recovery(args.lease, args.owner)
    elif args.action == "recovery-info":
        common, directory, metadata = same_source_lease(args.lease)
        with mutex(common):
            print(json.dumps(recovery_check(directory, metadata, live_state(directory), parent=os.getppid())))
    elif args.action in ("source-window-enter", "source-window-close"):
        same_source_window(args.lease, args.owner, args.action == "source-window-close")
    elif args.action == "consume":
        print(json.dumps(consume_application(args.proof, args.application_root, args.registry, args.resource_id, args.endpoint_id)))
    elif args.action == "resolve":
        operation, digest, common, registry_sha = authority(args.operation)
        need(args.owner == operation["owner"], "operation owner mismatch")
        print(json.dumps({"worktree": operation["application"]["root"],
                          "candidate": operation["application"]["commit"],
                          "parent_lease": operation.get("retained_authority", {}).get("lease", ""),
                          "parent_expires_at": int(retained_parent(operation, common)[1]["expires_at"]) if "retained_authority" in operation else None,
                          "resources": {key: sorted(values) for key, values in
                                        expected_resources(operation, digest, registry_sha).items()}}))
    elif args.action == "verify":
        validate(json_unique(args.metadata), {key: set(values) for key, values in json_unique(args.resources).items()})
    elif args.action == "release-check":
        common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
        directory = common / "peanut-admin-resource-leases/leases" / args.lease
        value = live_state(directory)
        if value is not None:
            if value.get("phase") == "mt012":
                helper = runpy.run_path(str(ROOT / "scripts/project-resource-mt012.py"))
                helper["release_check"](args.lease, args.owner)
                return
            metadata = dict(table(directory / "metadata.tsv", 2))
            need(args.owner == metadata["owner"] == value["owner"], "operation release owner mismatch")
            need(not original_process_alive(value["pid"], value.get("process_birth")),
                 "operation owner process is still active")
            if value.get("child_pid") is not None:
                need(not original_process_alive(value["child_pid"], value.get("child_birth")),
                     "native operation child is still active")
            if value.get("phase") == "source-application":
                need(value.get("status") == "WINDOW_CLOSED" and value.get("operation_terminal") is True,
                     "unknown source APP window requires independent native reconciliation")
                same_source_lease(args.lease, allow_expired=True)
                receipt = ROOT / "server/private/maintenance" / ("source-window-" + args.lease + "-" + str(time.time_ns()) + ".json")
                save(receipt, dict(value, actual_process_terminal=True))
                (directory / STATE).unlink()
                return
            reviewed = {}
            operation, _, _ = lease(args.lease, allow_expired=True, terminal_review=reviewed)
            if reviewed:
                value["terminal_release_review"] = reviewed
            if value["status"] != "COMPLETED" or value["actual_process_terminal"] is not True:
                review = operation.get("terminal_review")
                need(isinstance(review, dict), "UNKNOWN needs a registered independent terminal review")
                raw = regular(canonical(review["path"]), private=True)
                project = project_authority()[1]
                path = canonical(review["path"])
                need(project in path.parents, "terminal review must belong to the registered Project")
                ref = git(project, "rev-parse", "HEAD").decode().strip()
                relative = str(path.relative_to(project))
                need(git(project, "ls-tree", "--name-only", ref, "--", relative).decode().strip() == relative,
                     "terminal review must be the exact versioned Project result")
                need(git(project, "show", ref + ":" + str(path.relative_to(project))) == raw,
                     "terminal review must be the exact versioned Project result")
                proof = json_unique(raw)
                failed_install = "failed_install" in reviewed
                need(proof.get("lease") == args.lease
                     and (proof.get("verdict") == "FAILED_INSTALL_RELEASE_ONLY" if failed_install
                          else proof.get("verdict") == "SAFE_TO_RELEASE")
                     and proof.get("state_sha256") == sha(regular(directory / STATE, private=True))
                     and sha(raw) == reviewed.get("sha256", sha(raw))
                     and proof.get("reviewer_thread") not in (None, "", value["owner"])
                     and proof.get("actual_process_terminal") is True
                     and (proof.get("unknown_preserved") is True and proof.get("release_only") is True
                          and proof.get("failure_point") == "status_response_parse_before_installer"
                          if failed_install else True),
                     "independent terminal review does not bind this exact stopped operation")
                value["terminal_review"] = {"sha256": sha(raw), "reviewer_thread": proof["reviewer_thread"],
                                            "project_commit": ref}
            receipt = canonical(operation["receipt_root"]) / (args.lease + ".json")
            need(receipt.parent.is_dir(), "operation terminal receipt parent unavailable")
            need(not receipt.exists(), "operation terminal receipt already exists; reconcile before release")
            save(receipt, value)
            need(json_unique(regular(receipt, private=True)) == value, "terminal receipt copy differs")
            (directory / STATE).unlink()
    else:
        execute(args.lease, args.owner, args.phase, args.step)


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError) as error:
        print("resource-application: " + str(error), file=sys.stderr)
        sys.exit(1)
