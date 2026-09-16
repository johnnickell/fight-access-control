#!/usr/bin/env python3
"""Qualify Task Board next-action precedence with disposable planning records."""

from __future__ import annotations

from pathlib import Path

from planning_portfolio import Record, render_board


def task(order: int, status: str, title: str) -> Record:
    """Creates one disposable standalone Task record."""
    return Record(
        path=Path("planning/tasks") / f"{order:05}-TASK.md",
        fields={
            "id": f"TASK-{order:05}",
            "kind": "bug",
            "order": str(order),
            "status": status,
            "title": title,
        },
    )


def next_action(*tasks: Record) -> str:
    """Renders the Board next-action section for disposable Task records."""
    records = {record.identifier: record for record in tasks}
    board = render_board(records, records)

    return board.split("## What's Next?\n\n", 1)[1].split("\n\n## ", 1)[0]


def assert_next_action(expected: str, *tasks: Record) -> None:
    """Asserts the rendered next action equals the expected guidance."""
    actual = next_action(*tasks)

    assert actual == expected, f"Expected {expected!r}; got {actual!r}."


def main() -> None:
    """Qualifies the accepted Task Board next-action precedence."""
    needs_info = task(35, "needs-info", "Clarify Common compatibility")
    triage = task(36, "needs-triage", "Triage the planning gap")
    ready = task(37, "ready-for-agent", "Execute the ready slice")
    active = task(38, "in-progress", "Continue the active slice")
    human = task(39, "ready-for-human", "Decide the release boundary")

    assert_next_action(
        "Needs information: [TASK-00035](00035-TASK.md) — Clarify Common compatibility. "
        "This Task is not executable until the missing information is resolved.",
        needs_info,
    )
    assert_next_action(
        "Needs triage: [TASK-00036](00036-TASK.md) — Triage the planning gap. "
        "This Task is not executable until it is triaged.",
        triage,
    )
    assert_next_action(
        "Needs information: [TASK-00035](00035-TASK.md) — Clarify Common compatibility. "
        "This Task is not executable until the missing information is resolved.",
        needs_info,
        triage,
    )
    assert_next_action(
        "First ready Task: [TASK-00037](00037-TASK.md) — Execute the ready slice.",
        ready,
        needs_info,
        triage,
    )
    assert_next_action(
        "Active Task: [TASK-00038](00038-TASK.md) — Continue the active slice.",
        active,
        ready,
        needs_info,
        triage,
    )
    assert_next_action(
        "Human decision required: [TASK-00039](00039-TASK.md) — Decide the release boundary. "
        "Active Task: [TASK-00038](00038-TASK.md) — Continue the active slice.",
        human,
        active,
        ready,
        needs_info,
        triage,
    )
    assert_next_action(
        "No non-terminal Task exists. Any future release decision requires separate human authorization.",
    )

    print("Planning portfolio next-action qualification passed.")


if __name__ == "__main__":
    main()
