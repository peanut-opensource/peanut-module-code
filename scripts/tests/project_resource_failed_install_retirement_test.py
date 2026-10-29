#!/usr/bin/env python3
"""Offline failed-install retirement admission; no APP, database or lease command."""

import copy
import hashlib
import json
from pathlib import Path
import runpy
import tempfile
from unittest import TestCase, main, mock


SCRIPT = Path(__file__).resolve().parents[1] / "project-resource-application.py"


class FailedInstallRetirementTest(TestCase):
    def setUp(self):
        self.native = runpy.run_path(str(SCRIPT))
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve()
        self.project = self.root / "project"
        self.project.mkdir()
        self.directory = self.root / "lease"
        self.directory.mkdir()
        self.app = self.root / "app"
        (self.app / "server/private/installation").mkdir(parents=True)
        self.witness = self.project / "witness.json"
        self.witness.write_text("{}")
        self.witness.chmod(0o600)
        self.operation = {
            "stable_resource_id": self.native["FAILED_INSTALL_OPERATION"], "phase": "install",
            "application": {"root": str(self.app)},
            "database": {"name": "fixture_db", "server_uuid": "fixture_uuid", "version": "8.4"},
            "tool_source": {"commit": "a" * 40, "files": {"scripts/project-resource-application.py": ""}},
        }
        self.metadata = {"owner": "owner", "lease": "lease"}
        self.state = {
            "phase": "install", "status": "UNKNOWN", "failure": "JSONDecodeError",
            "actual_process_terminal": True, "child_pid": None, "child_exit_code": 1,
            "metadata_readonly": {
                "status": "COMPLETED", "witness": str(self.witness),
                "witness_sha256": self.native["sha"](self.witness.read_bytes()),
                "result": {"database": "fixture_db", "server_uuid": "fixture_uuid", "version": "8.4",
                           "principal": "root@localhost", "schema_rows": 1, "partial_revokes": 0,
                           "active_roles": "NONE", "tables_views": 0, "triggers": 0,
                           "routines": 0, "events": 0},
            },
        }
        self.source = b"fixed historical source"
        self.source_sha = hashlib.sha256(self.source).hexdigest()
        self.operation["tool_source"]["files"]["scripts/project-resource-application.py"] = self.source_sha
        self.digest = "b" * 64
        self.proof = {
            "verdict": "FAILED_INSTALL_RELEASE_ONLY",
            "failure_point": "status_response_parse_before_installer",
            "unknown_preserved": True, "release_only": True, "no_pending_process": True,
            "status_stdout": "NOT_AVAILABLE",
            "native_jobs": [
                {"job_id": "wc_job_claim", "status": "completed", "exit_code": 0},
                {"job_id": "wc_job_execution", "status": "failed", "exit_code": 1},
            ],
        }
        self.evidence_path = self.project / "execution.json"
        self.refresh_evidence()

    def refresh_evidence(self):
        raw = json.dumps(self.state, sort_keys=True).encode() + b"\n"
        (self.directory / "application-operation.json").write_bytes(raw)
        (self.directory / "application-operation.json").chmod(0o600)
        (self.directory / "metadata.tsv").write_bytes(b"owner\towner\n")
        (self.directory / "resources.tsv").write_bytes(b"resource\tfixture\n")
        snapshots = {}
        for name in ("application-operation.json", "metadata.tsv", "resources.tsv"):
            data = (self.directory / name).read_bytes()
            snapshots[name] = {"reference": {"sha256": self.native["sha"](data)},
                               "exact_bytes_utf8": data.decode()}
        def job(job_id, status, exit_code):
            return {"structuredContent": {"success": True, "output": {"items": [
                {"job_id": job_id, "status": status, "terminal": True, "exit_code": exit_code}
            ]}}}
        self.evidence = {
            "native_state_snapshot": snapshots,
            "inputs": {"operation_id": self.operation["stable_resource_id"],
                       "operation_digest": self.digest, "owner": "owner",
                       "Code_source": {"commit": "a" * 40}},
            "claim_Job": "wc_job_claim", "install_Job": "wc_job_execution", "claim_exit": 0,
            "failure": {"exit_code": 1, "native_failure": "JSONDecodeError", "last_child_exit": 1,
                        "actual_process_terminal": True, "child_pid": None},
            "actual_installer_count": 0, "budgets": {"ordinary_installer_calls_all_history": 0},
            "native_evidence": {"claim_terminal": job("wc_job_claim", "completed", 0),
                                "entry_terminal": job("wc_job_execution", "failed", 1)},
            "actual_metadata": self.state["metadata_readonly"],
            "private_witness": {"path": str(self.witness),
                                "sha256": self.state["metadata_readonly"]["witness_sha256"]},
            "APP_after": {"markers": {name: False for name in
                                      ("installed.json", "executing.json", "baseline.json", "migration.json")}},
        }
        self.save_evidence()

    def save_evidence(self):
        self.evidence_path.write_text(json.dumps(self.evidence, sort_keys=True))
        self.evidence_path.chmod(0o600)
        self.proof["original_execution_evidence"] = {
            "path": str(self.evidence_path), "sha256": self.native["sha"](self.evidence_path.read_bytes())}

    def check(self):
        globals_ = self.native["failed_install_review"].__globals__
        with mock.patch.dict(globals_, FAILED_INSTALL_EVIDENCE_SHA256=self.proof["original_execution_evidence"]["sha256"],
                             FAILED_INSTALL_SOURCE_SHA256=self.source_sha,
                             git=lambda *_: self.source):
            return self.native["failed_install_review"](
                self.operation, self.project, self.directory, self.metadata, {},
                self.state, self.proof, self.digest)

    def test_exact_failed_status_parse_admits_release_only(self):
        result = self.check()
        self.assertEqual(result["failure_point"], "status_response_parse_before_installer")
        self.assertTrue(result["unknown_preserved"])
        self.assertTrue(result["release_only"])

    def test_wrong_phase_hash_job_or_installer_evidence_fails_closed(self):
        original_state = copy.deepcopy(self.state)
        original_proof = copy.deepcopy(self.proof)
        changes = [
            (lambda: self.state.update(phase="protect"), False),
            (lambda: self.proof["original_execution_evidence"].update(sha256="0" * 64), False),
            (lambda: self.evidence["native_evidence"]["entry_terminal"]["structuredContent"]["output"]["items"][0].update(exit_code=0), True),
            (lambda: self.evidence.update(actual_installer_count=1), True),
            (lambda: self.proof.update(no_pending_process=False), False),
            (lambda: self.proof.update(status_stdout=""), False),
        ]
        for change, evidence_changed in changes:
            with self.subTest(change=change):
                self.state = copy.deepcopy(original_state)
                self.proof = copy.deepcopy(original_proof)
                self.refresh_evidence()
                change()
                if evidence_changed:
                    self.save_evidence()
                with self.assertRaises(RuntimeError):
                    self.check()

    def test_marker_and_dangling_symlink_fail_closed(self):
        marker = self.app / "server/private/installation/installed.json"
        for dangling in (False, True):
            with self.subTest(dangling=dangling):
                if dangling:
                    marker.symlink_to(self.root / "absent")
                else:
                    marker.write_text("{}")
                try:
                    with self.assertRaisesRegex(RuntimeError, "marker exists"):
                        self.check()
                finally:
                    marker.unlink()


if __name__ == "__main__":
    main()
