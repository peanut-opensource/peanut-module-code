#!/usr/bin/env python3
"""Real temporary-file tests for the public non-secret runtime configurator."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("runtime_configuration", ROOT / "scripts/configure-runtime.py")
configuration = importlib.util.module_from_spec(spec)
spec.loader.exec_module(configuration)
IMAGE = "sha256:" + "a" * 64


class RuntimeConfigurationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="peanut-runtime-configuration-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve() / "server/docker"
        self.root.mkdir(parents=True)
        shutil.copyfile(ROOT / "runtime-configuration.example.json", self.root / "runtime-configuration.example.json")

    def test_creates_only_private_configuration_from_fixed_inputs(self):
        result = configuration.configure(self.root, IMAGE, "fixture-app", "127.0.0.1", 21081, 21307)
        self.assertEqual(result["status"], "created")
        target = self.root / ".env"
        self.assertEqual(target.stat().st_mode & 0o777, 0o600)
        data = target.read_text()
        self.assertIn("PHP_IMAGE=" + IMAGE + "\n", data)
        self.assertIn("HTTP_PORT=21081\n", data)
        root_password = next(line.split("=", 1)[1] for line in data.splitlines() if line.startswith("MYSQL_ROOT_PASSWORD="))
        self.assertRegex(root_password, r"^[a-f0-9]{64}$")
        self.assertEqual(data.count("MYSQL_ROOT_PASSWORD="), 1)
        self.assertFalse((self.root / "mysql").exists())
        self.assertFalse((self.root / "secrets").exists())

    def test_repeated_configuration_never_overwrites(self):
        configuration.configure(self.root, IMAGE)
        original = (self.root / ".env").read_bytes()
        with self.assertRaisesRegex(ValueError, "already exists"):
            configuration.configure(self.root, "sha256:" + "b" * 64)
        self.assertEqual((self.root / ".env").read_bytes(), original)

    def test_mutable_image_and_colliding_ports_are_rejected_before_writes(self):
        with self.assertRaisesRegex(ValueError, "immutable"):
            configuration.configure(self.root, "php:latest")
        with self.assertRaisesRegex(ValueError, "must differ"):
            configuration.configure(self.root, IMAGE, http_port=21080, mysql_host_port=21080)
        self.assertFalse((self.root / ".env").exists())

    def test_existing_data_and_dangling_config_are_preserved(self):
        (self.root / "mysql").mkdir()
        sentinel = self.root / "mysql/keep.txt"
        sentinel.write_text("synthetic existing instance")
        with self.assertRaisesRegex(ValueError, "already exists"):
            configuration.configure(self.root, IMAGE)
        self.assertEqual(sentinel.read_text(), "synthetic existing instance")
        sentinel.unlink()
        (self.root / "mysql").rmdir()
        (self.root / ".env").symlink_to("missing-config")
        with self.assertRaisesRegex(ValueError, "already exists"):
            configuration.configure(self.root, IMAGE)
        self.assertTrue((self.root / ".env").is_symlink())

    def test_unknown_template_fields_do_not_become_environment_variables(self):
        path = self.root / "runtime-configuration.example.json"
        data = json.loads(path.read_text())
        data["EXTRA_SECRET"] = "synthetic-not-a-real-secret"
        path.write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError, "unsupported"):
            configuration.configure(self.root, IMAGE)
        self.assertFalse((self.root / ".env").exists())


if __name__ == "__main__":
    unittest.main()
