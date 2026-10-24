#!/usr/bin/env python3
"""Exercise the real packager against disposable committed trees, without network."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

REPO = Path(__file__).resolve().parents[1]
SCRIPT = REPO / "scripts/package-plugin.py"
MAIN = b"<?php\n/*\n * Version: 1.21.4\n */\ndefine( 'DHC_VERSION', '1.21.4' );\n"


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="connector-package-test-")
        self.root = Path(self.temp.name)
        self.repo = self.root / "source"
        self.repo.mkdir()
        self.git("init", "-q")
        self.git("config", "user.name", "Package Fixture")
        self.git("config", "user.email", "fixture@example.invalid")
        self.product = {
            "dsquared-hub-connector.php": MAIN, "uninstall.php": b"<?php\n",
            "readme.txt": b"Stable tag: 1.21.4\n", "README.md": b"fixture\n",
            "CHANGELOG.md": b"fixture\n", "LICENSE": b"fixture\n",
            "includes/helper.php": b"<?php\n", "admin/admin.php": b"<?php\n",
            "assets/icon.bin": bytes(range(256)),
        }
        for name, data in {**self.product, "tests/excluded.php": b"fixture",
                           ".env": b"TEST_ONLY=excluded", "docs/excluded.md": b"fixture"}.items():
            self.write(name, data)
        self.commit = self.commit_all()

    def tearDown(self):
        self.temp.cleanup()

    def write(self, name, data):
        path = self.repo / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.repo)

    def commit_all(self):
        self.git("add", ".")
        self.git("-c", "core.hooksPath=/dev/null", "commit", "-qm", "fixture")
        return self.git("rev-parse", "HEAD").decode().strip()

    def run_build(self, folder="one", commit=None):
        env = {**os.environ, "TZ": "Pacific/Honolulu" if folder == "two" else "UTC"}
        return subprocess.run(["python3", str(SCRIPT), "--commit", commit or self.commit,
                               "--output-dir", str(self.root / folder)], cwd=self.repo,
                              env=env, capture_output=True, text=True, timeout=20)

    def test_committed_bytes_only_exact_manifest_and_reproducibility(self):
        self.write("includes/helper.php", b"dirty local file must not ship")
        self.write("assets/untracked.txt", b"untracked must not ship")
        for folder in ("one", "two"):
            result = self.run_build(folder)
            self.assertEqual(result.returncode, 0, result.stderr)
        first = self.root / "one/dsquared-hub-connector.zip"
        self.assertEqual(first.read_bytes(), (self.root / "two/dsquared-hub-connector.zip").read_bytes())
        manifest = json.loads((self.root / "one/manifest.json").read_text())
        self.assertEqual(manifest["commit"], self.commit)
        self.assertEqual(manifest["version"], "1.21.4")
        self.assertEqual(manifest["sha256"], hashlib.sha256(first.read_bytes()).hexdigest())
        expected = {"dsquared-hub-connector/" + name: data for name, data in self.product.items()}
        with zipfile.ZipFile(first) as package:
            self.assertEqual(set(package.namelist()), set(expected))
            self.assertIsNone(package.testzip())
            for item in manifest["files"]:
                data = package.read(item["path"])
                self.assertEqual(data, expected[item["path"]])
                self.assertEqual(item["sha256"], hashlib.sha256(data).hexdigest())
                self.assertEqual(item["bytes"], len(data))

    def test_refuses_artifact_replacement(self):
        self.assertEqual(self.run_build().returncode, 0)
        archive = self.root / "one/dsquared-hub-connector.zip"
        original = archive.read_bytes()
        result = self.run_build()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Refusing to overwrite", result.stderr)
        self.assertEqual(archive.read_bytes(), original)

    def test_requires_commit_not_mutable_ref_or_tree(self):
        for value in ("HEAD", "--all", self.commit[:7], self.git("rev-parse", "HEAD^{tree}").decode().strip()):
            with self.subTest(value=value):
                self.assertNotEqual(self.run_build(commit=value).returncode, 0)
                self.assertFalse((self.root / "one/dsquared-hub-connector.zip").exists())

    def test_refuses_version_mismatch(self):
        self.write("readme.txt", b"Stable tag: 1.21.3\n")
        result = self.run_build(commit=self.commit_all())
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("must agree", result.stderr)

    def test_refuses_missing_required_root(self):
        (self.repo / "LICENSE").unlink()
        result = self.run_build(commit=self.commit_all())
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("required package", result.stderr)

    def test_refuses_symlinks_inside_product_tree(self):
        (self.repo / "assets/link").symlink_to("../../outside")
        result = self.run_build(commit=self.commit_all())
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Unsupported package entry", result.stderr)

    def test_workflows_cannot_publish_or_replace_release_assets(self):
        release = (REPO / ".github/workflows/release.yml").read_text()
        contract = (REPO / ".github/workflows/connector-security.yml").read_text()
        self.assertIn("workflow_dispatch:", release)
        self.assertEqual(contract.count("ref: ${{ github.event.pull_request.head.sha || github.sha }}"), 2)
        self.assertIn('ref: ${{ github.sha }}', release)
        self.assertNotIn('ref: ${{ inputs.', release)
        self.assertIn('"$CANDIDATE_SHA" = "$WORKFLOW_SHA"', release)
        self.assertNotRegex(release, r"(?m)^  (push|release|pull_request_target):")
        for source in (release, contract):
            self.assertIn("contents: read", source)
            self.assertNotIn("contents: write", source)
            self.assertNotIn("gh release", source)
            self.assertNotIn("--clobber", source)
            self.assertNotIn("secrets.", source)
            self.assertIn("python3 scripts/package-plugin.py --commit", source)


if __name__ == "__main__":
    unittest.main()
