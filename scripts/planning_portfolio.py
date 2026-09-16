#!/usr/bin/env python3
"""Generate and validate the EPIC -> TICKET -> TASK planning portfolio."""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLANNING = ROOT / "planning"
STATUS = {"needs-triage", "needs-info", "ready-for-agent", "ready-for-human", "in-progress", "done", "wontfix"}
TERMINAL = {"done", "wontfix"}
KINDS = {"epics": ("EPIC", "-EPIC.md"), "tickets": ("TICKET", "-TICKET.md"), "tasks": ("TASK", "-TASK.md")}
STANDALONE_TASK_KINDS = {"bug", "chore"}
LINK = re.compile(r"!?\[[^\]]*\]\(([^\s)]+)")


@dataclass(frozen=True)
class Record:
    path: Path
    fields: dict[str, str]

    @property
    def identifier(self) -> str:
        return self.fields["id"]

    @property
    def title(self) -> str:
        return self.fields["title"]


def frontmatter(path: Path) -> dict[str, str]:
    text = path.read_text(encoding="utf-8")
    if not text.startswith("---\n"):
        raise ValueError("missing frontmatter")
    try:
        _opening, block, _body = text.split("---", 2)
    except ValueError as exception:
        raise ValueError("unterminated frontmatter") from exception

    return {
        key.strip(): value.strip()
        for line in block.strip().splitlines()
        if ":" in line
        for key, value in [line.split(":", 1)]
    }


def load_records(errors: list[str]) -> dict[str, Record]:
    records: dict[str, Record] = {}
    for directory, (prefix, suffix) in KINDS.items():
        for path in sorted((PLANNING / directory).glob(f"*{suffix}")):
            try:
                fields = frontmatter(path)
            except ValueError as exception:
                errors.append(f"{path.relative_to(ROOT)}: {exception}")
                continue
            identifier = fields.get("id", "")
            if not re.fullmatch(rf"{prefix}-[0-9]{{5}}", identifier):
                errors.append(f"{path.relative_to(ROOT)}: invalid id {identifier!r}")
                continue
            if identifier in records:
                errors.append(f"duplicate id {identifier}")
                continue
            if fields.get("status") not in STATUS:
                errors.append(f"{path.relative_to(ROOT)}: invalid status")
            if not fields.get("title"):
                errors.append(f"{path.relative_to(ROOT)}: missing title")
            records[identifier] = Record(path, fields)
    return records


def load_archived_records(errors: list[str]) -> dict[str, Record]:
    records: dict[str, Record] = {}
    for directory, (prefix, suffix) in KINDS.items():
        archive = PLANNING / directory / "archive"
        if not archive.is_dir():
            continue
        for path in sorted(archive.rglob(f"*{suffix}")):
            try:
                fields = frontmatter(path)
            except ValueError as exception:
                errors.append(f"{path.relative_to(ROOT)}: {exception}")
                continue
            identifier = fields.get("id", "")
            if not re.fullmatch(rf"{prefix}-[0-9]{{5}}", identifier):
                errors.append(f"{path.relative_to(ROOT)}: invalid archived id {identifier!r}")
            elif identifier in records:
                errors.append(f"duplicate archived id {identifier}")
            else:
                records[identifier] = Record(path, fields)
    return records


def record_sort_key(record: Record) -> tuple[int, str]:
    return (int(record.fields.get("order", record.identifier.rsplit("-", 1)[1])), record.identifier)


def blocker_ids(record: Record) -> list[str]:
    return [item.strip() for item in record.fields.get("blocked_by", "").split(",") if item.strip()]


def descendants(records: dict[str, Record], parent_key: str, parent: str) -> list[Record]:
    return sorted((record for record in records.values() if record.fields.get(parent_key) == parent), key=record_sort_key)


