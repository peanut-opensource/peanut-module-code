import gzip
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/update-recovery.py"
spec = importlib.util.spec_from_file_location("update_recovery", SCRIPT)
recovery = importlib.util.module_from_spec(spec)
spec.loader.exec_module(recovery)
TEMP_ROOT = Path(__file__).resolve().parents[3] / ".local/tmp/phase1-continuation-20260930"


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(content)
    path.chmod(0o600)


class RecoveryFilesTest(unittest.TestCase):
    def setUp(self):
        TEMP_ROOT.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=TEMP_ROOT)
        root = Path(self.temporary.name)
        self.server = root / "server"
        self.workspace = root / "update"
        self.server.mkdir()
        write(self.server / "private/maintenance/update-verification.key", b"a" * 64 + b"\n")
        (self.server / "private/maintenance").chmod(0o700)
        (self.workspace / "recovery").mkdir(parents=True)
        self.plan = {"update_id": "unit-update", "source": {}}
        snapshots = {}
        for name in recovery.SNAPSHOT_NAMES:
            path = self.workspace / "recovery" / name
            write(path, name.encode())
            snapshots[name] = digest(path)
        self.plan["source"] = {
            "installed_receipt_sha256": snapshots["private/installation/installed.json"],
            "baseline_sha256": snapshots["private/installation/baseline.json"],
            "deployment_state_sha256": snapshots["private/installation/deployment.json"],
        }
        write(self.workspace / "plan.json", b"plan")
        sql = b"DROP DATABASE IF EXISTS `peanut`;\nCREATE DATABASE `peanut`;\nUSE `peanut`;\n"
        with gzip.open(self.workspace / "recovery/database.sql.gz", "wb") as stream:
            stream.write(sql)
        (self.workspace / "recovery/database.sql.gz").chmod(0o600)
        self.probe = {"server_uuid": "12345678-1234-1234-1234-123456789abc", "database": "peanut",
                      "charset": "utf8mb4", "collation": "utf8mb4_unicode_ci", "table_count": 1}
        self.runtime = {"images": {name: "sha256:" + name[0] * 64 for name in ("php", "nginx", "mysql")},
                        "project": "peanut-unit"}
        self.identity = {"resource_id": "db-unit", "endpoint_id": "mysql-unit", "database": "peanut"}
        # Real canonical phase binding for the native file-recovery consumer.
        # instance/service/database effects remain isolated by the existing test patchers.
        canonical = lambda value: json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode()
        product_path = root / "product-plan.json"
        state_path = root / "product-state.json"
        product = {"protocol": "peanut.product-upgrade-plan.v2", "workspace": str(self.workspace),
                   "candidate": "unit-candidate", "state_path": str(state_path)}
        product["plan_sha256"] = "sha256:" + hashlib.sha256(canonical(product)).hexdigest()
        write(product_path, json.dumps(product).encode())
        state = {"protocol": "peanut.product-upgrade-state.v1", "candidate": product["candidate"],
                 "plan_sha256": product["plan_sha256"]}
        state["state_sha256"] = "sha256:" + hashlib.sha256(canonical(state)).hexdigest()
        write(state_path, json.dumps(state).encode())
        write(self.workspace / "product-binding.json", json.dumps({"plan_path": str(product_path),
              "plan_sha256": product["plan_sha256"]}).encode())
        self.record = {"product_plan_sha256": product["plan_sha256"],
                       "storage": {name: None for name in recovery.STORAGE_NAMES},
                       "protocol": "peanut.server-update-backup.v1", "update_id": "unit-update",
                       "plan_sha256": digest(self.workspace / "plan.json"), "database_identity": dict(self.identity),
                       "database_dump_sha256": digest(self.workspace / "recovery/database.sql.gz"),
                       "source_images": self.runtime["images"], "compose_project": self.runtime["project"],
                       "mysql_identity": self.probe, "snapshots": snapshots}
        self.write_record()
        self.args = type("Args", (), {"server": self.server, "workspace": self.workspace})()
        self.patchers = [
            patch.object(recovery, "instance", return_value=(self.server, self.workspace, self.plan,
                                                                {"activation_started": False},
                                                                digest(self.workspace / "plan.json"), self.identity)),
            patch.object(recovery, "assert_stopped", return_value=self.runtime),
            patch.object(recovery, "db_probe", return_value=self.probe),
        ]
        for item in self.patchers:
            item.start()

    def tearDown(self):
        for item in reversed(self.patchers):
            item.stop()
        self.temporary.cleanup()

    def write_record(self):
        record = self.workspace / "backup.json"
        write(record, json.dumps(self.record).encode())
        write(self.workspace / "backup.json.hmac", hmac.new(b"a" * 64, record.read_bytes(), hashlib.sha256).hexdigest().encode())

    def test_forged_backup_receipt_rejected(self):
        (self.workspace / "backup.json.hmac").write_text("0" * 64)
        with self.assertRaisesRegex(ValueError, "signature"):
            recovery.verify(self.args)

    def test_valid_recovery_files(self):
        self.assertEqual(recovery.verify(self.args)[-1], self.record)

    def test_snapshot_path_traversal_rejected(self):
        self.record["snapshots"]["../../../outside.txt"] = "a" * 64
        self.write_record()
        with self.assertRaisesRegex(ValueError, "allowlist"):
            recovery.verify(self.args)

    def test_snapshot_missing_and_absolute_rejected(self):
        del self.record["snapshots"][".env"]
        with self.assertRaisesRegex(ValueError, "allowlist"):
            self.write_record(); recovery.verify(self.args)
        self.record["snapshots"]["/.env"] = "a" * 64
        with self.assertRaisesRegex(ValueError, "allowlist"):
            self.write_record(); recovery.verify(self.args)

    def test_snapshot_parent_symlink_rejected(self):
        parent = self.workspace / "recovery/private"
        os.rename(parent, self.workspace / "private-saved")
        parent.symlink_to(self.workspace / "private-saved", target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "parent"):
            recovery.verify(self.args)

    def test_snapshot_hardlink_and_mode_rejected(self):
        path = self.workspace / "recovery/.env"
        os.link(path, self.workspace / "second-link")
        with self.assertRaisesRegex(ValueError, "unsafe"):
            recovery.verify(self.args)
        (self.workspace / "second-link").unlink()
        path.chmod(0o644)
        with self.assertRaisesRegex(ValueError, "mode"):
            recovery.verify(self.args)

    def test_wrong_plan_and_instance_rejected(self):
        self.record["plan_sha256"] = "f" * 64
        self.write_record()
        with self.assertRaisesRegex(ValueError, "another plan"):
            recovery.verify(self.args)
        self.record["plan_sha256"] = digest(self.workspace / "plan.json")
        self.record["database_identity"]["resource_id"] = "other"
        self.write_record()
        with self.assertRaisesRegex(ValueError, "another plan"):
            recovery.verify(self.args)

    def test_dump_damage_and_other_schema_rejected(self):
        path = self.workspace / "recovery/database.sql.gz"
        path.write_bytes(b"broken")
        with self.assertRaisesRegex(ValueError, "bytes changed"):
            recovery.verify(self.args)
        with gzip.open(path, "wb") as stream:
            stream.write(b"DROP DATABASE `other`; CREATE DATABASE `other`; USE `other`;")
        path.chmod(0o600)
        self.record["database_dump_sha256"] = digest(path)
        self.write_record()
        with self.assertRaisesRegex(ValueError, "another schema"):
            recovery.verify(self.args)

    def test_source_image_and_mysql_identity_drift_rejected(self):
        with patch.object(recovery, "assert_stopped", return_value={"images": {}, "project": "peanut-unit"}):
            with self.assertRaisesRegex(ValueError, "image"):
                recovery.verify(self.args)
        with patch.object(recovery, "db_probe", return_value={**self.probe, "server_uuid": "other"}):
            with self.assertRaisesRegex(ValueError, "MySQL server"):
                recovery.verify(self.args)

    def test_activation_started_refuses_restore(self):
        with patch.object(recovery, "instance", return_value=(self.server, self.workspace, self.plan,
                                                                  {"activation_started": True},
                                                                  digest(self.workspace / "plan.json"), self.identity)):
            with self.assertRaisesRegex(ValueError, "activation started"):
                recovery.restore(self.args)
        self.assertFalse((self.workspace / "database-recovery.json").exists())

    def test_failed_import_keeps_started_intent(self):
        with patch.object(recovery, "compose", side_effect=RuntimeError("mysql import exit 9")):
            with self.assertRaisesRegex(RuntimeError, "exit 9"):
                recovery.restore(self.args)
        self.assertEqual(json.loads((self.workspace / "database-recovery.json").read_text())["status"], "started")


