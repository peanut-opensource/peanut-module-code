#!/usr/bin/env python3
"""Exercise the registered defaults and per-checkout listener overrides."""

import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
STACK = ROOT / "scripts/local-stack.sh"
PROBE = ROOT / "scripts/local-environment-probe"


def values(path):
    return dict(line.split("=", 1) for line in path.read_text().splitlines() if "=" in line)


class LocalStackPortContractTest(unittest.TestCase):
    def run_stack(self, stack, backend):
        return subprocess.run(
            [str(STACK), "urls"],
            env={**os.environ, "PEANUT_LOCAL_ENV_FILE": str(stack), "PEANUT_SERVER_ENV_FILE": str(backend)},
            text=True, capture_output=True, timeout=15,
        )

    def run_probe(self, stack, backend):
        return subprocess.run(
            [str(PROBE), "--config-only", "--env-file", str(stack), "--backend-env", str(backend)],
            text=True, capture_output=True, timeout=15,
        )

    def test_missing_values_receive_defaults_and_repeat_without_change(self):
        with tempfile.TemporaryDirectory(prefix="peanut-local-port-") as directory:
            stack = Path(directory) / "stack.env"
            backend = Path(directory) / "backend.env"
            backend.write_text("DB_USER=peanut_admin_development\nDB_PASS=synthetic\n")
            backend.chmod(0o600)

            ready = self.run_stack(stack, backend)
            self.assertEqual(ready.returncode, 0, ready.stderr)
            self.assertEqual(values(stack)["PHP_PORT"], "20180")
            self.assertIn("http://127.0.0.1:20180/", ready.stdout)
            probe = self.run_probe(stack, backend)
            self.assertEqual(probe.returncode, 0, probe.stderr)
            self.assertEqual(json.loads(probe.stdout)["status"], "pass")

            before = (stack.read_bytes(), backend.read_bytes())
            repeated = self.run_stack(stack, backend)
            self.assertEqual(repeated.returncode, 0, repeated.stderr)
            self.assertEqual((stack.read_bytes(), backend.read_bytes()), before)

    def test_valid_overrides_are_preserved_across_urls_proxy_and_probe(self):
        with tempfile.TemporaryDirectory(prefix="peanut-local-port-") as directory:
            stack = Path(directory) / "stack.env"
            backend = Path(directory) / "backend.env"
            stack.write_text("PHP_PORT=8000\nVITE_PORT=5173\nPC_PORT=3100\nDEV_HTTP_PORT=8080\n")
            backend.write_text("DB_USER=peanut_admin_development\nDB_PASS=synthetic\n")
            stack.chmod(0o600)
            backend.chmod(0o600)

            ready = self.run_stack(stack, backend)
            self.assertEqual(ready.returncode, 0, ready.stderr)
            self.assertIn("http://127.0.0.1:8000/", ready.stdout)
            self.assertIn("Development: http://127.0.0.1:8080/\n", ready.stdout)
            self.assertIn("http://127.0.0.1:8080/admin/", ready.stdout)
            self.assertIn("http://127.0.0.1:5173/admin/", ready.stdout)
            self.assertIn("PC direct:   http://127.0.0.1:3100/\n", ready.stdout)
            self.assertEqual(values(stack)["PHP_PORT"], "8000")
            client = values(Path(directory) / "container-client.env")
            self.assertEqual(client["VITE_API_PROXY_TARGET"], "http://host.docker.internal:8000")
            self.assertEqual(client["NUXT_DEV_PROXY_TARGET"], "http://host.docker.internal:8000/api")
            self.assertEqual(client["PC_PORT"], "3100")
            probe = self.run_probe(stack, backend)
            self.assertEqual(probe.returncode, 0, probe.stderr)
            self.assertEqual(json.loads(probe.stdout)["status"], "pass")

    def test_invalid_or_conflicting_port_rejects_before_mutating_backend(self):
        for declaration in (
            "PHP_PORT=not-a-port\n",
            "PHP_PORT=0\n",
            "PHP_PORT=65536\n",
            "PHP_PORT=20181\n",
            "PHP_PORT=20189\n",
            "PHP_PORT=8000\nPHP_PORT=8001\n",
        ):
            with self.subTest(declaration=declaration.splitlines()[0]):
                with tempfile.TemporaryDirectory(prefix="peanut-local-port-") as directory:
                    stack = Path(directory) / "stack.env"
                    backend = Path(directory) / "backend.env"
                    stack.write_text(declaration)
                    backend.write_text(
                        "DB_USER=peanut_admin_development\nDB_PASS=synthetic\nADMIN_INITIAL_EMAIL=old@example.test\n"
                    )
                    stack.chmod(0o600)
                    backend.chmod(0o600)
                    before = (stack.read_bytes(), backend.read_bytes())
                    refused = self.run_stack(stack, backend)
                    self.assertNotEqual(refused.returncode, 0)
                    self.assertIn("project-resource-registry:", refused.stderr)
                    self.assertEqual((stack.read_bytes(), backend.read_bytes()), before)
                    self.assertFalse((Path(directory) / "container-client.env").exists())
                    probe = self.run_probe(stack, backend)
                    self.assertNotEqual(probe.returncode, 0)
                    checks = json.loads(probe.stdout)["checks"]
                    self.assertEqual(
                        next(item["status"] for item in checks if item["id"] == "config.local_environment"),
                        "fail",
                    )

    def test_symlink_and_backend_key_guards_reject_without_mutation(self):
        with tempfile.TemporaryDirectory(prefix="peanut-local-port-") as directory:
            base = Path(directory)
            stack = base / "stack.env"
            backend = base / "backend.env"
            stack.write_text("PHP_PORT=8000\nDB_PASS=must-not-belong-here\n")
            backend.write_text("DB_USER=peanut_admin_development\nDB_PASS=synthetic\n")
            stack.chmod(0o600)
            backend.chmod(0o600)
            before = (stack.read_bytes(), backend.read_bytes())
            self.assertNotEqual(self.run_stack(stack, backend).returncode, 0)
            self.assertEqual((stack.read_bytes(), backend.read_bytes()), before)

            stack.write_text("PHP_PORT=8000\n")
            linked_stack = base / "linked-stack.env"
            linked_stack.symlink_to(stack)
            before = (stack.read_bytes(), backend.read_bytes())
            self.assertNotEqual(self.run_stack(linked_stack, backend).returncode, 0)
            self.assertEqual((stack.read_bytes(), backend.read_bytes()), before)

            linked_backend = base / "linked-backend.env"
            linked_backend.symlink_to(backend)
            self.assertNotEqual(self.run_stack(stack, linked_backend).returncode, 0)
            self.assertEqual((stack.read_bytes(), backend.read_bytes()), before)


if __name__ == "__main__":
    unittest.main()