def validate_records(records: dict[str, Record], errors: list[str]) -> None:
    for identifier, record in records.items():
        fields = record.fields
        parent_key = "epic" if identifier.startswith("TICKET-") else "ticket"
        parent = fields.get(parent_key, "")
        if identifier.startswith("TICKET-"):
            expected = "EPIC-"
            if not parent.startswith(expected) or parent not in records:
                errors.append(f"{record.path.relative_to(ROOT)}: invalid {parent_key} {parent!r}")
        if identifier.startswith("TASK-"):
            kind = fields.get("kind", "")
            if parent:
                if not parent.startswith("TICKET-") or parent not in records:
                    errors.append(f"{record.path.relative_to(ROOT)}: invalid ticket {parent!r}")
            elif kind not in STANDALONE_TASK_KINDS:
                errors.append(f"{record.path.relative_to(ROOT)}: parentless TASK requires kind chore or bug")
            if kind and kind not in STANDALONE_TASK_KINDS:
                errors.append(f"{record.path.relative_to(ROOT)}: invalid TASK kind {kind!r}")
            try:
                int(fields["order"])
            except (KeyError, ValueError):
                errors.append(f"{record.path.relative_to(ROOT)}: missing or invalid order")
        for blocker in blocker_ids(record):
            if not blocker.startswith("TASK-") or blocker not in records:
                errors.append(f"{record.path.relative_to(ROOT)}: invalid blocker {blocker}")

    visiting: set[str] = set()
    visited: set[str] = set()

    def visit(identifier: str) -> None:
        if identifier in visiting:
            errors.append(f"dependency cycle at {identifier}")
            return
        if identifier in visited:
            return
        visiting.add(identifier)
        for blocker in blocker_ids(records[identifier]):
            if blocker.startswith("TASK-") and blocker in records:
                visit(blocker)
        visiting.remove(identifier)
        visited.add(identifier)

    for identifier in sorted(item for item in records if item.startswith("TASK-")):
        visit(identifier)


def relative_link(source_directory: Path, target: Path) -> str:
    return Path(os.path.relpath(target, source_directory)).as_posix()


def link_from(source_directory: Path, record: Record) -> str:
    return f"[{record.identifier}]({relative_link(source_directory, record.path)})"


def task_row(source_directory: Path, task: Record, all_records: dict[str, Record]) -> str:
    blockers = blocker_ids(task)
    blocked_by = ", ".join(
        link_from(source_directory, all_records[blocker])
        for blocker in blockers
    ) if blockers else "—"
    pr = task.fields.get("pr", "—")
    parent_id = task.fields.get("ticket", "")
    parent = all_records.get(parent_id)
    parent_label = (
        f"{link_from(source_directory, parent)} — {parent.title}"
        if parent is not None
        else f"— (standalone {task.fields['kind']})"
    )
    return (
        f"| {task.fields['order']} | {link_from(source_directory, task)} | {task.title} | {parent_label} | "
        f"{task.fields['status']} | {blocked_by} | {pr} |"
    )


def render_task_index(records: dict[str, Record], all_records: dict[str, Record]) -> str:
    source = PLANNING / "tasks"
    tasks = sorted((record for record in records.values() if record.identifier.startswith("TASK-")), key=record_sort_key)
    rows = "\n".join(task_row(source, task, all_records) for task in tasks)
    return f"""# Tasks

Tasks are executable vertical slices. Their `ticket` frontmatter normally connects them to a parent Ticket;
standalone bugs and chores leave `ticket` empty and declare `kind`. `blocked_by` contains only Task IDs. This
generated index is a projection; Task records are canonical for scope, dependencies, acceptance, and verification.
The [Task Board](BOARD.md) is the operational projection.

<!-- generated:task-index:start -->
| Order | TASK ID | Title | Parent TICKET | Status | Blocked by | PR |
| --- | --- | --- | --- | --- | --- | --- |
{rows}
<!-- generated:task-index:end -->
"""


def render_table(source: Path, tasks: list[Record], all_records: dict[str, Record]) -> str:
    if not tasks:
        return "No Tasks are currently in this state."
    return "\n".join([
        "| Order | TASK ID | Title | Parent TICKET | Status | Blocked by | PR |",
        "| --- | --- | --- | --- | --- | --- | --- |",
        *(task_row(source, task, all_records) for task in tasks),
    ])


