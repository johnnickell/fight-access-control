#!/usr/bin/env python3
"""Archive explicitly selected terminal planning records and repair local Markdown links."""

from __future__ import annotations

import argparse
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLANNING = ROOT / "planning"
TERMINAL = {"done", "wontfix"}
LINK = re.compile(r"(\!?\[[^\]]*\]\()([^\s)]+)(\))")


def frontmatter(path: Path) -> dict[str, str]:
    text = path.read_text(encoding="utf-8")
    if not text.startswith("---\n"):
        return {}
    _, block, _ = text.split("---", 2)
    return {
        key.strip(): value.strip()
        for line in block.strip().splitlines()
        if ":" in line
        for key, value in [line.split(":", 1)]
    }


def records(directory: str, suffix: str) -> dict[str, Path]:
    result: dict[str, Path] = {}
    for path in (PLANNING / directory).glob(f"*{suffix}"):
        data = frontmatter(path)
        if identifier := data.get("id"):
            result[identifier.strip()] = path
    return result


def wayfinder_status(path: Path) -> str:
    match = re.search(r"^\*\*Status:\*\*\s*(.+?)\s*$", path.read_text(encoding="utf-8"), re.MULTILINE)
    return match.group(1).strip().casefold() if match else ""


def wayfinder_frontier_is_empty(text: str) -> bool:
    match = re.search(r"^## Frontier\s*\n+(.*?)(?=^## |\Z)", text, re.MULTILINE | re.DOTALL)
    if match is None:
        return False
    return bool(re.search(r"\b(?:none|no wayfinder decision remains|no remaining frontier)\b", match.group(1), re.IGNORECASE))


def local_target(source: Path, destination: str) -> Path | None:
    target, _, _anchor = destination.partition("#")
    if not target or target.startswith(("/", "http:", "https:", "mailto:")):
        return None
    return (source.parent / target).resolve()


def require_terminal(selected: list[Path]) -> None:
    for path in selected:
        if frontmatter(path).get("status") not in TERMINAL:
            raise ValueError(f"{path.relative_to(ROOT)} is not terminal")


def require_no_live_dependents(selected_task_ids: set[str], all_tasks: dict[str, Path]) -> None:
    dependents: list[str] = []
    for identifier, path in all_tasks.items():
        if identifier in selected_task_ids:
            continue
        blockers = {
            blocker.strip()
            for blocker in frontmatter(path).get("blocked_by", "").split(",")
            if blocker.strip()
        }
        if selected := sorted(blockers & selected_task_ids):
            dependents.append(f"{identifier} depends on {', '.join(selected)}")
    if dependents:
        raise ValueError("selected Tasks remain live blockers: " + "; ".join(dependents))


def archive_tasks(identifiers: list[str]) -> dict[Path, Path]:
    current = records("tasks", "-TASK.md")
    selected = [current[identifier] for identifier in identifiers]
    require_terminal(selected)
    require_no_live_dependents(set(identifiers), current)
    return {path: PLANNING / "tasks/archive" / path.name for path in selected}


def archive_tickets(identifiers: list[str]) -> dict[Path, Path]:
    current = records("tickets", "-TICKET.md")
    selected = [current[identifier] for identifier in identifiers]
    require_terminal(selected)
    all_tasks = records("tasks", "-TASK.md")
    for path in selected:
        identifier = frontmatter(path)["id"]
        children = [task for task in all_tasks.values() if frontmatter(task).get("ticket") == identifier]
        require_terminal(children)
    moves = {path: PLANNING / "tickets/archive" / path.name for path in selected}
    for task in all_tasks.values():
        if frontmatter(task).get("ticket") in identifiers:
            moves[task] = PLANNING / "tasks/archive" / task.name
    require_no_live_dependents(
        {identifier for identifier, path in all_tasks.items() if path in moves},
        all_tasks,
    )
    return moves


def archive_epics(identifiers: list[str]) -> dict[Path, Path]:
    current = records("epics", "-EPIC.md")
    selected = [current[identifier] for identifier in identifiers]
    require_terminal(selected)
    all_tickets = records("tickets", "-TICKET.md")
    all_tasks = records("tasks", "-TASK.md")
    for path in selected:
        identifier = frontmatter(path)["id"]
        children = [ticket for ticket in all_tickets.values() if frontmatter(ticket).get("epic") == identifier]
        require_terminal(children)
        for ticket in children:
            ticket_id = frontmatter(ticket)["id"]
            require_terminal([task for task in all_tasks.values() if frontmatter(task).get("ticket") == ticket_id])
    moves = {path: PLANNING / "epics/archive" / path.name for path in selected}
    selected_ticket_ids = set()
    for ticket in all_tickets.values():
        if frontmatter(ticket).get("epic") in identifiers:
            moves[ticket] = PLANNING / "tickets/archive" / ticket.name
            selected_ticket_ids.add(frontmatter(ticket)["id"])
    for task in all_tasks.values():
        if frontmatter(task).get("ticket") in selected_ticket_ids:
            moves[task] = PLANNING / "tasks/archive" / task.name
    require_no_live_dependents(
        {identifier for identifier, path in all_tasks.items() if path in moves},
        all_tasks,
    )
    return moves


