#!/usr/bin/env python3
"""Validate the small Markdown planning portfolio without external dependencies."""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLANNING = ROOT / "planning"
VALID_STATUSES = {
    "needs-triage", "needs-info", "ready-for-agent", "ready-for-human",
    "in-progress", "done", "wontfix",
}
TERMINAL = {"done", "wontfix"}


def frontmatter(path: Path) -> dict[str, str]:
    text = path.read_text(encoding="utf-8")
    if not text.startswith("---\n"):
        raise ValueError("missing frontmatter")
    _, block, _ = text.split("---", 2)
    values: dict[str, str] = {}
    for line in block.strip().splitlines():
        key, separator, value = line.partition(":")
        if not separator:
            raise ValueError(f"invalid frontmatter line: {line}")
        values[key.strip()] = value.strip()
    return values


def main() -> int:
    errors: list[str] = []
    records: dict[str, tuple[Path, dict[str, str]]] = {}
    patterns = {
        "epics": re.compile(r"EPIC-\d{5}$"),
        "specs": re.compile(r"PRD-\d{5}$"),
        "tickets": re.compile(r"T-\d{5}$"),
    }

    for directory, pattern in patterns.items():
        suffix = {"epics": "-EPIC.md", "specs": "-PRD.md", "tickets": "-TICKET.md"}[directory]
        for path in sorted((PLANNING / directory).rglob(f"*{suffix}")):
            try:
                data = frontmatter(path)
            except ValueError as exception:
                errors.append(f"{path.relative_to(ROOT)}: {exception}")
                continue
            record_id = data.get("id", "")
            if not pattern.fullmatch(record_id):
                errors.append(f"{path.relative_to(ROOT)}: invalid id {record_id!r}")
            if record_id in records:
                errors.append(f"duplicate id: {record_id}")
            if data.get("status") not in VALID_STATUSES:
                errors.append(f"{path.relative_to(ROOT)}: invalid status {data.get('status')!r}")
            for required in ("id", "title", "status"):
                if not data.get(required):
                    errors.append(f"{path.relative_to(ROOT)}: missing {required}")
            records[record_id] = (path, data)

    markdown_link = re.compile(r"\!?\[[^\]]*\]\(([^\s)]+)")
    for path in PLANNING.rglob("*.md"):
        if path.name.startswith("_"):
            continue
        for target in markdown_link.findall(path.read_text(encoding="utf-8")):
            destination, _, _anchor = target.partition("#")
            if not destination.endswith(".md") or destination.startswith(("/", "http:", "https:", "mailto:")):
                continue
            resolved = (path.parent / destination).resolve()
            if not resolved.is_file():
                errors.append(f"{path.relative_to(ROOT)}: broken local Markdown link {target}")

    for record_id, (path, data) in records.items():
        parent = data.get("epic") or data.get("prd")
        if parent and parent not in records:
            errors.append(f"{path.relative_to(ROOT)}: unknown parent {parent}")
        blockers = [value for value in data.get("blocked_by", "").split(",") if value]
        for blocker in blockers:
            if blocker not in records:
                errors.append(f"{path.relative_to(ROOT)}: unknown blocker {blocker}")
            elif not blocker.startswith("T-"):
                errors.append(f"{path.relative_to(ROOT)}: blocker must be a ticket: {blocker}")

    visiting: set[str] = set()
    visited: set[str] = set()

    def visit(ticket_id: str) -> None:
        if ticket_id in visiting:
            errors.append(f"dependency cycle at {ticket_id}")
            return
        if ticket_id in visited or ticket_id not in records:
            return
        visiting.add(ticket_id)
        for blocker in filter(None, records[ticket_id][1].get("blocked_by", "").split(",")):
            visit(blocker)
        visiting.remove(ticket_id)
        visited.add(ticket_id)

    for record_id in records:
        if record_id.startswith("T-"):
            visit(record_id)

    ignored = subprocess.run(
        [
            "git",
            "-c",
            f"safe.directory={ROOT.resolve()}",
            "check-ignore",
            "-q",
            ".runs/planning-check",
        ],
        cwd=ROOT,
        check=False,
    )
    if ignored.returncode != 0:
        errors.append(".runs/ must be gitignored")

    if errors:
        print("Planning validation failed:")
        for error in errors:
            print(f"- {error}")
        return 1

    active = sum(1 for _, data in records.values() if data.get("status") not in TERMINAL)
    print(f"Planning validation passed: {len(records)} records, {active} active")
    return 0


if __name__ == "__main__":
    sys.exit(main())
