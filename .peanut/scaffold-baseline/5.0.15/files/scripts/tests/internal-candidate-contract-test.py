#!/usr/bin/env python3
"""Internal projection integrity fixtures; native Composer installation is separate."""
from __future__ import annotations

import hashlib
import importlib.machinery
import importlib.util
import json
import runpy
import subprocess
import tarfile
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
api = runpy.run_path(str(ROOT / "scripts/internal_candidate_contract.py"))
loader = importlib.machinery.SourceFileLoader("candidate_consumer", str(ROOT / "scripts/consumer-module-reference-chain"))
spec = importlib.util.spec_from_loader(loader.name, loader)
consumer = importlib.util.module_from_spec(spec)
loader.exec_module(consumer)


class InternalCandidateTest(unittest.TestCase):
    def setUp(self):
        parent = ROOT / ".local/tmp/internal-edition-candidate-20260928"
        parent.mkdir(parents=True, exist_ok=True)
        temp = tempfile.TemporaryDirectory(dir=parent)
        self.addCleanup(temp.cleanup)
        self.work = Path(temp.name)
        self.root = self.work / "application"
        self.root.mkdir()
        self.core_path = "packages/core-php/core.zip"
        self.core_bytes = b"Synthetic integrity fixture, not an installable package"
        self.core = {"package": "peanut-admin/core", "resolved_version": "4.0.0-rc.1", "constraint": "4.0.0-rc.1",
                     "source_reference": "c" * 40, "path_in_archive": self.core_path,
                     "sha256": hashlib.sha256(self.core_bytes).hexdigest(), "sha1": hashlib.sha1(self.core_bytes).hexdigest()}
        self.write("server/composer.json", {"require": {"peanut-admin/core": "dev-dev"}})
        self.write("server/composer.lock", {"packages": []})
        self.write("server/app/modules/official/identity/composer.json", {"require": {"peanut-admin/core": "dev-dev"}})
        for path in ["server/runtime/.gitkeep", "server/public/storage/.gitkeep", "server/private/storage/.gitkeep",
                     ".peanut/scaffold-baseline/4.0.0-dev/files/server/runtime/.gitkeep"]:
            self.write(path, b"")
        rows = [{"path": name, **value} for name, value in api["tree"](self.root).items()]
        self.write(".peanut/application-manifest.json", {"generation_source": {"commit": "a" * 40, "tree": "b" * 40},
                                                       "edition": {"name": "multi-tenant"}, "files": rows})
        self.before = self.work / "before.json"
        self.before.write_text(json.dumps(api["tree"](self.root)))
        self.descriptor = self.work / "core.json"
        self.descriptor.write_text(json.dumps(self.core))
        self.write(self.core_path, self.core_bytes)
        self.write("server/composer.json", {"require": {"peanut-admin/core": self.core["resolved_version"]}})
        self.write("server/app/modules/official/identity/composer.json", {"require": {"peanut-admin/core": self.core["resolved_version"]}})
        self.write("server/composer.lock", {"packages": [{"name": self.core["package"], "version": self.core["resolved_version"],
            "source": {"reference": self.core["source_reference"]}, "dist": {"reference": self.core["source_reference"],
            "url": "../" + self.core_path, "shasum": self.core["sha1"]}}]})

    def write(self, name, value):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(value if isinstance(value, bytes) else (json.dumps(value) + "\n").encode())
        path.chmod(0o644)

    def seal(self):
        return api["seal"](self.root, self.before, self.descriptor)

    def test_valid_projection_preserves_original_and_all_placeholders(self):
        result = self.seal()
        self.assertEqual(result["status"], "verified-internal-candidate")
        self.assertEqual(len(result["projection"]["changes"]), 3)
        self.assertEqual(len(result["nested_core_manifests"]), 1)
        self.assertEqual(api["verify"](self.root), result)
        self.assertTrue((self.root / "server/runtime/.gitkeep").is_file())

    def test_removal_of_source_placeholder_is_rejected(self):
        (self.root / "server/runtime/.gitkeep").unlink()
        with self.assertRaisesRegex(ValueError, "SOURCE_REMOVAL"):
            self.seal()

    def test_changing_original_baseline_is_rejected(self):
        self.write(".peanut/scaffold-baseline/4.0.0-dev/files/server/runtime/.gitkeep", b"changed")
        with self.assertRaisesRegex(ValueError, "UNEXPECTED_PROJECTION"):
            self.seal()

    def test_extra_source_is_rejected(self):
        self.write("extra.php", b"<?php")
        with self.assertRaisesRegex(ValueError, "UNEXPECTED_ADDITION"):
            self.seal()

    def test_runtime_secret_is_rejected(self):
        self.write("server/.env", b"fixture=only")
        with self.assertRaisesRegex(ValueError, "FORBIDDEN_SOURCE"):
            self.seal()

    def test_nested_dependency_mismatch_is_rejected(self):
        self.write("server/app/modules/official/identity/composer.json", {"require": {"peanut-admin/core": "dev-dev"}})
        with self.assertRaisesRegex(ValueError, "NESTED_CORE_REQUIRE"):
            self.seal()

    def test_archive_content_mutation_is_rejected(self):
        self.seal()
        self.write("server/composer.json", {"require": {"peanut-admin/core": "999.0.0"}})
        with self.assertRaisesRegex(ValueError, "PROJECTED_TREE"):
            api["verify"](self.root)

    def test_core_bytes_mutation_is_rejected(self):
        self.seal()
        self.write(self.core_path, b"tampered")
        with self.assertRaisesRegex(ValueError, "CORE_DIGEST"):
            api["verify"](self.root)

    def test_symlink_is_rejected(self):
        target = self.root / "server/link.php"
        target.symlink_to(self.root / "server/composer.json")
        with self.assertRaisesRegex(ValueError, "SYMLINK"):
            self.seal()

    def test_hardlink_is_rejected(self):
        import os
        os.link(self.root / "server/composer.json", self.root / "server/alias.json")
        with self.assertRaisesRegex(ValueError, "FILE_INVALID"):
            self.seal()

    def test_external_marker_mismatch_is_rejected(self):
        sealed = self.seal()
        artifact = {"protocol": api["PROTOCOL"], "scope": "internal-candidate", "candidate_marker": sealed["candidate_marker"],
                    "projection": sealed["projection"], "core_php_archive": self.core,
                    "source": {"commit": "a" * 40, "tree": "b" * 40}, "edition": {"name": "multi-tenant"}}
        self.assertEqual(api["verify"](self.root, artifact)["status"], "verified-internal-candidate")
        artifact["candidate_marker"] = {"path": api["MARKER"], "sha256": "0" * 64}
        with self.assertRaisesRegex(ValueError, "EXTERNAL_MARKER"):
            api["verify"](self.root, artifact)

    def test_builder_native_path_guards(self):
        source = (ROOT / "scripts/build-internal-edition-candidate").read_text()
        guard = source[source.index("function internalCandidatePath("):source.index("/** @return array<string,mixed> */\nfunction internalCandidateReadJson")]
        probe = self.work / "native-path-guards.php"
        probe.write_text("<?php\ndeclare(strict_types=1);\n" + guard + r"""
$root = $argv[1]; $file = $root . '/receipt.json';
file_put_contents($file, '{}');
internalCandidatePath($file, false, true);
internalCandidatePath($root . '/absent/target', true);
$checks = 2;
$reject = static function (callable $operation, string $expected) use (&$checks): void {
    try { $operation(); }
    catch (RuntimeException $error) {
        if ($error->getMessage() !== $expected) throw $error;
        ++$checks; return;
    }
    throw new RuntimeException('expected rejection was missing');
};
$reject(fn() => internalCandidatePath($root . '/../alias', true), 'INTERNAL_CANDIDATE_PATH_INVALID');
$reject(fn() => internalCandidatePath($root . '/missing'), 'INTERNAL_CANDIDATE_PATH_MISSING');
symlink($root, $root . '/parent-link');
$reject(fn() => internalCandidatePath($root . '/parent-link/out', true), 'INTERNAL_CANDIDATE_PATH_LINK_REJECTED');
symlink($file, $root . '/file-link');
$reject(fn() => internalCandidatePath($root . '/file-link', false, true), 'INTERNAL_CANDIDATE_PATH_LINK_REJECTED');
link($file, $root . '/hard-link');
$reject(fn() => internalCandidatePath($root . '/hard-link', false, true), 'INTERNAL_CANDIDATE_INPUT_FILE_INVALID');
echo json_encode(['checks'=>$checks], JSON_THROW_ON_ERROR), PHP_EOL;
""")
        result = subprocess.run([str(consumer.PHP), str(probe), str(self.work)], capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, '')
        self.assertEqual(json.loads(result.stdout)['checks'], 7)

    def test_builder_rejects_existing_output_without_touching_it(self):
        retained = self.work / 'retained.txt'
        retained.write_text('do not replace')
        result = subprocess.run([
            str(consumer.PHP), str(ROOT / 'scripts/build-internal-edition-candidate'),
            '--source-commit=' + 'a' * 40, '--edition=multi-tenant',
            '--core-inputs=' + str(self.work / 'not-needed.json'), '--output=' + str(self.work),
        ], capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 64)
        self.assertEqual(retained.read_text(), 'do not replace')

    def test_formal_retagging_is_rejected_before_extraction_promotion(self):
        self.seal()
        package = self.work / "fixture.tar.gz"
        with tarfile.open(package, "w:gz") as archive:
            archive.add(self.root, arcname="product")
        artifact = {"protocol": "peanut.edition-installer.v1", "archive": {"root": "product", "format": "tar.gz",
            "sha256": api["digest"](package)}, "application": {"manifest_sha256": api["digest"](self.root / ".peanut/application-manifest.json")}}
        target = self.work / "consumer"
        with self.assertRaisesRegex(consumer.ChainError, "internal candidate marker"):
            consumer.extract_installer(package, artifact, target)
        self.assertFalse(target.exists())


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(InternalCandidateTest)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    if not result.wasSuccessful():
        raise SystemExit(1)
    print(f"INTERNAL-CANDIDATE-PROJECTION-001 passed ({result.testsRun} cases)")
