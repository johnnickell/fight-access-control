#!/usr/bin/env python3
"""Explicit release-tooling qualification; not part of the product suite or CI gate."""

from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

from check_release_docs import validate


SOURCE = Path(__file__).resolve().parent.parent
REPOSITORY = "https://github.com/johnnickell/fight-access-control"


class ReleaseDocsQualification(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix="access-control-release-docs-")
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        (self.root / "docs").mkdir()
        self.fixture()

    def fixture(self, version="0.7.0", status="published"):
        (self.root / "README.md").write_text(
            f'''# Package
<!-- release:start -->
**Release: [v{version}]({REPOSITORY}/releases/tag/v{version})**
**Status: {status}.**
Requires **PHP 8.5+** and **Fight Common ^1.3**.
```bash
composer require johnnickell/fight-access-control:"~{version}"
```
[Release overview](docs/release-{version}.md)
[Tagged documentation]({REPOSITORY}/tree/v{version}/docs)
<!-- release:end -->
Historical v0.1.0 and independent protocol version 2 remain unchanged.
''', encoding="utf-8")
        (self.root / "CHANGELOG.md").write_text(
            f'''# Changelog
## [Unreleased]
## [{version}] - 2026-10-20
### Changed
- Current contract.
## [0.6.0] - 2026-10-19
- Historical contract.
[Unreleased]: {REPOSITORY}/compare/v{version}...HEAD
[{version}]: {REPOSITORY}/compare/v0.6.0...v{version}
[0.6.0]: {REPOSITORY}/compare/v0.5.0...v0.6.0
''', encoding="utf-8")
        (self.root / f"docs/release-{version}.md").write_text(
            f"# v{version} release and integration overview\n\nPrevious releases retain their history.\n",
            encoding="utf-8")
        (self.root / "composer.json").write_text(
            '{"name":"johnnickell/fight-access-control",'
            '"require":{"php":">=8.5","johnnickell/fight-common":"^1.3"}}\n', encoding="utf-8")

    def replace(self, name, old, new):
        path = self.root / name
        contents = path.read_text(encoding="utf-8")
        self.assertIn(old, contents)
        path.write_text(contents.replace(old, new), encoding="utf-8")

    def snapshot(self):
        return {str(path.relative_to(self.root)): path.read_bytes()
                for path in self.root.rglob("*") if path.is_file()}

    def install_wrapper(self):
        (self.root / "bin").mkdir()
        (self.root / "scripts").mkdir()
        for relative in ("bin/release", "scripts/check_release_docs.py"):
            shutil.copy2(SOURCE / relative, self.root / relative)

    def run_wrapper(self, action, version):
        return subprocess.run(["bash", "bin/release", action, version], cwd=self.root,
                              text=True, capture_output=True, check=False)

    def test_published_and_prepared_metadata_are_read_only(self):
        for version, status in [("0.7.0", "published"), ("0.8.0", "prepared (not yet published)")]:
            with self.subTest(version=version):
                self.fixture(version, status)
                before = self.snapshot()
                self.assertEqual([], validate(self.root, version))
                self.assertEqual(before, self.snapshot())

    def test_stale_or_malformed_metadata_rejects(self):
        cases = [
            ("README.md", "[v0.7.0]", "[v0.6.0]", "release label/tag"),
            ("README.md", "releases/tag/v0.7.0", "releases/tag/v0.6.0", "release label/tag"),
            ("README.md", '"~0.7.0"', '"~0.6.0"', "install command"),
            ("README.md", "release-0.7.0.md", "release-0.6.0.md", "release overview"),
            ("README.md", "tree/v0.7.0/docs", "tree/v0.6.0/docs", "tagged documentation"),
            ("README.md", "**Status: published.**", "**Status: ready.**", "status must"),
            ("README.md", "<!-- release:end -->", "", "release:start/release:end"),
            ("README.md", "<!-- release:start -->", "<!-- release:start -->\n<!-- release:start -->", "release:start/release:end"),
            ("README.md", "**PHP 8.5+**", "**PHP 8.4+**", "runtime requirements"),
            ("README.md", "**Fight Common ^1.3**", "**Fight Common ^1.2**", "runtime requirements"),
            ("CHANGELOG.md", "## [Unreleased]", "## [0.8.0] - 2026-10-21", "first and unique"),
            ("CHANGELOG.md", "## [0.7.0] - 2026-10-20", "## [0.7.0] - 2026-02-30", "valid YYYY-MM-DD"),
            ("CHANGELOG.md", "## [0.6.0] - 2026-10-19", "## [0.7.0] - 2026-10-19", "first and unique"),
            ("CHANGELOG.md", "compare/v0.7.0...HEAD", "compare/v0.6.0...HEAD", "[Unreleased] link"),
            ("CHANGELOG.md", "compare/v0.6.0...v0.7.0", "compare/v0.5.0...v0.7.0", "[0.7.0] link"),
            ("docs/release-0.7.0.md", "# v0.7.0", "# v0.6.0", "overview title"),
            ("composer.json", '{"name":', '{"version":"0.7.0","name":', "omit version"),
            ("composer.json", '{"name":', '{"name"', "invalid JSON"),
        ]
        for name, old, new, diagnostic in cases:
            with self.subTest(file=name, change=new):
                self.fixture()
                self.replace(name, old, new)
                before = self.snapshot()
                self.assertIn(diagnostic, "\n".join(validate(self.root, "0.7.0")))
                self.assertEqual(before, self.snapshot())

    def test_missing_inputs_reject(self):
        for name in ("README.md", "CHANGELOG.md", "composer.json", "docs/release-0.7.0.md"):
            with self.subTest(file=name):
                self.fixture()
                (self.root / name).unlink()
                errors = validate(self.root, "0.7.0")
                self.assertTrue(any(name in error and "cannot read" in error for error in errors))

    def test_invalid_versions_and_older_candidate_reject(self):
        for version in ("v0.7.0", "00.7.0", "0.7", "0.7.0-rc.1", "../0.7.0", "０.7.0"):
            with self.subTest(version=version):
                self.assertIn("Version must be", "\n".join(validate(self.root, version)))
        self.assertIn("first and unique", "\n".join(validate(self.root, "0.6.0")))

    def test_cli_checks_working_tree_without_git_or_mutation(self):
        self.install_wrapper()
        before = self.snapshot()
        result = self.run_wrapper("check-docs", "0.7.0")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("publication is not verified", result.stdout)
        self.assertEqual(before, self.snapshot())
        result = self.run_wrapper("check-docs", "0.8.0")
        self.assertEqual(3, result.returncode, result.stdout + result.stderr)
        self.assertEqual(before, self.snapshot())
        self.assertEqual(2, self.run_wrapper("unknown", "0.7.0").returncode)
        self.assertEqual(2, self.run_wrapper("check-docs", "v0.7.0").returncode)

    def test_certification_rejects_drift_before_expensive_gates(self):
        self.install_wrapper()
        self.replace("README.md", '"~0.7.0"', '"~0.6.0"')
        subprocess.run(["git", "init", "-q"], cwd=self.root, check=True)
        subprocess.run(["git", "add", "."], cwd=self.root, check=True)
        subprocess.run(["git", "-c", "user.name=Release qualification", "-c",
                        "user.email=qualification@example.invalid", "-c", "commit.gpgsign=false",
                        "commit", "-qm", "Fixture"], cwd=self.root, check=True)
        result = self.run_wrapper("certify", "0.7.0")
        self.assertEqual(3, result.returncode, result.stdout + result.stderr)
        logs = list(self.root.glob(".runs/release-*/release-docs.log"))
        self.assertEqual(1, len(logs))
        self.assertIn("install command", logs[0].read_text(encoding="utf-8"))
        self.assertFalse(list(self.root.glob(".runs/release-*/build.log")))
        self.assertFalse(list(self.root.glob(".runs/release-*/certification.json")))


if __name__ == "__main__":
    unittest.main(verbosity=2)