class ActualInstanceBindingTest(unittest.TestCase):
    def setUp(self):
        TEMP_ROOT.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=TEMP_ROOT)
        root = Path(self.temporary.name)
        self.server = root / "server"
        self.workspace = root / "workspace"
        self.workspace.mkdir()
        write(self.server / "private/installation/installed.json", b"installed")
        write(self.server / "private/installation/baseline.json", b"baseline")
        write(self.server / ".peanut/release-identity.json", b"release")
        write(self.server / ".env", b"DB_NAME=peanut\nDB_HOST=mysql\nDB_PORT=3306\nPEANUT_DATABASE_RESOURCE_ID=db-unit\nPEANUT_DATABASE_ENDPOINT_ID=mysql-unit\n")
        registry = {"resources": {"databases": [{"stable_resource_id": "db-unit", "database": "peanut",
                    "service_type": "mysql", "fallback": "none", "container_endpoint": {
                        "endpoint_id": "mysql-unit", "host": "mysql", "port": 3306}}]}}
        write(self.server / "private/resources/project-resources.json", json.dumps(registry).encode())
        plan = {"protocol": "peanut.server-update-plan.v1", "update_id": "unit-update",
                "source": {"identity_sha256": digest(self.server / ".peanut/release-identity.json"),
                           "installed_receipt_sha256": digest(self.server / "private/installation/installed.json"),
                           "baseline_sha256": digest(self.server / "private/installation/baseline.json")},
                "target": {"identity_sha256": "b" * 64}}
        write(self.workspace / "plan.json", json.dumps(plan).encode())
        write(self.workspace / "journal.json", json.dumps({"update_id": "unit-update",
              "plan_sha256": digest(self.workspace / "plan.json")}).encode())
        write(self.server / "runtime/upgrade/current-update.json", json.dumps({
              "update_id": "unit-update", "workspace": str(self.workspace)}).encode())
        write(self.server / "runtime/upgrade/maintenance.json", json.dumps({
              "update_id": "unit-update", "status": "active"}).encode())
        self.args = type("Args", (), {"server": self.server, "workspace": self.workspace})()

    def tearDown(self):
        self.temporary.cleanup()

    def test_real_instance_binding(self):
        self.assertEqual(recovery.instance(self.args)[-1]["resource_id"], "db-unit")

    def test_wrong_current_pointer_rejected(self):
        write(self.server / "runtime/upgrade/current-update.json", b'{"update_id":"other","workspace":"other"}')
        with self.assertRaisesRegex(ValueError, "pointer"):
            recovery.instance(self.args)

    def test_installed_identity_or_resource_change_rejected(self):
        write(self.server / "private/installation/installed.json", b"changed")
        with self.assertRaisesRegex(ValueError, "installed instance"):
            recovery.instance(self.args)
        write(self.server / "private/installation/installed.json", b"installed")
        write(self.server / ".env", b"DB_NAME=other\nDB_HOST=mysql\nDB_PORT=3306\nPEANUT_DATABASE_RESOURCE_ID=db-unit\nPEANUT_DATABASE_ENDPOINT_ID=mysql-unit\n")
        with self.assertRaisesRegex(ValueError, "registry"):
            recovery.instance(self.args)

    def test_dump_failure_never_publishes_recovery_receipt(self):
        write(self.server / "private/maintenance/update-verification.key", b"a" * 64 + b"\n")
        (self.server / "private/maintenance").chmod(0o700)
        write(self.server / "docker/.env", b"COMPOSE_PROJECT_NAME=peanut-unit\n")
        journal = json.loads((self.workspace / "journal.json").read_text())
        journal.update({"status": "applying", "operations": [{"status": "pending"}]})
        write(self.workspace / "journal.json", json.dumps(journal).encode())
        runtime = {"project": "peanut-unit", "images": {name: "sha256:" + name[0] * 64
                                                        for name in ("php", "nginx", "mysql")}}
        probe = {"server_uuid": "12345678-1234-1234-1234-123456789abc", "database": "peanut",
                 "charset": "utf8mb4", "collation": "utf8mb4_unicode_ci", "table_count": 1}
        with patch.object(recovery, "assert_stopped", return_value=runtime), \
             patch.object(recovery, "db_probe", return_value=probe), \
             patch.object(recovery, "compose", side_effect=RuntimeError("mysqldump exit 7")):
            with self.assertRaisesRegex(RuntimeError, "exit 7"):
                recovery.backup(self.args)
        self.assertFalse((self.workspace / "backup.json").exists())
        self.assertFalse((self.workspace / "backup.json.hmac").exists())

    def test_real_compose_mount_and_image_binding(self):
        write(self.server / "docker/.env", b"COMPOSE_PROJECT_NAME=peanut-unit\n")
        ids = {name: name[0] * 64 for name in ("php", "nginx", "mysql")}
        images = {name: "sha256:" + digit * 64 for name, digit in (("php", "a"), ("nginx", "b"), ("mysql", "c"))}
        wrong_mount = False
        writable_http = False

        def compose_stub(directory, *arguments, **unused):
            if arguments == ("ps", "--status", "running", "--services"):
                return "mysql"
            if arguments[:3] == ("ps", "-a", "-q"):
                return ids[arguments[3]]
            raise AssertionError(arguments)

        def docker_stub(*arguments, **unused):
            nonlocal wrong_mount, writable_http
            if arguments[0] == "ps" and "--filter" in arguments:
                if any("service=" in arg for arg in arguments):
                    name = next(name for name in ids if any(arg.endswith(f"service={name}") for arg in arguments))
                    return ids[name]
                return ids["mysql"]
            if arguments[0] == "inspect":
                ident = arguments[-1]
                name = next(name for name in ids if ids[name] == ident)
                destinations = {"php": (self.server, "/run/peanut-owner/server"),
                                "nginx": (self.server / "public", "/var/www/peanut-admin/server/public"),
                                "mysql": (self.server / "docker/mysql", "/var/lib/mysql")}
                source, target = destinations[name]
                return json.dumps({"Id": ident, "Image": images[name],
                    "State": {"Status": "running" if name == "mysql" else "exited"},
                    "Config": {"Labels": {"com.docker.compose.project": "peanut-unit",
                                           "com.docker.compose.service": name,
                                           "com.docker.compose.project.working_dir": str(self.server / "docker")}},
                    "Mounts": [{"Type": "bind", "Source": str(self.server / "other" if wrong_mount and name == "php" else source),
                                "Destination": target}] + ([{"Type": "bind", "Source": str(source),
                                    "Destination": "/var/www/peanut-http/server", "RW": writable_http}] if name == "php" else [])})
            raise AssertionError(arguments)

        with patch.object(recovery, "compose", side_effect=compose_stub), patch.object(recovery, "docker", side_effect=docker_stub):
            self.assertEqual(recovery.assert_stopped(self.server)["images"], images)
            writable_http = True
            with self.assertRaisesRegex(ValueError, "readonly program mount"):
                recovery.assert_stopped(self.server)
            writable_http = False
            wrong_mount = True
            with self.assertRaisesRegex(ValueError, "mount"):
                recovery.assert_stopped(self.server)

        write(self.server / "private/installation/update-verification.key", b"legacy-owner-input")
        with self.assertRaisesRegex(ValueError, "OWNER_MAINTENANCE_REQUIRED"):
            recovery.verification_key(self.server)


if __name__ == "__main__":
    unittest.main()
