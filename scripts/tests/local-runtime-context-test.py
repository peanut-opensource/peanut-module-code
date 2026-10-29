#!/usr/bin/env python3
"""Exercise real local-stack generation and dev start commands with local fixtures.

No Docker, MySQL, network, or package installation is performed. The registry and
package-manager executables are fixtures; these tests do not certify live service
identity, dependency installation, container networking, or application behavior.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
DEFAULTS = {
    "PHP_PORT": "20180", "VITE_PORT": "20181", "PLATFORM_PORT": "20177",
    "PC_PORT": "20185", "MOBILE_PORT": "20182", "DOCS_PORT": "20186",
    "DEV_HTTP_PORT": "20187", "HTTP_PORT": "20190", "REDIS_PORT": "20184",
}


def read_values(path: Path) -> dict[str, str]:
    return dict(line.split("=", 1) for line in path.read_text().splitlines() if "=" in line)


def command_for(service: str) -> list[str]:
    text = (ROOT / "deploy/docker-compose.dev.yml").read_text()
    section = text.split(f"  {service}:\n", 1)[1]
    return json.loads(re.search(r"^    command: (\[.*\])$", section, re.M).group(1))


class LocalRuntimeContextTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory(prefix="peanut-runtime-context-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name).resolve()
        (self.root / "scripts").mkdir()
        (self.root / ".local").mkdir()
        (self.root / "server").mkdir()
        self.stack = self.root / ".local/stack.env"
        self.backend = self.root / "server/.env"
        self.stack.write_text("PHP_PORT=8000\nPC_PORT=3100\nDEV_HTTP_PORT=8080\n")
        self.backend.write_text(
            "DB_USER=peanut_admin_development\nDB_PASS=synthetic-secret\n"
            "JWT_SECRET=synthetic-jwt\nTENANT_IDENTIFIER_HMAC_KEY=synthetic-tenant\n"
            "PLATFORM_IDENTIFIER_HMAC_KEY=synthetic-platform\n"
        )
        shutil.copyfile(ROOT / "scripts/local-stack.sh", self.root / "scripts/local-stack.sh")
        registry = self.root / "scripts/project-resource-registry"
        registry.write_text(
            "#!/usr/bin/env python3\nimport sys\nfrom pathlib import Path\n"
            f"defaults={DEFAULTS!r}\n"
            "if sys.argv[1] == 'local-stack-ports':\n"
            "    p=Path(sys.argv[sys.argv.index('--env-file')+1])\n"
            "    if p.exists():\n"
            "        for line in p.read_text().splitlines():\n"
            "            k, sep, v=line.partition('=')\n"
            "            if sep and k in defaults and v: defaults[k]=v\n"
            "    for k,v in defaults.items(): print(k+'='+v)\n"
            "elif sys.argv[1] == 'database-env':\n"
            "    print('DB_HOST=127.0.0.1\\nDB_PORT=33306\\nDB_NAME=synthetic_only')\n"
            "    print('PEANUT_DATABASE_RESOURCE_ID=synthetic-fixture')\n"
            "else: sys.exit(91)\n"
        )
        registry.chmod(0o700)
        subprocess.run(["git", "init", "-q", str(self.root)], check=True, capture_output=True)
        subprocess.run(
            ["git", "-C", str(self.root), "-c", "user.name=Fixture", "-c",
             "user.email=fixture@example.invalid", "-c", "commit.gpgsign=false",
             "commit", "--allow-empty", "-qm", "isolated test fixture"],
            check=True, capture_output=True,
        )

    def run_stack(self) -> subprocess.CompletedProcess[str]:
        environment = dict(os.environ)
        environment["PEANUT_LOCAL_ENV_FILE"] = str(self.stack)
        environment["PEANUT_SERVER_ENV_FILE"] = str(self.backend)
        return subprocess.run(
            ["sh", str(self.root / "scripts/local-stack.sh"), "urls"],
            env=environment, text=True, capture_output=True, timeout=15,
        )

    def test_host_and_container_proxies_follow_same_effective_ports(self) -> None:
        result = self.run_stack()
        self.assertEqual(result.returncode, 0, result.stderr)
        for filename, host in (("host-client.env", "127.0.0.1"),
                               ("container-client.env", "host.docker.internal")):
            path = self.root / ".local" / filename
            values = read_values(path)
            self.assertEqual(values["PHP_PORT"], "8000")
            self.assertEqual(values["PC_PORT"], "3100")
            self.assertEqual(values["NUXT_DEV_PROXY_ORIGIN"], f"http://{host}:8000")
            self.assertEqual(values["NUXT_DEV_PROXY_TARGET"], f"http://{host}:8000/api")
            self.assertEqual(values["VITE_API_PROXY_TARGET"], f"http://{host}:8000")
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
            self.assertFalse(any(re.search(r"PASS|SECRET|TOKEN|KEY", key) for key in values))
            self.assertNotIn("synthetic-secret", path.read_text())
        self.assertIn("PC direct:   http://127.0.0.1:3100/\n", result.stdout)
        self.assertEqual(read_values(self.backend)["DB_PASS"], "synthetic-secret")

    def test_missing_ports_use_registered_defaults(self) -> None:
        self.stack.write_text("")
        result = self.run_stack()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(read_values(self.root / ".local/host-client.env")["NUXT_DEV_PROXY_ORIGIN"],
                         "http://127.0.0.1:20180")

    def test_repeated_generation_preserves_all_content(self) -> None:
        self.assertEqual(self.run_stack().returncode, 0)
        paths = [self.stack, self.backend, self.root / ".local/host-client.env",
                 self.root / ".local/container-client.env"]
        before = [path.read_bytes() for path in paths]
        result = self.run_stack()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([path.read_bytes() for path in paths], before)
        self.assertEqual(list((self.root / ".local").glob("client-environment.*")), [])

    def test_generated_symlinks_reject_before_config_mutation(self) -> None:
        outside = self.root / "outside.txt"
        outside.write_text("not a generated file\n")
        for filename in ("host-client.env", "container-client.env"):
            with self.subTest(filename=filename):
                link = self.root / ".local" / filename
                link.symlink_to(outside)
                before = (self.stack.read_bytes(), self.backend.read_bytes(), outside.read_bytes())
                result = self.run_stack()
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("must not be a symlink", result.stderr)
                self.assertEqual((self.stack.read_bytes(), self.backend.read_bytes(), outside.read_bytes()), before)
                link.unlink()

    def test_generated_directory_rejects_before_config_mutation(self) -> None:
        path = self.root / ".local/host-client.env"
        path.mkdir()
        before = (self.stack.read_bytes(), self.backend.read_bytes())
        result = self.run_stack()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("regular file", result.stderr)
        self.assertEqual((self.stack.read_bytes(), self.backend.read_bytes()), before)


class DevelopmentCommandTest(unittest.TestCase):
    def exercise(self, service: str, fail: str | None) -> tuple[int, str]:
        with tempfile.TemporaryDirectory(prefix="peanut-command-context-") as directory:
            root = Path(directory)
            binary = root / "bin"
            binary.mkdir()
            marker = root / "events"
            for tool in ("npm", "pnpm", "corepack", "node"):
                script = binary / tool
                script.write_text(
                    '#!/bin/sh\nprintf "%s\\n" "' + tool + ' $*" >> "$TEST_EVENTS"\n'
                    + ("exit 23\n" if tool == fail else "exit 0\n")
                )
                script.chmod(0o700)
            environment = {**os.environ, "PATH": str(binary) + os.pathsep + os.environ["PATH"],
                           "TEST_EVENTS": str(marker)}
            result = subprocess.run(command_for(service), cwd=root, env=environment,
                                    text=True, capture_output=True, timeout=10)
            return result.returncode, marker.read_text() if marker.exists() else ""

    def test_failed_install_never_executes_runtime(self) -> None:
        for service, fail in (("web", "pnpm"), ("platform", "npm"), ("pc", "npm"),
                              ("mobile", "npm"), ("docs", "pnpm"), ("docs", "corepack")):
            with self.subTest(service=service, fail=fail):
                code, events = self.exercise(service, fail)
                self.assertEqual(code, 23, events)
                self.assertFalse(any(line.startswith("node ") for line in events.splitlines()), events)

    def test_successful_install_reaches_runtime(self) -> None:
        for service in ("web", "platform", "pc", "mobile", "docs"):
            with self.subTest(service=service):
                code, events = self.exercise(service, None)
                self.assertEqual(code, 0, events)
                self.assertTrue(any(line.startswith("node ") for line in events.splitlines()), events)
                self.assertNotIn("--legacy-peer-deps", events)

    def test_host_gateway_has_no_application_dependencies(self) -> None:
        # Structural only: real Compose/Nginx parsing and requests run locally.
        text = (ROOT / "deploy/docker-compose.host-gateway.yml").read_text()
        self.assertEqual(re.findall(r"^  ([a-z][a-z_-]*):$", text, re.M), ["nginx"])
        self.assertNotIn("depends_on:", text)
        self.assertNotIn("command:", text)
        for name in ("PHP_PORT", "VITE_PORT", "PLATFORM_PORT", "PC_PORT", "MOBILE_PORT", "DEV_HTTP_PORT"):
            self.assertIn("${" + name + ":?", text)
        self.assertIn("name: peanut-admin-development", text)


if __name__ == "__main__":
    unittest.main(verbosity=2)
