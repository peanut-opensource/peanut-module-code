import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/update-preparation.py"
spec = importlib.util.spec_from_file_location("update_preparation", SCRIPT)
preparation = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preparation)
TEMP_ROOT = Path(__file__).resolve().parents[3] / ".local/tmp/phase1-continuation-20260930"


def write(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data)
    path.chmod(0o600)


class VendorSwitchTest(unittest.TestCase):
    def setUp(self):
        TEMP_ROOT.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=TEMP_ROOT)
        root = Path(self.temporary.name)
        self.server = root / "server"
        self.workspace = root / "workspace"
        self.target = self.workspace / "prepared/server"
        (self.workspace / "recovery").mkdir(parents=True)
        for base, lock, vendor in ((self.server, b"old-lock", b"old-vendor"),
                                   (self.target, b"new-lock", b"new-vendor")):
            write(base / "composer.lock", lock)
            write(base / "composer.json", lock)
            write(base / "vendor/autoload.php", vendor)
            write(base / "vendor/.peanut-complete.json", vendor)
        self.plan = {"protocol": "peanut.server-update-plan.v1", "update_id": "unit-update",
                     "source": {"identity_sha256": "a" * 64,
                                "composer_lock_sha256": preparation.sha(self.server / "composer.lock")},
                     "target": {"identity_sha256": "b" * 64}}
        write(self.workspace / "plan.json", json.dumps(self.plan).encode())
        write(self.workspace / "backup.json", json.dumps({"update_id": "unit-update",
                     "plan_sha256": preparation.sha(self.workspace / "plan.json")}).encode())
        self.patches = [patch.object(preparation.subprocess, "run", return_value=type("Process", (), {"returncode": 0})()),
                        patch.object(preparation, "verify_recovery_point", return_value=None)]
        for item in self.patches:
            item.start()
        self.call("record")

    def tearDown(self):
        for item in reversed(self.patches):
            item.stop()
        self.temporary.cleanup()

    def call(self, phase):
        argv = [str(SCRIPT), phase, "--server", str(self.server), "--workspace", str(self.workspace)]
        with patch.object(preparation.sys, "argv", argv):
            preparation.main()

    def intent(self):
        data = json.loads((self.workspace / "preparation.json").read_text())
        intent = {"status": "started", "update_id": "unit-update",
                  "plan_sha256": preparation.sha(self.workspace / "plan.json"),
                  "backup_sha256": preparation.sha(self.workspace / "backup.json"),
                  "source_present": True, "source_tree_sha256": data["source_tree_sha256"],
                  "target_tree_sha256": data["target_tree_sha256"]}
        write(self.workspace / "dependency-switch.json", json.dumps(intent).encode())

    def test_complete_switch_and_repeat(self):
        self.call("switch")
        self.assertEqual((self.server / "vendor/autoload.php").read_bytes(), b"new-vendor")
        self.assertEqual((self.workspace / "recovery/old-vendor/autoload.php").read_bytes(), b"old-vendor")
        self.call("switch")
        self.assertEqual(json.loads((self.workspace / "dependency-switch.json").read_text())["status"], "completed")

    def test_resume_after_old_vendor_rename(self):
        self.intent()
        os.replace(self.server / "vendor", self.workspace / "recovery/old-vendor")
        self.call("switch")
        self.assertEqual((self.server / "vendor/autoload.php").read_bytes(), b"new-vendor")

    def test_resume_after_target_vendor_rename(self):
        self.intent()
        os.replace(self.server / "vendor", self.workspace / "recovery/old-vendor")
        os.replace(self.target / "vendor", self.server / "vendor")
        self.call("switch")
        self.assertEqual(json.loads((self.workspace / "dependency-switch.json").read_text())["status"], "completed")

    def test_recover_after_both_renames(self):
        self.call("switch")
        self.call("recover")
        self.assertEqual((self.server / "vendor/autoload.php").read_bytes(), b"old-vendor")
        self.assertEqual((self.workspace / "recovery/new-vendor/autoload.php").read_bytes(), b"new-vendor")

    def test_recover_after_first_rename(self):
        self.intent()
        os.replace(self.server / "vendor", self.workspace / "recovery/old-vendor")
        self.call("recover")
        self.assertEqual((self.server / "vendor/autoload.php").read_bytes(), b"old-vendor")

    def test_tampered_old_vendor_blocks_recovery(self):
        self.call("switch")
        write(self.workspace / "recovery/old-vendor/autoload.php", b"tampered")
        with self.assertRaisesRegex(ValueError, "old vendor"):
            self.call("recover")
        self.assertFalse((self.server / "vendor").exists())

    def test_backup_binding_and_stopped_verification_required(self):
        with patch.object(preparation, "verify_recovery_point", side_effect=ValueError("writers running")):
            with self.assertRaisesRegex(ValueError, "writers running"):
                self.call("switch")
        self.assertFalse((self.workspace / "dependency-switch.json").exists())
        write(self.workspace / "backup.json", b'{"update_id":"wrong"}')
        with self.assertRaisesRegex(ValueError, "differs from plan"):
            self.call("switch")
        self.assertEqual((self.server / "vendor/autoload.php").read_bytes(), b"old-vendor")


if __name__ == "__main__":
    unittest.main()
