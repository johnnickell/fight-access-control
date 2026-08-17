#!/usr/bin/env python3
"""Validate the local Markdown planning portfolio without external dependencies."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLANNING = ROOT / "planning"
VALID_STATUSES = {
    "needs-triage", "needs-info", "ready-for-agent", "ready-for-human",
    "in-progress", "done", "wontfix",
}
VALID_ADR_STATUSES = {"proposed", "accepted", "superseded"}
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
        for path in sorted((PLANNING / directory).glob(f"*{suffix}")):
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

    for record_id, (path, data) in records.items():
        if record_id.startswith("PRD-") and not data.get("epic"):
            errors.append(f"{path.relative_to(ROOT)}: missing epic parent")

    roadmap = PLANNING / "ROADMAP.md"
    epic_index = PLANNING / "epics" / "README.md"
    adr_index = PLANNING / "adr" / "README.md"
    for required in (roadmap, epic_index, adr_index):
        if not required.is_file():
            errors.append(f"{required.relative_to(ROOT)}: missing planning index")

    roadmap_text = roadmap.read_text(encoding="utf-8") if roadmap.is_file() else ""
    epic_index_text = epic_index.read_text(encoding="utf-8") if epic_index.is_file() else ""
    for record_id, (path, _) in records.items():
        if not record_id.startswith("EPIC-"):
            continue
        if path.name not in roadmap_text:
            errors.append(f"{roadmap.relative_to(ROOT)}: missing {record_id}")
        if path.name not in epic_index_text:
            errors.append(f"{epic_index.relative_to(ROOT)}: missing {record_id}")

    adr_index_text = adr_index.read_text(encoding="utf-8") if adr_index.is_file() else ""
    for path in sorted((PLANNING / "adr").glob("[0-9][0-9][0-9][0-9]-*.md")):
        text = path.read_text(encoding="utf-8")
        identifier = f"ADR-{path.name[:4]}"
        if not re.search(rf"^# ADR {path.name[:4]}:", text, re.MULTILINE):
            errors.append(f"{path.relative_to(ROOT)}: invalid ADR heading")
        status = re.search(r"^- Status: (.+)$", text, re.MULTILINE)
        if status is None or status.group(1) not in VALID_ADR_STATUSES:
            value = status.group(1) if status else ""
            errors.append(f"{path.relative_to(ROOT)}: invalid ADR status {value!r}")
        if path.name not in adr_index_text:
            errors.append(f"{adr_index.relative_to(ROOT)}: missing {identifier}")

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

    gitignore = ROOT / ".gitignore"
    ignored_patterns = {
        line.strip()
        for line in gitignore.read_text(encoding="utf-8").splitlines()
        if gitignore.is_file() and line.strip() and not line.lstrip().startswith("#")
    } if gitignore.is_file() else set()
    if "/.runs/" not in ignored_patterns:
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
