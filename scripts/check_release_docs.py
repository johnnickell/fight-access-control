#!/usr/bin/env python3
"""Read-only release metadata preflight; historical and protocol versions are not rewritten."""

import argparse
import datetime
import json
from pathlib import Path
import re


VERSION_PATTERN = r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"
REPOSITORY = "https://github.com/johnnickell/fight-access-control"
PACKAGE = "johnnickell/fight-access-control"


def validate(root: Path, version: str) -> list[str]:
    errors = []
    if re.fullmatch(VERSION_PATTERN, version) is None:
        return ["Version must be X.Y.Z without a v prefix or leading zeroes."]

    def read(relative: str) -> str:
        try:
            return (root / relative).read_text(encoding="utf-8")
        except (OSError, UnicodeError) as error:
            errors.append(f"{relative}: cannot read release metadata ({error}).")
            return ""

    def require(condition: bool, message: str) -> None:
        if not condition:
            errors.append(message)

    readme = read("README.md")
    start, end = "<!-- release:start -->", "<!-- release:end -->"
    require(
        readme.count(start) == 1 and readme.count(end) == 1,
        "README.md: exactly one release:start/release:end block is required.",
    )
    panel_match = re.search(re.escape(start) + r"(.*?)" + re.escape(end), readme, re.DOTALL)
    panel = panel_match.group(1) if panel_match else ""
    release = f"**Release: [v{version}]({REPOSITORY}/releases/tag/v{version})**"
    require(panel.count(release) == 1, f"README.md: release label/tag URL must identify v{version}.")
    statuses = re.findall(r"^\*\*Status: (.+)\.\*\*$", panel, re.MULTILINE)
    require(
        len(statuses) == 1 and statuses[0] in ("published", "prepared (not yet published)"),
        "README.md: status must be published or prepared (not yet published); this check verifies neither claim.",
    )
    commands = re.findall(r"^composer require .+$", readme, re.MULTILINE)
    require(
        commands == [f'composer require {PACKAGE}:"~{version}"']
        and commands[0] in panel,
        f'README.md: the sole install command must be composer require {PACKAGE}:"~{version}" in the release block.',
    )
    require(
        panel.count(f"[Release overview](docs/release-{version}.md)") == 1,
        f"README.md: release overview must link to docs/release-{version}.md.",
    )
    require(
        panel.count(f"[Tagged documentation]({REPOSITORY}/tree/v{version}/docs)") == 1,
        f"README.md: tagged documentation must identify v{version}.",
    )
    require(
        set(re.findall(r"\bv(" + VERSION_PATTERN + r")\b", panel)) == {version},
        "README.md: release block contains a missing or inconsistent version.",
    )

    try:
        composer = json.loads(read("composer.json"))
        require(isinstance(composer, dict), "composer.json: expected a JSON object.")
        if isinstance(composer, dict):
            require("version" not in composer, "composer.json: omit version; signed Git tags own package versions.")
            require(composer.get("name") == PACKAGE, f"composer.json: package name must be {PACKAGE}.")
            requirements = composer.get("require", {})
            if not isinstance(requirements, dict):
                requirements = {}
            php = requirements.get("php", "")
            common = requirements.get("johnnickell/fight-common", "")
            require(
                isinstance(php, str) and php.startswith(">=")
                and f"**PHP {php[2:]}+**" in panel
                and bool(common) and f"**Fight Common {common}**" in panel,
                "README.md: runtime requirements must match composer.json (review the checker if constraint syntax changes).",
            )
    except json.JSONDecodeError:
        errors.append("composer.json: invalid JSON.")

    changelog = read("CHANGELOG.md")
    headings = re.findall(r"^## \[([^\]]+)\](.*)$", changelog, re.MULTILINE)
    require(
        len(headings) >= 2 and headings[0] == ("Unreleased", "") and headings[1][0] == version
        and sum(name == version for name, _ in headings) == 1,
        f"CHANGELOG.md: v{version} must be the first and unique release after [Unreleased].",
    )
    dates = [suffix for name, suffix in headings if name == version]
    try:
        if len(dates) != 1 or re.fullmatch(r" - [0-9]{4}-[0-9]{2}-[0-9]{2}", dates[0]) is None:
            raise ValueError
        datetime.date.fromisoformat(dates[0][3:])
    except ValueError:
        errors.append(f"CHANGELOG.md: [{version}] needs one valid YYYY-MM-DD release date.")

    target = f"{REPOSITORY}/compare/v{version}...HEAD"
    links = re.findall(r"^\[Unreleased\]: (.+)$", changelog, re.MULTILINE)
    require(links == [target], f"CHANGELOG.md: [Unreleased] link must be {target}.")
    previous = headings[2][0] if len(headings) > 2 else None
    if previous is not None and re.fullmatch(VERSION_PATTERN, previous) is None:
        errors.append("CHANGELOG.md: previous release heading is malformed.")
    target = (
        f"{REPOSITORY}/compare/v{previous}...v{version}"
        if previous is not None else f"{REPOSITORY}/releases/tag/v{version}"
    )
    links = re.findall(r"^\[" + re.escape(version) + r"\]: (.+)$", changelog, re.MULTILINE)
    require(links == [target], f"CHANGELOG.md: [{version}] link must be {target}.")

    overview_path = f"docs/release-{version}.md"
    overview = read(overview_path)
    require(
        overview.splitlines()[:1] == [f"# v{version} release and integration overview"],
        f"{overview_path}: overview title must identify v{version}.",
    )
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("version")
    args = parser.parse_args()
    errors = validate(Path(__file__).resolve().parent.parent, args.version)
    if errors:
        for error in errors:
            print(f"release docs: {error}")
        return 3
    print(f"Release documentation is consistent for v{args.version}; publication is not verified.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
