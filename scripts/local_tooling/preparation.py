"""Resolve native Git registrations for project-owned local tooling."""
import hashlib
import json
from pathlib import Path

from .resources import registrations, require


def identity(safe, checkout):
    return 'fight-wt-' + hashlib.sha256((str(safe) + '\0' + str(checkout)).encode()).hexdigest()[:12]


def resolve(root, selector=None, removed=False):
    entries = registrations(root)
    safe = Path(entries[0]['path'])
    if selector is None:
        return safe, safe, safe.name, entries[0]
    found = [entry for entry in entries if selector in
             (entry['path'], entry['branch'], Path(entry['path']).name)]
    require(len(found) <= 1, 'Ambiguous worktree; use the exact registered path')
    if found:
        checkout = Path(found[0]['path'])
        return safe, checkout, identity(safe, checkout), found[0]
    require(removed and Path(selector).is_absolute(), 'Expected a registered worktree or exact retired absolute path')
    checkout = Path(selector)
    project = identity(safe, checkout)
    locator = safe / 'var/worktrees' / project / 'locator.json'
    entry = json.loads(locator.read_text()) if locator.is_file() else None
    require(entry is None or (entry['path'] == str(checkout) and entry['project'] == project), 'Locator identity mismatch')
    return safe, checkout, project, entry