def render_board(records: dict[str, Record], all_records: dict[str, Record]) -> str:
    source = PLANNING / "tasks"
    tasks = sorted((record for record in records.values() if record.identifier.startswith("TASK-")), key=record_sort_key)
    active = [task for task in tasks if task.fields["status"] not in TERMINAL]
    in_progress = [task for task in active if task.fields["status"] == "in-progress"]
    ready = [
        task for task in active
        if task.fields["status"] == "ready-for-agent"
        and all(all_records[blocker].fields["status"] in TERMINAL for blocker in blocker_ids(task))
    ]
    waiting = [task for task in active if task.fields["status"] == "ready-for-agent" and task not in ready]
    needs_info = [task for task in active if task.fields["status"] == "needs-info"]
    human = [task for task in active if task.fields["status"] == "ready-for-human"]
    triage = [task for task in active if task.fields["status"] == "needs-triage"]
    next_item = "No non-terminal Task exists. Any future release decision requires separate human authorization."
    if human:
        next_item = f"Human decision required: {link_from(source, human[0])} — {human[0].title}."
        if in_progress:
            next_item += f" Active Task: {link_from(source, in_progress[0])} — {in_progress[0].title}."
    elif in_progress:
        next_item = f"Active Task: {link_from(source, in_progress[0])} — {in_progress[0].title}."
    elif ready:
        next_item = f"First ready Task: {link_from(source, ready[0])} — {ready[0].title}."
    elif needs_info:
        next_item = (
            f"Needs information: {link_from(source, needs_info[0])} — {needs_info[0].title}. "
            "This Task is not executable until the missing information is resolved."
        )
    elif triage:
        next_item = (
            f"Needs triage: {link_from(source, triage[0])} — {triage[0].title}. "
            "This Task is not executable until it is triaged."
        )
    return f"""# Task Board

This generated execution view preserves the portfolio. Individual Task records are canonical for scope,
dependencies, acceptance, and verification.

## What's Next?

{next_item}

## In Progress

{render_table(source, in_progress, all_records)}

## Ready Frontier

{render_table(source, ready, all_records)}

## Waiting

{render_table(source, waiting, all_records)}

## Needs Info

{render_table(source, needs_info, all_records)}

## Human Action

{render_table(source, human, all_records)}

## Triage

{render_table(source, triage, all_records)}

## Recently Done

{render_table(source, [task for task in tasks if task.fields["status"] in TERMINAL], all_records)}
"""


def replace_section(text: str, heading: str, content: str) -> str:
    pattern = re.compile(rf"(?ms)^{re.escape(heading)}\n.*?(?=^## |\Z)")
    replacement = f"{heading}\n\n{content.rstrip()}\n"
    if not pattern.search(text):
        return f"{text.rstrip()}\n\n{replacement}"
    return pattern.sub(replacement, text)


def render_child_views(records: dict[str, Record]) -> dict[Path, str]:
    rendered: dict[Path, str] = {}
    for epic in (record for record in records.values() if record.identifier.startswith("EPIC-")):
        tickets = descendants(records, "epic", epic.identifier)
        content = "\n".join([
            "| TICKET ID | Title | Status |",
            "| --- | --- | --- |",
            *(f"| {link_from(epic.path.parent, ticket)} | {ticket.title} | {ticket.fields['status']} |" for ticket in tickets),
        ]) if tickets else "No Tickets recorded."
        rendered[epic.path] = replace_section(epic.path.read_text(encoding="utf-8"), "## Child Tickets", content or "No Tickets recorded.")
    for ticket in (record for record in records.values() if record.identifier.startswith("TICKET-")):
        tasks = descendants(records, "ticket", ticket.identifier)
        content = "\n".join([
            "| Order | TASK ID | Title | Status |",
            "| --- | --- | --- | --- |",
            *(f"| {task.fields['order']} | {link_from(ticket.path.parent, task)} | {task.title} | {task.fields['status']} |" for task in tasks),
        ]) if tasks else "No Tasks recorded."
        rendered[ticket.path] = replace_section(ticket.path.read_text(encoding="utf-8"), "## Child Tasks", content or "No Tasks recorded.")
    return rendered


