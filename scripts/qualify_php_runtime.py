#!/usr/bin/env python3
"""Explicit wrapper qualification with an isolated Docker double; never runs Docker."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SOURCE = Path(__file__).resolve().parent.parent
DOCKER_DOUBLE = r'''#!/usr/bin/env python3
import json
import os
from pathlib import Path
import sys

state = Path(os.environ["DOUBLE_STATE"])
args = sys.argv[1:]
with (state / "calls.jsonl").open("a") as log:
    log.write(json.dumps(args) + "\n")
if args[0] == "build":
    if os.environ.get("BUILD_EXIT"):
        sys.exit(int(os.environ["BUILD_EXIT"]))
    # Without an explicit refresh the cached, obsolete base remains selected.
    if "--pull" in args:
        (state / "version").write_text(os.environ.get("UPSTREAM_VERSION", "8.5.12"))
    sys.exit(0)
if args[:2] != ["container", "run"]:
    sys.exit(99)
if "--entrypoint" in args:
    print((state / "version").read_text(), end="")
    sys.exit(int(os.environ.get("INSPECT_EXIT", "0")))
(state / "executed-version").write_text((state / "version").read_text())
sys.exit(int(os.environ.get("COMMAND_EXIT", "0")))
'''


class PhpRuntimeQualification(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix="access-control-php-runtime-")
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        (self.root / "bin").mkdir()
        for path in (SOURCE / "bin").iterdir():
            if path.is_file():
                shutil.copy2(path, self.root / "bin" / path.name)
        self.state = self.root / "state"
        self.state.mkdir()
        self.docker = self.root / "docker-double"
        self.docker.write_text(DOCKER_DOUBLE, encoding="utf-8")
        self.docker.chmod(0o755)

    def invoke(self, wrapper="build", arguments=(), **environment):
        for path in self.state.iterdir():
            path.unlink()
        (self.state / "version").write_text("8.5.7", encoding="utf-8")
        env = {**os.environ, "DOCKER_BIN": str(self.docker),
               "DOUBLE_STATE": str(self.state), **environment}
        result = subprocess.run(["bash", str(self.root / "bin" / wrapper), *arguments],
                                cwd=self.root.parent, env=env, text=True,
                                capture_output=True, check=False)
        calls = self.state / "calls.jsonl"
        self.calls = [json.loads(line) for line in calls.read_text().splitlines()] if calls.exists() else []
        return result

    def test_cached_old_base_is_refreshed_before_package_execution(self):
        result = self.invoke()
        self.assertEqual(0, result.returncode, result.stderr)
        executed = (self.state / "executed-version").read_text()
        print(f"Cached 8.5.7 / upstream 8.5.12: executed PHP {executed}; calls={self.calls}")
        self.assertEqual("8.5.12", executed,
                         "Wrapper executed package work on cached PHP 8.5.7 instead of refreshing")
        self.assertIn("--pull", self.calls[0])
        self.assertEqual(3, len(self.calls), self.calls)
        self.assertIn("--entrypoint", self.calls[1])
        self.assertIn("composer update", self.calls[2][-1])

    def assert_admitted(self, result, image="fight-access-control"):
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(3, len(self.calls), self.calls)
        build, inspect, command = self.calls
        self.assertEqual(["build", "--pull", "-t", image, "./etc/docker/"], build)
        self.assertEqual(["container", "run"], inspect[:2])
        self.assertEqual(image, inspect[inspect.index("--entrypoint") + 2])
        self.assertNotIn("-v", inspect, "Admission must not mount the repository")
        self.assertIn(image, command)
        self.assertIn("-v", command)
        self.assertIn(f"{self.root}:/app:delegated", command)
        self.assertEqual("/app", command[command.index("-w") + 1])
        self.assertEqual(f"{os.getuid()}:{os.getgid()}", command[command.index("--user") + 1])

    def test_supported_patch_boundaries_for_both_entry_points(self):
        for wrapper in ("build", ".container-run"):
            for version in ("8.5.12", "8.5.13", "8.5.99", "8.5.100"):
                with self.subTest(wrapper=wrapper, version=version):
                    result = self.invoke(wrapper, UPSTREAM_VERSION=version)
                    self.assert_admitted(result)
                    self.assertEqual(version, (self.state / "executed-version").read_text())
                    self.assertIn(version, result.stdout)

    def test_obsolete_unsupported_and_malformed_versions_stop_package_work(self):
        versions = ("8.5.0", "8.5.7", "8.5.11", "8.4.99", "8.6.0", "9.0.0",
                    "8.5.12RC1", "8.5.12-dev", "8.5.012", "", "not PHP",
                    "PHP 8.5.12", "8.5.12\nnoise", " 8.5.12", "8.5.12 ")
        for wrapper in ("build", ".container-run"):
            for version in versions:
                with self.subTest(wrapper=wrapper, version=version):
                    result = self.invoke(wrapper, UPSTREAM_VERSION=version)
                    self.assertNotEqual(0, result.returncode)
                    self.assertIn("require stable PHP 8.5.12", result.stderr)
                    self.assertEqual(2, len(self.calls))
                    self.assertFalse((self.state / "executed-version").exists())

    def test_refresh_failure_never_falls_back_to_cached_image(self):
        for wrapper in ("build", ".container-run"):
            with self.subTest(wrapper=wrapper):
                result = self.invoke(wrapper, BUILD_EXIT="42")
                self.assertNotEqual(0, result.returncode)
                self.assertIn("Docker/registry access", result.stderr)
                self.assertIn("Cached fallback is disabled", result.stderr)
                self.assertEqual(1, len(self.calls))
                self.assertFalse((self.state / "executed-version").exists())

    def test_failed_inspection_stops_even_with_patched_output_available(self):
        for wrapper in ("build", ".container-run"):
            with self.subTest(wrapper=wrapper):
                result = self.invoke(wrapper, INSPECT_EXIT="43")
                self.assertNotEqual(0, result.returncode)
                self.assertIn("Cannot inspect actual PHP version", result.stderr)
                self.assertEqual(2, len(self.calls))
                self.assertFalse((self.state / "executed-version").exists())

    def test_image_overrides_forwarding_and_command_exit_status(self):
        arguments = ("printf", "%s\\n", "two words", "", "*", "--flag=quoted value")
        for wrapper in ("build", ".container-run", "exec"):
            with self.subTest(wrapper=wrapper):
                result = self.invoke(wrapper, arguments if wrapper != "build" else ("--latest",),
                                     FIGHT_ACCESS_CONTROL_PHP_IMAGE="custom/php:task-83")
                self.assert_admitted(result, image="custom/php:task-83")
                command = self.calls[-1]
                if wrapper == "build":
                    self.assertNotIn("-it", command)
                    self.assertEqual(["bash", "-c"], command[-3:-1])
                    self.assertIn("composer update --no-interaction --no-progress --prefer-dist", command[-1])
                    self.assertTrue(command[-1].endswith("&& ./bin/quality"))
                else:
                    self.assertIn("-it", command)
                    self.assertEqual(list(arguments), command[-len(arguments):])
                result = self.invoke(wrapper, COMMAND_EXIT="37")
                self.assertEqual(37, result.returncode, result.stderr)
                self.assertEqual(3, len(self.calls))
                result = self.invoke(wrapper, FIGHT_ACCESS_CONTROL_PHP_IMAGE="custom/php:task-83",
                                     UPSTREAM_VERSION="8.5.7")
                self.assertNotEqual(0, result.returncode)
                self.assertFalse((self.state / "executed-version").exists())

    def test_invalid_build_arguments_do_not_touch_docker(self):
        for arguments in (("--unknown",), ("--latest", "extra")):
            result = self.invoke(arguments=arguments)
            self.assertEqual(2, result.returncode)
            self.assertEqual([], self.calls)

    def test_build_preserves_read_only_linked_worktree_metadata(self):
        def git(*args):
            return subprocess.run(["git", "-C", str(self.root), *args], check=True,
                                  text=True, capture_output=True).stdout.strip()

        git("init", "-q")
        git("add", "bin")
        git("-c", "user.name=Runtime qualification", "-c", "user.email=runtime@example.invalid",
            "-c", "commit.gpgsign=false", "commit", "-qm", "Fixture")
        primary = self.root
        linked = self.root / "linked checkout"
        git("worktree", "add", "-q", "-b", "fixture-worktree", str(linked))
        common = git("rev-parse", "--path-format=absolute", "--git-common-dir")
        metadata = subprocess.run(["git", "-C", str(linked), "rev-parse", "--absolute-git-dir"],
                                  check=True, capture_output=True, text=True).stdout.strip()
        self.root = linked
        try:
            result = self.invoke()
            self.assert_admitted(result)
            self.assertIn(f"{common}:{common}:ro", self.calls[-1])
            self.assertIn(f"{metadata}:{metadata}:ro", self.calls[-1])
        finally:
            self.root = primary


if __name__ == "__main__":
    unittest.main(verbosity=2)
