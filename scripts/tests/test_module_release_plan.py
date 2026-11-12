"""No-PHP/DB release change planning against fixed module source commits."""
import json
import pathlib
import runpy
import subprocess
import tempfile
import unittest

SCRIPT = pathlib.Path(__file__).resolve().parents[1] / "build-module-release-index"
PLAN = runpy.run_path(str(SCRIPT))["plan_source_changes"]


class ModuleReleasePlanTest(unittest.TestCase):
    def test_unchanged_then_changed_version_requires_new_identity(self):
        with tempfile.TemporaryDirectory(prefix="module-plan-test-") as temporary:
            base = pathlib.Path(temporary)
            root = base / "source"
            root.mkdir()

            def git(*args):
                return subprocess.check_output(["git", "-C", str(root), *args], text=True).strip()

            def write(relative, contents):
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(contents, encoding="utf8")

            def commit(label):
                git("add", ".")
                git("commit", "-qm", label)
                return git("rev-parse", "HEAD")

            def manifest(version):
                return json.dumps({
                    "key": "peanut.file", "version": version,
                    "frontend": {"entry": "web/src/modules/peanut-file/contribution.ts"},
                }) + "\n"

            git("init", "-q")
            git("config", "user.email", "test@example.invalid")
            git("config", "user.name", "Test")
            module = "server/app/modules/peanut/file"
            write(module + "/module.json", manifest("1.0.1"))
            write(module + "/service.php", "<?php return 'first';\n")
            write("web/src/modules/peanut-file/contribution.ts", "export const feature = 'one'\n")
            baseline = commit("baseline")
            index = base / "index.json"
            index.write_text(json.dumps({
                "protocol": "peanut.module-release-index.v1",
                "repository": "peanut-opensource/peanut-module-code",
                "commit": baseline, "tag": "v1.0.0-rc.1",
                "modules": [{"key": "peanut.file", "version": "1.0.1", "sha256": "a" * 64}],
            }), encoding="utf8")
            self.assertEqual(PLAN(root, baseline, index)["status"], "no_module_release_needed")

            write(module + "/service.php", "<?php return 'second';\n")
            changed = commit("change module without version")
            result = PLAN(root, changed, index)
            self.assertEqual(result["status"], "blocked")
            self.assertEqual(result["modules"][0]["status"], "version_bump_required")
            self.assertEqual(result["modules"][0]["changed_path_count"], 1)

            write(module + "/module.json", manifest("1.0.2"))
            bumped = commit("bump module version")
            result = PLAN(root, bumped, index)
            self.assertEqual(result["status"], "changes")
            self.assertEqual(result["modules"][0]["to"], "1.0.2")
            self.assertEqual(result["modules"][0]["changed_path_count"], 2)

            write("web/src/modules/peanut-file/contribution.ts", "export const feature = 'two'\n")
            frontend = commit("change frontend")
            result = PLAN(root, frontend, index)
            self.assertEqual(result["modules"][0]["status"], "changed")
            self.assertIn("web/src/modules/peanut-file/contribution.ts", result["modules"][0]["changed_paths"])


if __name__ == "__main__":
    unittest.main()