def render_record_index(directory: str, heading: str, records: list[Record]) -> str:
    source = PLANNING / directory
    rows = "\n".join(f"| {link_from(source, record)} | {record.title} | {record.fields['status']} |" for record in records)
    return f"""# {heading}

This generated index is a projection; individual records remain canonical.

| ID | Title | Status |
| --- | --- | --- |
{rows}
"""


def render_roadmap(records: dict[str, Record]) -> str:
    path = PLANNING / "ROADMAP.md"
    rows = []
    for epic in sorted((record for record in records.values() if record.identifier.startswith("EPIC-")), key=record_sort_key):
        tickets = descendants(records, "epic", epic.identifier)
        tasks = [task for ticket in tickets for task in descendants(records, "ticket", ticket.identifier)]
        rows.append(
            f"| {link_from(PLANNING, epic)} | {epic.fields.get('target', '—')} | {epic.fields['status']} | "
            f"{len(tickets)} | {len(tasks)} |"
        )
    content = "\n".join([
        "<!-- generated:epic-status:start -->",
        "| EPIC | Target | Status | Tickets | Tasks |",
        "| --- | --- | --- | --- | --- |",
        *rows,
        "<!-- generated:epic-status:end -->",
    ])
    return replace_section(path.read_text(encoding="utf-8"), "## Record Status Projection", content)


def generated_views(records: dict[str, Record], all_records: dict[str, Record]) -> dict[Path, str]:
    views = {
        PLANNING / "epics" / "README.md": render_record_index(
            "epics", "EPICs", sorted((record for record in records.values() if record.identifier.startswith("EPIC-")), key=record_sort_key)
        ),
        PLANNING / "tickets" / "README.md": render_record_index(
            "tickets", "TICKETs", sorted((record for record in records.values() if record.identifier.startswith("TICKET-")), key=record_sort_key)
        ),
        PLANNING / "tasks" / "README.md": render_task_index(records, all_records),
        PLANNING / "tasks" / "BOARD.md": render_board(records, all_records),
        PLANNING / "ROADMAP.md": render_roadmap(records),
    }
    views.update(render_child_views(records))
    return views


def validate_links(errors: list[str]) -> None:
    for path in PLANNING.rglob("*.md"):
        if path.name.startswith("_"):
            continue
        for value in LINK.findall(path.read_text(encoding="utf-8")):
            target = value.partition("#")[0]
            if target.endswith(".md") and not target.startswith(("http:", "https:", "/")):
                if not (path.parent / target).resolve().is_file():
                    errors.append(f"{path.relative_to(ROOT)}: broken link {value}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--write", action="store_true", help="rewrite deterministic planning projections")
    args = parser.parse_args()
    errors: list[str] = []
    records = load_records(errors)
    archived_records = load_archived_records(errors)
    overlap = sorted(set(records) & set(archived_records))
    if overlap:
        errors.append("record is both live and archived: " + ", ".join(overlap))
    validate_records(records | archived_records, errors)
    validate_links(errors)
    ignored = subprocess.run(
        ["git", "-c", f"safe.directory={ROOT.resolve()}", "check-ignore", "-q", ".runs/planning-check"],
        cwd=ROOT,
        check=False,
    ).returncode == 0
    if not ignored:
        errors.append(".runs must be ignored")

    if errors:
        print("Planning validation failed:")
        print("\n".join(f"- {error}" for error in errors))
        return 1

    views = generated_views(records, records | archived_records)
    stale = [path.relative_to(ROOT) for path, rendered in views.items() if path.read_text(encoding="utf-8") != rendered]
    if args.write:
        for path, rendered in views.items():
            if path.read_text(encoding="utf-8") != rendered:
                path.write_text(rendered, encoding="utf-8")
        stale = []
    elif stale:
        errors.append("generated planning views are stale; run ./bin/planning-check --write: " + ", ".join(map(str, stale)))

    if errors:
        print("Planning validation failed:")
        print("\n".join(f"- {error}" for error in errors))
        return 1

    active = sum(record.fields["status"] not in TERMINAL for record in records.values())
    action = "Updated" if args.write else "Validated"
    print(f"Planning {action.lower()}: {len(records)} records, {active} active")
    return 0


if __name__ == "__main__":
    sys.exit(main())
