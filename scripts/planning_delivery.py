"""Observe explicit delivery snapshots and plan lossless, authorized retirement.

Qualified from Fight Common TASK-00176. Ordinary validation is strictly offline.
"""
from __future__ import annotations

import datetime
import json
import os
from pathlib import Path
import re
import subprocess


def current_delivery(records: dict) -> list:
    return [(key, path, data) for key, (path, data) in sorted(records.items())
            if data.get("delivery") == "awaiting-merge"]


def local_git(root: Path, *arguments: str) -> str:
    return subprocess.run(
        ["git", "--no-replace-objects", "-c", f"safe.directory={root.resolve()}", *arguments],
        cwd=root, capture_output=True, text=True, timeout=5, check=True,
        env={**os.environ, "GIT_NO_LAZY_FETCH": "1"},
    ).stdout.strip()


def delivery_identity(root: Path, records: dict) -> list[str]:
    errors, claimed = [], {}
    for key, (path, data) in records.items():
        if data.get("delivery") and (not key.startswith("TASK-") or "archive" in path.parts
                                    or data["delivery"] != "awaiting-merge"):
            errors.append(f"{key}: delivery must be awaiting-merge on a live TASK")
    handoffs = current_delivery(records)
    if not handoffs:
        return errors
    try:
        origin = local_git(root, "remote", "get-url", "origin")
        match = re.fullmatch(r"(?:git@github\.com:|https://github\.com/)([A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_.-]*?)(?:\.git)?", origin)
        if not match:
            raise ValueError()
    except (OSError, subprocess.SubprocessError, ValueError):
        return errors + ["delivery identity unavailable: restore the intended GitHub origin before refresh"]
    for key, path, data in handoffs:
        if path.name != f"{key[5:]}-TASK.md":
            errors.append(f"{key}: filename and subject disagree")
        url = data.get("pr", "")
        if not re.fullmatch(rf"https://github\.com/{re.escape(match[1])}/pull/[1-9][0-9]*", url):
            errors.append(f"{key}: exact marked PR must match target origin")
        elif url in claimed:
            errors.append(f"{key}: conflicting marked PR subject {claimed[url]}")
        claimed[url] = key
    return errors


