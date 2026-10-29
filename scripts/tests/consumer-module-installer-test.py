#!/usr/bin/env python3
"""Real fixed-installer extraction checks; owned files only, no database/network."""
from __future__ import annotations

import hashlib
import importlib.machinery
import importlib.util
import io
import tarfile
import tempfile
import unittest
from types import SimpleNamespace
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader(
    "consumer_module_reference_chain", str(ROOT / "scripts/consumer-module-reference-chain")
)
spec = importlib.util.spec_from_loader(loader.name, loader)
module = importlib.util.module_from_spec(spec)
loader.exec_module(module)


class FixedInstallerArchiveTest(unittest.TestCase):
    def setUp(self):
        owned = ROOT / ".local/tmp/consumer-installer-contract"
        owned.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=owned)
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.package = self.root / "installer.tar.gz"
        self.target = self.root / "application-b"
        self.manifest = b'{"schema_version":1,"fixture":true}\n'
        self.core = b"fixture core zip bytes"

    def archive(self, entries=None):
        if entries is None:
            entries = [
                ("product/.peanut/application-manifest.json", self.manifest),
                ("product/packages/core-php/core.zip", self.core),
            ]
        with tarfile.open(self.package, "w:gz") as archive:
            for name, content in entries:
                member = tarfile.TarInfo(name)
                if isinstance(content, tuple):
                    member.type, member.linkname = content
                    archive.addfile(member)
                else:
                    member.size = len(content)
                    member.mode = 0o644
                    archive.addfile(member, io.BytesIO(content))
        return {
            "archive": {
                "format": "tar.gz",
                "root": "product",
                "sha256": hashlib.sha256(self.package.read_bytes()).hexdigest(),
            },
            "application": {"manifest_sha256": hashlib.sha256(self.manifest).hexdigest()},
        }

    def reject(self, artifact, message):
        with self.assertRaisesRegex(module.ChainError, message):
            module.extract_installer(self.package, artifact, self.target)
        self.assertFalse(any(self.root.glob(".application-b.extract-*")))

    def test_exact_archive_installs_same_bytes_for_two_independent_consumers(self):
        artifact = self.archive()
        first = self.root / "application-a"
        module.extract_installer(self.package, artifact, first)
        module.extract_installer(self.package, artifact, self.target)
        for target in [first, self.target]:
            self.assertEqual((target / ".peanut/application-manifest.json").read_bytes(), self.manifest)
            self.assertFalse(target.is_symlink())
        self.assertFalse(any(self.root.glob(".*.extract-*")))

    def test_wrong_archive_digest_is_rejected(self):
        artifact = self.archive()
        artifact["archive"]["sha256"] = "0" * 64
        self.reject(artifact, "archive digest differs")
        self.assertFalse(self.target.exists())

    def test_wrong_application_manifest_digest_is_rejected(self):
        artifact = self.archive()
        artifact["application"]["manifest_sha256"] = "0" * 64
        self.reject(artifact, "application manifest digest is invalid")

    def test_parent_path_archive_root_is_rejected(self):
        artifact = self.archive()
        artifact["archive"]["root"] = "../product"
        self.reject(artifact, "archive root is invalid")
        self.assertFalse(self.target.exists())

    def test_parent_traversal_member_is_rejected_before_extraction(self):
        artifact = self.archive([("product/../escaped.txt", b"not extracted")])
        self.reject(artifact, "unsafe member")
        self.assertFalse((self.root / "escaped.txt").exists())
        self.assertFalse(self.target.exists())

    def test_symbolic_link_member_is_rejected(self):
        artifact = self.archive([("product/link", (tarfile.SYMTYPE, "safe-looking-file"))])
        self.reject(artifact, "unsafe member")
        self.assertFalse(self.target.exists())

    def test_hard_link_member_is_rejected(self):
        artifact = self.archive([("product/link", (tarfile.LNKTYPE, "product/file"))])
        self.reject(artifact, "unsafe member")
        self.assertFalse(self.target.exists())

    def test_empty_archive_is_rejected(self):
        artifact = self.archive([])
        self.reject(artifact, "archive is empty")
        self.assertFalse(self.target.exists())

    def test_undeclared_root_is_rejected(self):
        artifact = self.archive([("other/.peanut/application-manifest.json", self.manifest)])
        self.reject(artifact, "unsafe member")
        self.assertFalse(self.target.exists())

    def artifact_manifest(self, protocol):
        archive = self.archive()
        archive.update({
            "schema_version": 1,
            "protocol": protocol,
            "edition": {"name": "multi-tenant"},
            "source": {"commit": "a" * 40, "tree": "b" * 40},
            "formal_release": {"eligible": protocol == "peanut.edition-installer.v1"},
        })
        archive["archive"]["filename"] = self.package.name
        if protocol == "peanut.internal-edition-candidate.v1":
            archive.update({
                "scope": "internal-candidate",
                "status": "internal-build-input-not-formal-release",
                "core_php_archive": {
                    "package": "peanut-admin/core",
                    "path_in_archive": "packages/core-php/core.zip",
                    "sha256": hashlib.sha256(self.core).hexdigest(),
                    "sha1": hashlib.sha1(self.core).hexdigest(),
                    "source_reference": "d" * 40,
                },
            })
        return archive

    def validate_args(self, mode, manifest_path):
        return SimpleNamespace(
            candidate="a" * 40,
            edition="multi-tenant",
            installer_mode=mode,
            installer_sha256=hashlib.sha256(self.package.read_bytes()).hexdigest(),
            installer_manifest_sha256=hashlib.sha256(manifest_path.read_bytes()).hexdigest(),
        )

    def test_formal_mode_rejects_internal_protocol(self):
        artifact = self.artifact_manifest("peanut.internal-edition-candidate.v1")
        manifest = self.root / "internal.manifest.json"
        manifest.write_text("{}", encoding="utf-8")
        with self.assertRaisesRegex(module.ChainError, "fixed Edition installer identity"):
            module.validate_installer_artifact(
                self.validate_args("formal", manifest),
                "b" * 40,
                self.package,
                manifest,
                artifact,
            )

    def test_internal_mode_requires_internal_protocol_and_core_identity(self):
        artifact = self.artifact_manifest("peanut.internal-edition-candidate.v1")
        manifest = self.root / "internal.manifest.json"
        manifest.write_text("{}", encoding="utf-8")
        result = module.validate_installer_artifact(
            self.validate_args("internal-candidate", manifest),
            "b" * 40,
            self.package,
            manifest,
            artifact,
        )
        self.assertEqual(result["mode"], "internal-candidate")
        self.assertEqual(result["protocol"], "peanut.internal-edition-candidate.v1")
        self.assertFalse(result["formal_release_eligible"])

    def test_internal_mode_rejects_formal_protocol(self):
        artifact = self.artifact_manifest("peanut.edition-installer.v1")
        manifest = self.root / "formal.manifest.json"
        manifest.write_text("{}", encoding="utf-8")
        with self.assertRaisesRegex(module.ChainError, "internal Edition candidate identity"):
            module.validate_installer_artifact(
                self.validate_args("internal-candidate", manifest),
                "b" * 40,
                self.package,
                manifest,
                artifact,
            )

    def test_internal_wrong_source_is_rejected(self):
        artifact = self.artifact_manifest("peanut.internal-edition-candidate.v1")
        artifact["source"]["commit"] = "c" * 40
        manifest = self.root / "wrong-source.json"
        manifest.write_text("{}")
        with self.assertRaisesRegex(module.ChainError, "internal Edition candidate identity"):
            module.validate_installer_artifact(self.validate_args("internal-candidate", manifest), "b" * 40, self.package, manifest, artifact)

    def test_internal_wrong_edition_is_rejected(self):
        artifact = self.artifact_manifest("peanut.internal-edition-candidate.v1")
        artifact["edition"]["name"] = "standalone"
        manifest = self.root / "wrong-edition.json"
        manifest.write_text("{}")
        with self.assertRaisesRegex(module.ChainError, "internal Edition candidate identity"):
            module.validate_installer_artifact(self.validate_args("internal-candidate", manifest), "b" * 40, self.package, manifest, artifact)

    def test_internal_mode_rejects_tampered_core_digest(self):
        artifact = self.artifact_manifest("peanut.internal-edition-candidate.v1")
        artifact["core_php_archive"]["sha256"] = "0" * 64
        manifest = self.root / "internal.manifest.json"
        manifest.write_text("{}", encoding="utf-8")
        with self.assertRaisesRegex(module.ChainError, "Core archive digest differs"):
            module.validate_installer_artifact(
                self.validate_args("internal-candidate", manifest),
                "b" * 40,
                self.package,
                manifest,
                artifact,
            )


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(FixedInstallerArchiveTest)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    if not result.wasSuccessful():
        raise SystemExit(1)
    print(f"CONSUMER-INSTALLER-ARCHIVE-001 passed ({result.testsRun} cases)")
