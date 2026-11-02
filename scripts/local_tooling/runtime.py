"""Explicit checkout-scoped Compose operations; shared caches are never pruned."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys

from .preparation import resolve
from .resources import Hold, native_ids, query, registrations, require, run, text, write_private


def configuration(root):
    return json.loads((root / 'etc/local-tooling.json').read_text())


def environment():
    return {key: value for key, value in os.environ.items() if not key.startswith('COMPOSE_')}


def selected(root, selector=None, main=False, removed=False):
    if selector is None and not main:
        selector = str(root)
    safe, checkout, project, entry = resolve(root, selector, removed)
    isolated = checkout != safe
    if not isolated:
        project = configuration(root)['project']
    return safe, checkout, project, entry, isolated


def compose(root, selector=None, main=False):
    safe, checkout, project, entry, isolated = selected(root, selector, main)
    require(checkout.is_dir(), 'Selected source is absent')
    for relative in ('vendor', 'var', 'client/node_modules'):
        base = checkout / relative
        require(not base.is_symlink() and (not base.exists() or base.is_dir()), 'Mutable tree must belong to this checkout: ' + relative)
        if base.exists():
            for directory, directories, files in os.walk(base, followlinks=False):
                for name in directories + files:
                    path = Path(directory) / name
                    require(not path.is_symlink() or path.resolve().is_relative_to(checkout),
                            'Mutable-tree symlink escapes this checkout')
    env = environment()
    env.update({key: value.format(project=project, checkout=checkout, uid=os.getuid(), gid=os.getgid())
                for key, value in configuration(root).get('environment', {}).items()})
    env['FIGHT_CHECKOUT'] = str(checkout)
    env['FIGHT_PROJECT'] = project
    env['FIGHT_UID'] = str(os.getuid())
    env['FIGHT_GID'] = str(os.getgid())
    # Keep explicit configuration independent of ambient overrides and .env discovery.
    args = ['docker', 'compose', '--project-directory', str(checkout), '--project-name', project,
            '--env-file', str(checkout / '.env') if not isolated and (checkout / '.env').is_file() else '/dev/null',
            '--file', str(checkout / 'compose.yaml')]
    model = query([*args, 'config', '--format=json'], checkout, env)
    require(not any(value.get('external') for group in ('volumes', 'networks')
                    for value in model.get(group, {}).values()), 'Shared external resources require a separate operator')
    services = {}
    common = Path(text(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], checkout))
    for name, value in model['services'].items():
        settings = {'labels': {'io.fight.checkout': str(checkout)}}
        if value.get('build'):
            settings['image'] = project + '-' + name
        if isolated and value.get('ports'):
            settings['ports'] = '!override ' + json.dumps([
                {'target': port['target'], 'published': '0', 'host_ip': '127.0.0.1',
                 'protocol': port.get('protocol', 'tcp')} for port in value['ports']])
        mounts = []
        for mount in value.get('volumes', []):
            if mount['type'] == 'bind':
                source = Path(mount['source']).resolve()
                require(source == checkout or checkout in source.parents,
                        'Source configuration references storage outside this checkout')
        if configuration(root).get('mount_git', True) and (checkout / '.git').is_file():
            mounts.append({'type': 'bind', 'source': str(common), 'target': str(common), 'read_only': True})
        if mounts:
            settings['volumes'] = mounts
        services[name] = settings
    override = ['services:']
    for name, settings in services.items():
        override.append('  ' + name + ':')
        for key, value in settings.items():
            override.append('    ' + key + ': ' + (value if key == 'ports' else json.dumps(value)))
    for group in ('volumes', 'networks'):
        if model.get(group):
            override.append(group + ':')
            for name in model[group]:
                override += ['  ' + name + ':', '    name: ' + json.dumps(project + '_' + name),
                             '    labels: ' + json.dumps({'io.fight.checkout': str(checkout)})]
    state = safe / 'var/worktrees' / project
    require(state.resolve() == state, 'Runtime configuration alias refused')
    write_private(state / 'override.yaml', '\n'.join(override) + '\n')
    write_private(state / 'locator.json', json.dumps({**entry, 'project': project}) + '\n')
    args += ['--file', str(state / 'override.yaml')]
    return args, env, (safe, checkout, project, entry, isolated)


def container_inventory(root):
    # Concurrent unrelated --rm jobs may disappear between native list/inspect.
    # Retry only after a fresh list proves that exact race; an unchanged failing
    # inventory remains an error, never evidence of absence.
    for attempt in range(3):
        ids = text(['docker', 'ps', '-aq'], root).splitlines()
        try:
            return query(['docker', 'inspect', *ids], root) if ids else []
        except Hold:
            current = text(['docker', 'ps', '-aq'], root).splitlines()
            require(set(ids) != set(current), 'Docker container inventory unavailable')
    raise Hold('Docker container inventory changed repeatedly; retry after concurrent jobs finish')


def guard(root, checkout, project):
    containers = container_inventory(root)
    owned = set()
    for container in containers:
        labels = container['Config'].get('Labels') or {}
        if labels.get('com.docker.compose.project') != project:
            continue
        require(labels.get('io.fight.checkout') == str(checkout), 'Unknown/foreign project container; retain it')
        require(all(Path(m['Source']).resolve() == checkout for m in container['Mounts']
                    if m['Type'] == 'bind' and m['Destination'] == '/app'), 'Project container mounts another checkout')
        owned.add(container['Id'])
    for kind in ('volumes', 'networks'):
        for resource in native_ids(root, kind, project):
            value = query(['docker', kind[:-1], 'inspect', resource], root)[0]
            require((value.get('Labels') or {}).get('io.fight.checkout') == str(checkout),
                    'Unknown/foreign project ' + kind + '; retain it')
            if kind == 'volumes':
                require(not any(c['Id'] not in owned and any(m.get('Name') == value['Name']
                        for m in c['Mounts']) for c in containers), 'Volume has a foreign/stopped consumer')
            else:
                require(not any(c['Id'] not in owned and any(n.get('NetworkID') == value['Id']
                        for n in c['NetworkSettings']['Networks'].values()) for c in containers),
                        'Network has a foreign/stopped consumer')


def image_tags(root, project):
    expected = {project + '-' + role + ':latest' for role in configuration(root).get('images', [])}
    return expected & set(text(['docker', 'image', 'ls', '--format', '{{.Repository}}:{{.Tag}}'], root).splitlines())


def down(root, selector=None):
    safe, checkout, project, entry, isolated = selected(root, selector, main=True, removed=True)
    legacy = configuration(root).get('legacy_down', [])
    if legacy:
        run([*legacy, str(checkout)], root)
    guard(safe, checkout, project)
    # A source-free model uses native project labels for orphan discovery. No secrets or live source needed.
    state = safe / 'var/worktrees' / project
    if not state.exists() and not any(native_ids(safe, kind, project)
                                      for kind in ('containers', 'networks', 'volumes')) and not (isolated and image_tags(root, project)):
        print('Runtime already absent; shared images and caches retained', flush=True)
        return
    state.mkdir(parents=True, exist_ok=True)
    require(state.resolve() == state, 'Runtime configuration alias refused')
    cleanup = state / 'down.json'
    write_private(cleanup, json.dumps({'services': {}, 'name': project}) + '\n')
    command = ['docker', 'compose', '--project-directory', str(safe), '--project-name', project,
               '--env-file', '/dev/null', '--file', str(cleanup), 'down', '--remove-orphans']
    if isolated:
        command.append('--volumes')
    run(command, safe, env=environment())
    # Native Compose omits labeled plain `docker run` jobs such as docs previews.
    # Requalify ownership before retiring the exact remaining project containers.
    guard(safe, checkout, project)
    remaining = native_ids(safe, 'containers', project)
    if remaining:
        run(['docker', 'container', 'stop', *remaining], safe)
        guard(safe, checkout, project)
        remaining = native_ids(safe, 'containers', project)
        if remaining:
            run(['docker', 'container', 'rm', *remaining], safe)
    # Empty models cannot enumerate declared volumes/networks: retire remaining exact native objects.
    for kind in ('networks', 'volumes'):
        if kind == 'volumes' and not isolated:
            continue
        for resource in native_ids(safe, kind, project):
            guard(safe, checkout, project)
            run(['docker', kind[:-1], 'rm', resource], safe)
    for kind in ('containers', 'networks', 'volumes'):
        if kind != 'volumes' or isolated:
            require(not native_ids(safe, kind, project), 'Remaining project resources: ' + kind)
    if isolated:
        for tag in sorted(image_tags(root, project)):
            run(['docker', 'image', 'rm', tag], safe)
        require(not image_tags(root, project), 'Remaining task image tags')
    print('Runtime retired; shared images and build caches retained', flush=True)


def main(root):
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('up', 'down', 'docker', 'inspect', 'finish', 'image-name'))
    parser.add_argument('--worktree')
    parser.add_argument('--build-only', action='store_true')
    parser.add_argument('--outcome')
    parser.add_argument('--published-oid')
    parser.add_argument('--pr')
    args, argv = parser.parse_known_args()
    if args.action == 'image-name':
        require(len(argv) == 1 and argv[0].replace('-', '').isalnum(), 'Expected one image role')
        print(selected(root, args.worktree)[2] + '-' + argv[0])
        return 0
    if args.action == 'down':
        require(not argv, 'Unexpected down arguments')
        down(root, args.worktree)
        return 0
    if args.action == 'finish':
        require(not argv and args.worktree and args.outcome and args.published_oid and args.pr,
                'finish requires --worktree, --outcome, --published-oid and --pr')
        from .worktrunk import remove
        from .publication import published
        def delivery(safe, checkout, ref):
            outcome = Path(args.outcome).resolve()
            require(outcome.is_file() and checkout not in outcome.parents,
                    'Record final outcomes outside disposable source first')
            published({'safe': str(safe), 'ref': ref}, args.published_oid, ref, args.pr)
        return remove(root, args.worktree, args.published_oid, delivery)
    if args.action == 'docker' and argv[:1] != ['compose']:
        if argv[:1] == ['ps']:
            safe, checkout, project, entry, isolated = selected(root, args.worktree)
            guard(safe, checkout, project)
            argv = ['ps', '--filter', 'label=com.docker.compose.project=' + project, *argv[1:]]
        if argv[:1] == ['run']:
            safe, checkout, project, entry, isolated = selected(root, args.worktree)
            guard(safe, checkout, project)
            options = ['--label', 'com.docker.compose.project=' + project,
                       '--label', 'io.fight.checkout=' + str(checkout)]
            if configuration(root).get('mount_git', True) and (checkout / '.git').is_file():
                common = text(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], checkout)
                options += ['--mount', 'type=bind,source=' + common + ',target=' + common + ',readonly']
            argv = ['run', *options, *argv[1:]]
        return subprocess.run(['docker', *argv], cwd=root).returncode
    command, env, selection = compose(root, args.worktree, main=args.action == 'up')
    safe, checkout, project, entry, isolated = selection
    guard(safe, checkout, project)
    if args.action == 'up':
        require(not argv, 'Unexpected up arguments')
        prepare = configuration(root).get('startup', [])
        for directory in configuration(root).get('directories', []):
            (checkout / directory).mkdir(parents=True, exist_ok=True)
        if prepare:
            run(prepare, checkout, env=env)
        # Local gate evidence binds the immutable image ID. Avoid timestamped
        # attestation indexes replacing that ID when all executable layers match.
        run([*command, 'build', '--pull'], checkout,
            env={**env, 'BUILDX_NO_DEFAULT_ATTESTATIONS': '1'})
        setup = configuration(root).get('setup', [])
        if setup:
            run(setup, checkout, env=env)
        if not args.build_only:
            run([*command, 'up', '--detach', '--no-build', '--wait', '--wait-timeout', '120'], checkout, env=env)
            print('Compose ready: ' + project + '; inspect published ports with ./bin/runtime docker compose ps', flush=True)
    elif args.action == 'inspect':
        print(json.dumps({'checkout': str(checkout), 'project': project,
            'resources': {kind: native_ids(safe, kind, project) for kind in ('containers', 'networks', 'volumes')}}))
    else:
        return subprocess.run([*command, *argv[1:]], cwd=checkout, env=env).returncode
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main(Path.cwd().resolve()))
    except (Hold, OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
        print('Runtime incomplete: ' + str(error), file=sys.stderr)
        sys.exit(1)
