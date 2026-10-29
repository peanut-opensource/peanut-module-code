#!/usr/bin/env python3
"""Offline checks for captured installer output; never touches an APP or lease."""

import json
from pathlib import Path
import runpy
import shutil
import subprocess
import sys
import tempfile
from unittest import TestCase, main, mock


SCRIPT = Path(__file__).resolve().parents[1] / "project-resource-application.py"


class ApplicationOutputTest(TestCase):
    def setUp(self):
        self.native = runpy.run_path(str(SCRIPT))

    def run_child(self, stdout, exit_code, accepted, stderr=b""):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            operation = {"application": {"root": temporary}, "php_executable": sys.executable,
                         "max_seconds": 60}
            value = {"owner": "fixture", "started_at": self.native["time"].time()}
            command = [sys.executable, "-c", "import sys; sys.stdout.buffer.write(" + repr(stdout)
                       + "); sys.stderr.buffer.write(" + repr(stderr) + "); sys.exit(" + str(exit_code) + ")"]
            with mock.patch.dict(self.native["command"].__globals__,
                                 verify_native=lambda *_: None,
                                 lease=lambda *_1, **_2: (operation, None, {"expires_at": str(int(self.native["time"].time()) + 120)}),
                                 process_birth=lambda *_: "fixture",
                                 save=lambda *_: None):
                output = self.native["command"](operation, directory, value, "fixture", command,
                                                accepted=accepted)
                return output, value

    def test_status_json_retained_for_st_and_mt(self):
        for edition in ("ST", "MT"):
            with self.subTest(edition=edition):
                payload = json.dumps({"mode": edition, "state": "uninstalled", "code": "INSTALL_READY"}).encode()
                actual, value = self.run_child(payload, 1, (1,), b"status diagnostic")
                status = self.native["pristine_installation_status"](actual)
                self.assertEqual((status["mode"], status["state"], status["code"]),
                                 (edition, "uninstalled", "INSTALL_READY"))
                self.assertEqual(value["child_output"]["stderr_bytes"], len(b"status diagnostic"))
                self.assertEqual(value["child_output"]["stdout_sha256"], self.native["sha"](payload))

    def test_status_abnormal_output_fails_closed(self):
        for payload in (b"", b"not-json", b'{"state":"blocked","code":"INSTALL_BLOCKED"}'):
            with self.subTest(payload=payload):
                with self.assertRaises(RuntimeError):
                    self.native["pristine_installation_status"](self.run_child(payload, 1, (1,))[0])

    def test_failed_installer_exit_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, "native first-install phase failed"):
            self.run_child(b'{"status":"failed"}', 1, (0,))

    def test_timeout_renews_then_retains_output(self):
        class SlowChild:
            pid = 123
            returncode = 1
            calls = 0

            def communicate(self, input=None, timeout=None):
                self.calls += 1
                if self.calls == 1:
                    raise subprocess.TimeoutExpired("fixture", timeout)
                return b'{"state":"uninstalled","code":"INSTALL_READY"}', b"diagnostic"

        with tempfile.TemporaryDirectory() as temporary:
            operation = {"application": {"root": temporary}, "php_executable": sys.executable,
                         "max_seconds": 60}
            value = {"owner": "fixture", "started_at": self.native["time"].time()}
            child = SlowChild()
            with mock.patch.dict(self.native["command"].__globals__,
                                 verify_native=lambda *_: None,
                                 lease=lambda *_1, **_2: (operation, None, {"expires_at": str(int(self.native["time"].time()) + 120)}),
                                 process_birth=lambda *_: "fixture", save=lambda *_: None,
                                 renew_native=lambda *_: None), \
                 mock.patch.object(self.native["subprocess"], "Popen", return_value=child):
                output = self.native["command"](operation, Path(temporary), value, "fixture",
                                                [sys.executable], accepted=(1,))
            self.assertEqual(child.calls, 2)
            self.assertEqual(self.native["pristine_installation_status"](output)["code"], "INSTALL_READY")
            self.assertEqual(value["child_output"]["stderr_bytes"], len(b"diagnostic"))

    def test_status_producer_caught_error_is_json_and_still_fails(self):
        source = SCRIPT.parent.parent / "server"
        with tempfile.TemporaryDirectory() as temporary:
            server = Path(temporary) / "server"
            for relative in ("database/install.php", "database/environment-guard.php",
                             "database/resource-registry.php", "bootstrap/environment.php"):
                target = server / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source / relative, target)
            vendor = server / "vendor/autoload.php"
            vendor.parent.mkdir()
            vendor.write_text("<?php\n")
            environment = server / ".env"
            environment.write_text("")
            environment.chmod(0o600)
            result = subprocess.run(["php", str(server / "database/install.php"), "--status"],
                                    capture_output=True, cwd=temporary, check=False)
            self.assertEqual(result.returncode, 1, result.stderr.decode(errors="replace"))
            self.assertEqual(self.native["json_unique"](result.stdout),
                             {"state": "blocked", "code": "INSTALL_STATUS_UNAVAILABLE"})
            self.assertIn(b"INSTALL_EXECUTION_FAILED", result.stderr)
            environment.unlink()
            missing_env = subprocess.run(["php", str(server / "database/install.php"), "--status"],
                                         capture_output=True, cwd=temporary, check=False)
            self.assertEqual(missing_env.returncode, 1)
            self.assertEqual(self.native["json_unique"](missing_env.stdout)["state"], "blocked")
            vendor.unlink()
            missing_vendor = subprocess.run(["php", str(server / "database/install.php"), "--status"],
                                            capture_output=True, cwd=temporary, check=False)
            self.assertEqual(missing_vendor.returncode, 1)
            self.assertEqual(self.native["json_unique"](missing_vendor.stdout)["state"], "blocked")


if __name__ == "__main__":
    main()