def verify_delivery(root: Path, records: dict, subjects: list[str]) -> tuple[list, list[str]]:
    handoffs = current_delivery(records)
    marked = {key for key, _, _ in handoffs}
    if len(subjects) != len(set(subjects)) or any(key not in marked for key in subjects):
        return [], ["UNVERIFIED: select unique explicitly marked TASK IDs; no requests made"]
    if subjects:
        handoffs = [row for row in handoffs if row[0] in subjects]
    if len(handoffs) > 16:
        return [], ["UNVERIFIED: maximum 16 marked subjects; select relevant TASK IDs; no requests made"]
    resolved, errors = [], []
    for key, path, data in handoffs:
        url = data["pr"]
        repository, number = url.removeprefix("https://github.com/").split("/pull/")
        try:
            result = subprocess.run(
                ["gh", "pr", "view", number, "--repo", repository, "--json",
                 "number,url,title,state,isDraft,mergedAt,mergeCommit,baseRefName,headRefName,headRefOid"],
                cwd=root, capture_output=True, text=True, timeout=15, check=True,
                env={**os.environ, "GH_PROMPT_DISABLED": "1"},
            )
            if len(result.stdout.encode("utf-8")) > 16384:
                raise ValueError("oversized metadata")
            pr = json.loads(result.stdout)
            if (not isinstance(pr, dict) or type(pr.get("number")) is not int or str(pr["number"]) != number
                    or pr.get("url") != url or pr.get("title") != key + " — " + data["title"]
                    or pr.get("baseRefName") != "develop"
                    or not isinstance(pr.get("headRefName"), str)
                    or not re.fullmatch(rf"feature/task-{key[5:]}-[a-z0-9][a-z0-9-]*", pr["headRefName"])
                    or not isinstance(pr.get("headRefOid"), str)
                    or not re.fullmatch(r"[0-9a-f]{40}", pr["headRefOid"])
                    or type(pr.get("isDraft")) is not bool
                    or not {"mergedAt", "mergeCommit"}.issubset(pr)):
                raise ValueError("identity/incomplete metadata")
            state = pr.get("state")
            if state == "MERGED":
                if (not isinstance(pr["mergedAt"], str)
                        or not re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", pr["mergedAt"])
                        or not isinstance(pr["mergeCommit"], dict)
                        or not isinstance(pr["mergeCommit"].get("oid"), str)
                        or not re.fullmatch(r"[0-9a-f]{40}", pr["mergeCommit"]["oid"])):
                    raise ValueError("incomplete merge evidence")
                datetime.datetime.fromisoformat(pr["mergedAt"].replace("Z", "+00:00"))
                if data["status"] != "done":
                    errors.append(f"{key}: MERGED but subject is not done; requirement owner must resolve scope")
                    continue
                try:
                    head = local_git(root, "rev-parse", "--verify", "HEAD^{commit}")
                    local_git(root, "merge-base", "--is-ancestor", pr["headRefOid"], pr["mergeCommit"]["oid"])
                    local_git(root, "merge-base", "--is-ancestor", pr["mergeCommit"]["oid"], head)
                except (OSError, subprocess.SubprocessError):
                    errors.append(f"{key}: MERGED but local integration UNVERIFIED; authorized Git owner must integrate exact merge; no fetch performed")
                    continue
                pr["localHead"] = head
                pr["observedAt"] = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
                resolved.append((key, path, pr))
                print(f"Delivery resolved: {key} {url} MERGED at {pr['mergedAt']} oid={pr['mergeCommit']['oid']}; head={pr['headRefOid']} base={pr['baseRefName']} branch={pr['headRefName']} integrated={head}. No routine cleanup request; routing may continue.")
            elif state not in {"OPEN", "CLOSED"} or pr["mergedAt"] is not None or pr["mergeCommit"] is not None:
                raise ValueError("contradictory state")
            elif state == "CLOSED":
                errors.append(f"{key}: CLOSED WITHOUT MERGE; not delivered; maintainer owns future delivery, Land owns exceptional handoff correction")
            else:
                action = "Land delivery prerequisites (draft)" if pr["isDraft"] else "existing acceptance/QA/delivery holds before human review/merge"
                print(f"Delivery OPEN: {key} {url}; evaluate {action}. OPEN proves no readiness or CI success.")
        except (OSError, subprocess.SubprocessError, ValueError, TypeError, RecursionError):
            # Never echo provider diagnostics: they can contain credentials or private input.
            errors.append(f"{key}: UNVERIFIED tooling/authentication/API/metadata/identity; recover exact PR evidence; infer neither open nor merged")
    if not handoffs:
        print("No recorded current handoffs; no hosting requests; historical PRs not queried.")
    return resolved, errors


def reconcile_delivery(records: dict, resolved: list) -> dict[Path, str]:
    pending = {}
    for key, path, pr in resolved:
        text = path.read_bytes().decode("utf-8")
        header, boundary, body = re.split(r"((?:\r\n|\r|\n)---(?:\r\n|\r|\n))", text, maxsplit=1)
        end = boundary.index("---")
        header = "".join(line for line in (header + boundary[:end]).splitlines(keepends=True)
                         if line.partition(":")[0].strip() != "delivery")
        pending[path] = header + boundary[end:] + body + "\n\n" + (
            f"### Delivery reconciliation — PR #{pr['number']}\n\n"
            f"Observed {pr['observedAt']}: {pr['url']} MERGED into `{pr['baseRefName']}` at `{pr['mergedAt']}`. "
            f"Published head `{pr['headRefOid']}` on `{pr['headRefName']}` is an ancestor of merge "
            f"`{pr['mergeCommit']['oid']}`, integrated in local HEAD `{pr['localHead']}`. "
            "Retired only the ended marker; acceptance/QA and historical publication remain separate. "
            "No approval, merge, release or deployment was performed.\n"
        )
        records[key][1].pop("delivery")
    return pending


def delivery_table(root: Path, records: dict, source: Path) -> str:
    rows = ["| Subject | Recorded delivery | Exact PR |", "|---|---|---|"]
    for key, path, data in current_delivery(records):
        link = os.path.relpath(path, source.parent).replace(os.sep, "/")
        rows.append(f"| [{key}]({link}) | awaiting-merge; verify live state | [{data['pr']}]({data['pr']}) |")
    if len(rows) == 2:
        rows.append("| None | — | — |")
    return "\n".join(rows)
