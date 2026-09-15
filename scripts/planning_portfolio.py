#!/usr/bin/env python3
"""Validate EPIC, Ticket, and Task records."""
from __future__ import annotations
import argparse
import re
import subprocess
import sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
P=ROOT/"planning"
STATUS={"needs-triage","needs-info","ready-for-agent","ready-for-human","in-progress","done","wontfix"}
TERMINAL={"done","wontfix"}
TYPES={"epics":("EPIC","-EPIC.md"),"tickets":("TICKET","-TICKET.md"),"tasks":("TASK","-TASK.md")}
def fm(path):
    text=path.read_text(encoding="utf-8")
    if not text.startswith("---\n"): raise ValueError("missing frontmatter")
    _,body,_=text.split("---",2)
    return {key.strip(): value.strip() for key, value in (line.split(":",1) for line in body.strip().splitlines() if ":" in line)}
def main():
    parser=argparse.ArgumentParser(); parser.add_argument("--write",action="store_true"); parser.parse_args()
    errors=[]; records={}
    for folder,(prefix,suffix) in TYPES.items():
        for path in sorted((P/folder).glob("*"+suffix)):
            try: d=fm(path)
            except ValueError as error: errors.append(f"{path.relative_to(ROOT)}: {error}"); continue
            ident=d.get("id","")
            if not re.fullmatch(prefix+"-[0-9]{5}",ident): errors.append(f"{path.relative_to(ROOT)}: invalid id {ident!r}")
            if ident in records: errors.append(f"duplicate id {ident}")
            if d.get("status") not in STATUS: errors.append(f"{path.relative_to(ROOT)}: invalid status")
            if not d.get("title"): errors.append(f"{path.relative_to(ROOT)}: missing title")
            records[ident]=(path,d)
    for ident,(path,d) in records.items():
        parent=d.get("epic") or d.get("ticket")
        if parent and parent not in records: errors.append(f"{path.relative_to(ROOT)}: unknown parent {parent}")
        for blocker in (value.strip() for value in d.get("blocked_by","").split(",") if value.strip()):
            if blocker not in records or not blocker.startswith("TASK-"): errors.append(f"{path.relative_to(ROOT)}: invalid blocker {blocker}")
    visiting=set(); visited=set()
    def visit(ident):
        if ident in visiting: errors.append(f"dependency cycle at {ident}"); return
        if ident in visited: return
        visiting.add(ident)
        for blocker in (value.strip() for value in records[ident][1].get("blocked_by","").split(",") if value.strip()): visit(blocker)
        visiting.remove(ident); visited.add(ident)
    for ident in records:
        if ident.startswith("TASK-"): visit(ident)
    link=re.compile(r"!?\[[^\]]*\]\(([^\s)]+)")
    for path in P.rglob("*.md"):
        if path.name.startswith("_"): continue
        for value in link.findall(path.read_text()):
            target=value.partition("#")[0]
            if target.endswith(".md") and not target.startswith(("http:","https:","/")) and not (path.parent/target).resolve().is_file(): errors.append(f"{path.relative_to(ROOT)}: broken link {value}")
    if subprocess.run(["git","-c",f"safe.directory={ROOT.resolve()}","check-ignore","-q",".runs/planning-check"],cwd=ROOT).returncode: errors.append(".runs must be ignored")
    if errors:
        print("Planning validation failed:")
        print("\n".join("- "+error for error in errors)); return 1
    active=sum(d["status"] not in TERMINAL for _,d in records.values())
    print(f"Planning validation passed: {len(records)} records, {active} active"); return 0
if __name__=="__main__": sys.exit(main())
