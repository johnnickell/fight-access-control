"""Reuse a successful local gate only after fresh planning and exact input/evidence checks."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import time

from .resources import Hold, require, run, text, write_private
from .runtime import configuration


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True).encode()).hexdigest()


def tree(path, exclude=None, seen=None):
    """Hash bytes, modes and resolved symlink contents; reject unreadable/special inputs."""
    exclude = exclude or (lambda value: False)
    seen = set() if seen is None else seen
    if exclude(path):
        return None
    info = path.lstat()
    require(path not in seen, 'Cyclic input link')
    seen = seen | {path}
    mode = stat.S_IMODE(info.st_mode)
    if path.is_symlink():
        target = path.resolve(strict=True)
        return digest(['link', mode, os.readlink(path), tree(target, seen=seen)])
    if path.is_dir():
        return digest(['directory', mode, [(child.name, tree(child, exclude, seen))
                      for child in sorted(path.iterdir()) if not exclude(child)]])
    require(stat.S_ISREG(info.st_mode), 'Special input file disables caching')
    return digest(['file', mode, hashlib.sha256(path.read_bytes()).hexdigest()])


def source(root, config):
    excluded = {'.git', '.runs', 'var', '.idea', '.vscode', '.DS_Store', 'graphify-out',
                '__pycache__', '.phpcs-cache', '.phpunit.cache', '.phpunit.result.cache', *config.get('outputs', [])}
    def skip(path):
        relative = path.relative_to(root)
        parts = relative.parts
        if parts and parts[0] in excluded:
            return True
        if relative.as_posix() in config.get('outputs', []):
            return True
        if path.name in ('.DS_Store', '__pycache__'):
            return True
        if relative.as_posix() in ('client/node_modules/.cache', 'client/node_modules/.vite-temp'):
            return True
        if parts and parts[0] == 'planning' and path.is_file() and not path.is_symlink():
            return (path.suffix == '.md' and path.name not in ('CONVENTIONS.md', 'AGENTS.md', 'CLAUDE.md', 'SKILL.md')
                    and not path.name.endswith('_TEMPLATE.md') and not {'agents', 'adr'} & set(parts))
        return False
    require((root / 'vendor/autoload.php').is_file(), 'Installed dependencies are missing')
    # Generated trees may contain tracked entry points: those remain inputs even
    # when untracked reports/cache files are verified as output artifacts.
    tracked_runtime = text(['git', 'ls-files', '-z', '--', 'var', *config.get('outputs', [])], root).split('\0')
    return digest([tree(root, skip), {name: tree(root / name) for name in tracked_runtime if name}])


def artifacts(root, config):
    return {value: tree(root / value) for value in config.get('outputs', [])}


def main(root):
    config = configuration(root)
    require(all(arg in ['--force', *config.get('build_options', [])] for arg in sys.argv[1:]),
            'Usage: ./bin/build [--force]')
    cache = root / 'var/build.json'
    cache.parent.mkdir(parents=True, exist_ok=True)
    with (root / 'var/build.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        force = '--force' in sys.argv[1:]
        if force:
            cache.unlink(missing_ok=True)
        # Planning failures preserve the old application success but fail this invocation.
        planning = tree(root / 'planning')
        run([root / 'bin/planning-check'], root)
        require(planning == tree(root / 'planning'), 'Planning changed during validation')
        source_fingerprint = None
        try:
            source_fingerprint = source(root, config)
        except (Hold, OSError, ValueError, KeyError) as error:
            print('Build cache unavailable: ' + str(error) + '; executing full gate', flush=True)
        reason = 'Explicit --force' if force else 'No qualifying successful receipt'
        if cache.is_file() and not force and source_fingerprint is not None:
            try:
                pointer = json.loads(cache.read_text())
                receipt = root / pointer['receipt']
                require(receipt.resolve().is_relative_to(root / '.runs/build'), 'Invalid receipt path')
                evidence = json.loads(receipt.read_text())
                require(pointer['schema'] == 2 and evidence['schema'] == 2
                        and evidence['exit'] == 0 and evidence['verification_exit'] == 0,
                        'Invalid original receipt')
                require(pointer['receipt_sha256'] == tree(receipt), 'Original receipt changed')
                require(evidence['source'] == source_fingerprint, 'Source/dependencies/instructions changed')
                require(evidence['outputs'] == artifacts(root, config), 'Generated artifacts changed or missing')
                require(evidence['log_sha256'] == tree(receipt.parent / 'build.log'), 'Original evidence changed')
                require(source_fingerprint == source(root, config),
                        'Inputs changed during reuse checks')
                require(planning == tree(root / 'planning'), 'Planning changed during reuse checks')
                print('Planning validated freshly. Application gate reused: ' + str(receipt), flush=True)
                return 0
            except (Hold, OSError, ValueError, KeyError) as error:
                reason = str(error)
        cache.unlink(missing_ok=True)
        print('Build cache miss: ' + reason, flush=True)
        # Dependency resolution belongs only to a miss. Capture its resulting
        # inputs before running the gate so later builds can reuse that result.
        prepare = config.get('build_prepare', [])
        if prepare:
            run(prepare, root)
            try:
                source_fingerprint = source(root, config)
            except (Hold, OSError, ValueError, KeyError) as error:
                source_fingerprint = None
                print('Build cache unavailable after preparation: ' + str(error), flush=True)
        run_dir = root / '.runs/build' / (time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()) + '-' + str(os.getpid()))
        run_dir.mkdir(parents=True, exist_ok=False)
        started = time.time()
        receipt = {'schema': 2, 'command': config['gate'], 'started': started, 'exit': None, 'cache_miss': reason}
        write_private(run_dir / 'receipt.json', json.dumps(receipt, indent=2) + '\n')
        with (run_dir / 'build.log').open('w') as log:
            process = subprocess.Popen(config['gate'], cwd=root, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            for line in iter(process.stdout.readline, b''):
                sys.stdout.buffer.write(line)
                sys.stdout.buffer.flush()
                log.write(line.decode(errors='replace'))
                log.flush()
            status = process.wait()
        receipt.update(exit=status, finished=time.time())
        cacheable = status == 0 and source_fingerprint is not None
        if cacheable:
            try:
                require(source_fingerprint == source(root, config), 'Build inputs changed during execution; no cache published')
                require(planning == tree(root / 'planning'), 'Planning changed during execution; no cache published')
                receipt.update(source=source_fingerprint, outputs=artifacts(root, config),
                               log_sha256=tree(run_dir / 'build.log'))
            except (Hold, OSError, ValueError, KeyError) as error:
                receipt['verification_error'] = str(error)
                print('Build verification incomplete: ' + str(error), file=sys.stderr, flush=True)
                status = 1
                cacheable = False
        receipt['verification_exit'] = status
        receipt['cacheable'] = cacheable
        write_private(run_dir / 'receipt.json', json.dumps(receipt, indent=2) + '\n')
        if cacheable:
            write_private(cache, json.dumps({'schema': 2, 'receipt': str((run_dir / 'receipt.json').relative_to(root)),
                                            'receipt_sha256': tree(run_dir / 'receipt.json')}) + '\n')
        print('Build receipt: ' + str(run_dir / 'receipt.json'), flush=True)
        return status


if __name__ == '__main__':
    try:
        sys.exit(main(Path.cwd().resolve()))
    except (Hold, OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
        print('Build incomplete: ' + str(error), file=sys.stderr)
        sys.exit(1)