def archive_wayfinder(name: str) -> dict[Path, Path]:
    filename = name if name.endswith(".md") else f"{name}.md"
    path = PLANNING / "wayfinder" / filename
    if not path.is_file():
        raise ValueError(f"unknown Wayfinder map: {name}")
    text = path.read_text(encoding="utf-8")
    if wayfinder_status(path) != "closed":
        raise ValueError(f"{path.relative_to(ROOT)} is not Closed")
    if not wayfinder_frontier_is_empty(text):
        raise ValueError(f"{path.relative_to(ROOT)} still has a Wayfinder frontier")
    ticket_paths = [
        PLANNING / "wayfinder/tickets" / match
        for match in re.findall(r"\]\(tickets/(WF-\d{3}[^)#]+\.md)\)", text)
    ]
    if not ticket_paths:
        raise ValueError(f"{path.relative_to(ROOT)} has no linked decision tickets")
    if any(not ticket.is_file() or wayfinder_status(ticket) != "closed" for ticket in ticket_paths):
        raise ValueError(f"{path.relative_to(ROOT)} has unresolved decision tickets")
    if not re.search(r"\]\(\.\./(?:epics|tickets|tasks)/", text):
        raise ValueError(f"{path.relative_to(ROOT)} lacks a linked implementation handoff")

    moves = {path: PLANNING / "wayfinder/archive/maps" / path.name}
    for ticket in ticket_paths:
        moves[ticket] = PLANNING / "wayfinder/tickets/archive" / ticket.name
        research = PLANNING / "wayfinder/research" / f"{ticket.stem}-research.md"
        if research.is_file():
            moves[research] = PLANNING / "wayfinder/research/archive" / research.name
    return moves


def rewrite_links(moves: dict[Path, Path]) -> None:
    documents = list(ROOT.rglob("*.md"))
    originals = {path.resolve(): path.read_text(encoding="utf-8") for path in documents}
    normalized = {source.resolve(): destination.resolve() for source, destination in moves.items()}
    original_sources = {destination: source for source, destination in normalized.items()}

    for new_source, text in originals.items():
        old_source = original_sources.get(new_source, new_source)

        def replace(match: re.Match[str]) -> str:
            target = match.group(2)
            old_target = local_target(old_source, target)
            if old_target is None:
                return match.group(0)
            new_target = normalized.get(old_target, old_target)
            if new_source == old_source and new_target == old_target:
                return match.group(0)
            _path, marker, anchor = target.partition("#")
            relative = os.path.relpath(new_target, new_source.parent).replace(os.sep, "/")
            return f"{match.group(1)}{relative}{marker}{anchor}{match.group(3)}"

        rewritten = LINK.sub(replace, text)
        if rewritten != text:
            new_source.parent.mkdir(parents=True, exist_ok=True)
            new_source.write_text(rewritten, encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("kind", choices=("tasks", "tickets", "epics", "wayfinder"))
    parser.add_argument("identifiers", nargs="+")
    parser.add_argument("--apply", action="store_true", help="perform the validated archive move")
    args = parser.parse_args()

    try:
        if args.kind == "tasks":
            moves = archive_tasks(args.identifiers)
        elif args.kind == "tickets":
            moves = archive_tickets(args.identifiers)
        elif args.kind == "epics":
            moves = archive_epics(args.identifiers)
        else:
            if len(args.identifiers) != 1:
                raise ValueError("archive wayfinder accepts exactly one map name")
            moves = archive_wayfinder(args.identifiers[0])
        if any(destination.exists() for destination in moves.values()):
            raise ValueError("an archive destination already exists")
    except (KeyError, ValueError) as exception:
        print(f"Archive refused: {exception}", file=sys.stderr)
        return 2

    action = "Archive" if args.apply else "Would archive"
    for source, destination in moves.items():
        print(f"{action}: {source.relative_to(ROOT)} -> {destination.relative_to(ROOT)}")
    if not args.apply:
        print("Dry run only. Re-run with --apply after reviewing these moves.")
        return 0

    for source, destination in moves.items():
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.move(source, destination)
    rewrite_links(moves)
    write = subprocess.run([str(ROOT / "bin/planning-check"), "--write"], cwd=ROOT, check=False)
    if write.returncode:
        return write.returncode
    return subprocess.run([str(ROOT / "bin/planning-check")], cwd=ROOT, check=False).returncode


if __name__ == "__main__":
    sys.exit(main())
