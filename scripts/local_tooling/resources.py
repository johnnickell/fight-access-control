"""Small native-command helpers; Compose owns resource identity, not a second ledger."""
import json
import os
from pathlib import Path
import subprocess


class Hold(Exception):
    pass


def require(condition, message):
    if not condition:
        raise Hold(message)


def run(argv, cwd, capture=False, env=None, timeout=1800, input=None):
    result = subprocess.run([str(v) for v in argv], cwd=cwd, env=env, timeout=timeout,
                            capture_output=capture, text=True, input=input)
    if result.returncode:
        # Captured Compose errors can contain resolved private settings. Native
        # streamed operations keep their actual diagnostics; never dump config.
        raise Hold('Command failed (exit ' + str(result.returncode) + '): ' + ' '.join(str(v) for v in argv[:3]))
    return result.stdout.strip() if capture else None


def text(argv, cwd):
    return run(argv, cwd, capture=True)


def query(argv, cwd, env=None):
    return json.loads(run(argv, cwd, capture=True, env=env))


def write_private(path, content):
    require(not path.is_symlink(), 'Refusing aliased runtime configuration: ' + str(path))
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = path.with_suffix(path.suffix + '.tmp')
    # Exclusive creation refuses an unexpected concurrent writer/alias.
    with temporary.open('x') as stream:
        os.chmod(temporary, 0o600)
        stream.write(content)
    temporary.replace(path)


def registrations(root):
    blocks = text(['git', 'worktree', 'list', '--porcelain', '-z'], root).split('\0\0')
    result = []
    for block in blocks:
        fields = dict(line.split(' ', 1) if ' ' in line else (line, '') for line in block.split('\0') if line)
        if 'worktree' in fields:
            result.append({'path': str(Path(fields['worktree']).resolve()),
                           'branch': fields.get('branch', '').removeprefix('refs/heads/')})
    require(result, 'No registered Git worktrees')
    return result


def native_ids(root, kind, project):
    noun = {'containers': 'ps', 'volumes': 'volume', 'networks': 'network'}[kind]
    argv = ['docker', noun]
    if kind != 'containers':
        argv += ['ls']
    argv += ['-aq' if kind == 'containers' else '-q', '--filter', 'label=com.docker.compose.project=' + project]
    return text(argv, root).splitlines()
