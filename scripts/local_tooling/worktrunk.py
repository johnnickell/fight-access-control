"""Guard native Worktrunk removal independently of disposable Docker teardown."""
import json
from pathlib import Path
import shutil
import subprocess

from .preparation import resolve
from .resources import query, registrations, require, run, text


DOWN_HOOK = '{{ primary_worktree_path }}/bin/down --worktree {{ worktree_path }}'


def removal_hooks(safe, checkout):
    """Inspect effective system/user/project hooks without disclosing personal commands"""
    for directory in dict.fromkeys((safe, checkout)):
        for phase in ('pre-remove', 'post-remove'):
            hooks = query(['wt', 'hook', 'show', phase, '--format=json'], directory)
            require(isinstance(hooks, list), 'Worktrunk hook inventory unavailable')
            require(all(isinstance(hook, dict) and phase == 'pre-remove' and
                        hook.get('type') == phase and hook.get('source') == 'project' and
                        hook.get('name') == 'runtime' and hook.get('template') == DOWN_HOOK
                        for hook in hooks),
                    'Unqualified Worktrunk ' + phase + ' hook in ' +
                    ('canonical' if directory == safe else 'target') +
                    ' context; source retained, Docker teardown completed')


def remove(root, selector, expected_oid, delivery=None):
    """Run Docker first, then one non-force native removal for delivery or owned fixtures

    Land supplies its exact publication check. Without it, only an explicitly owned
    qualification fixture with no commits outside canonical HEAD is eligible.
    Caller authority and live-source qualification remain required; this is not a sandbox.
    """
    safe, checkout, project, entry = resolve(root, selector, removed=True)
    require(root == safe, 'Run source removal from canonical safe checkout')
    # Always use the public command, also used by the repository pre-remove hook.
    # Source, publication and hook holds cannot suppress independent Docker teardown.
    run([safe / 'bin/down', '--worktree', str(checkout)], safe)
    if entry is None:
        require(not (safe / 'var/worktrees' / project).exists(),
                'Unlocated generated configuration remains; inspect exact state, never infer retirement')
        require(not checkout.exists() and not checkout.is_symlink() and
                str(checkout) not in [value['path'] for value in registrations(safe)],
                'No locator; original source path/registration must remain absent')
        print(json.dumps({'project': project, 'eligible_closeout': True, 'already_absent': True,
                          'source_absent': True, 'registration_absent': True, 'runtime_absent': True,
                          'config_absent': True, 'branch_outcome': 'not_observed'}))
        return 0
    require(checkout != safe and checkout != Path.cwd().resolve(),
            'Main/current checkout is never disposable; Docker teardown completed')
    branch = entry['branch']
    ref = 'refs/heads/' + branch if branch else None
    present = str(checkout) in [value['path'] for value in registrations(safe)]
    require(present == checkout.exists(), 'Source path/registration mismatch; inspect partial effects')

    def ref_oid():
        rows = text(['git', 'for-each-ref', '--format=%(refname) %(objectname)', ref], safe).splitlines() if ref else []
        return next((row.split()[1] for row in rows if row.split()[0] == ref), '')

    def complete(branch_outcome='already_absent'):
        def postconditions():
            entries = registrations(safe)
            require(not checkout.exists() and not checkout.is_symlink() and
                    not any(value['path'] == str(checkout) or (branch and value['branch'] == branch)
                            for value in entries),
                    'Worktrunk retained/recreated source or registered owner; inspect actual outcome, never force')
            require(ref_oid() == (expected_oid if branch_outcome == 'retained_unmerged' else ''),
                    'Worktrunk ref postcondition changed; source/config closeout incomplete')

        postconditions()
        # Final public down must also succeed; a source/ref hold never substitutes
        # for Docker absence. Pin the original path, not a reattached branch.
        run([safe / 'bin/down', '--worktree', str(checkout)], safe)
        postconditions()
        state = safe / 'var/worktrees' / project
        require(state.resolve() == state and not state.is_symlink(), 'Runtime state alias refused')
        if state.exists():
            shutil.rmtree(state)
        print(json.dumps({'project': project, 'eligible_closeout': True, 'source_absent': True,
                          'registration_absent': True, 'runtime_absent': True, 'config_absent': True,
                          'ref': ref, 'branch_outcome': branch_outcome,
                          'retained_oid': expected_oid if branch_outcome == 'retained_unmerged' else None}))
        return 0

    if not present and not ref_oid():
        return complete()

    def source_state():
        entries = registrations(safe)
        if present:
            current = next((value for value in entries if value['path'] == str(checkout)), None)
            require(current == entry, 'Source registration changed before removal')
            require(text(['git', 'rev-parse', 'HEAD'], checkout) == expected_oid,
                    'Source OID changed; unique work retained')
            require(not text(['git', 'status', '--porcelain', '--untracked-files=all'], checkout),
                    'Dirty/unique source retained')
            require(not text(['git', 'ls-files', '--others', '--ignored', '--exclude-standard'], checkout),
                    'Ignored/private output remains; dispose only known temporary output after consumers end')
        else:
            require(not checkout.exists() and not checkout.is_symlink() and
                    not any(value['path'] == str(checkout) for value in entries),
                    'Absent source path/registration changed before removal')
            require(not any(value['branch'] == branch for value in entries),
                    'Surviving ref is checked out in a registered worktree; source/ref retained')
        if ref:
            require(text(['git', 'rev-parse', ref], safe) == expected_oid,
                    'Exact source ref/OID changed; unique work retained')
        if not delivery:
            # Interrupted native removal may leave only the exact fixture ref.
            # The same canonical ancestry proof applies with or without source.
            run(['git', 'merge-base', '--is-ancestor', expected_oid, 'HEAD'], safe)

    source_state()
    if delivery:
        delivery(safe, checkout, ref)
    # Qualify installed capabilities rather than an equality-version gate. --yes
    # on remove is per-invocation consent, NOT `config approvals add --yes`.
    help_text = text(['wt', 'remove', '--help'], safe)
    require(all(option in help_text for option in ('--yes', '--foreground', '--format')),
            'Installed Worktrunk lacks qualified noninteractive removal support')
    if present:
        from .source import check
        check(checkout, safe)
    source_state()
    # Re-read complete effective pre/post hooks in BOTH contexts immediately before
    # the native effect, using the same inherited config/environment as removal.
    removal_hooks(safe, checkout if present else safe)
    # Hook inventory is native work too: revalidate ownership after it, before effect.
    source_state()
    target = str(checkout) if present else branch
    require(target, 'No exact native removal target')
    result = subprocess.run(['wt', 'remove', '--foreground', '--format=json', '--yes', target],
                            cwd=safe, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, text=True, timeout=120)
    require(result.returncode == 0, 'Worktrunk removal failed; inspect partial effects without force')
    # Exit zero alone includes declined/raced/failed native ref effects. Admit
    # only the exact foreground result and separately prove live postconditions.
    results = json.loads(result.stdout)
    require(isinstance(results, list) and len(results) == 1 and isinstance(results[0], dict),
            'Unknown Worktrunk removal result; inspect partial effects')
    actual = results[0]
    require(actual.get('kind') == ('worktree' if present else 'branch_only') and
            actual.get('branch') == branch and actual.get('branch_checked_out_at', 'unknown') is None and
            (actual.get('path') == str(checkout) if present else
             actual.get('detached_worktree', 'unknown') is None and isinstance(actual.get('pruned'), bool)) and
            actual.get('branch_outcome') in ('deleted', 'retained_unmerged'),
            'Unqualified Worktrunk removal outcome; inspect actual source/ref/registration, never force')
    return complete(actual['branch_outcome'])
