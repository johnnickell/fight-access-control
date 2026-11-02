"""Source-removal guards, independent of explicit disposable Docker teardown."""
import json
import os
from pathlib import Path, PurePosixPath
import stat
import subprocess
import sys

from .resources import query, require, text


def parent_mount_unused(container, mount, checkout, source, safe):
    """Qualify parent-only exposure without stopping unrelated application services"""
    target = str(PurePosixPath(mount['Destination']) / checkout.relative_to(source))
    require(container['State']['Running'], 'Parent source mount requires live consumer qualification')
    configured = json.dumps({key: container['Config'].get(key) for key in ('WorkingDir', 'Entrypoint', 'Cmd')})
    require(target not in configured, 'Container configuration consumes candidate source')
    # Inspect every live container process as root for complete /proc visibility.
    # Return status only: no process arguments, descriptors or private data escape.
    script = r'''
for process in /proc/[0-9]*; do
    [ "$process" = "/proc/$$" ] && continue
    [ -d "$process" ] || continue
    [ -r "$process/cmdline" ] && [ -r "$process/maps" ] && [ -r "$process/fd" ] || exit 2
    grep -F -q -- "$1" "$process/cmdline" "$process/maps"
    status=$?
    [ "$status" -eq 0 ] && exit 3
    [ "$status" -eq 1 ] || exit 2
    for descriptor in "$process/cwd" "$process/exe" "$process"/fd/*; do
        [ -L "$descriptor" ] || continue
        value=$(readlink "$descriptor") || { [ ! -L "$descriptor" ] && continue; exit 2; }
        case "$value" in "$1"|"$1"/*) exit 3 ;; esac
    done
done
'''
    result = subprocess.run(['docker', 'exec', '--user', '0', container['Id'],
                             'sh', '-c', script, 'source-consumer-check', target],
                            cwd=safe, capture_output=True, text=True, timeout=60)
    require(result.returncode == 0, 'Parent source consumer present or visibility unavailable')


def readonly_docker_handles(output, safe):
    """Admit only positively identified macOS Docker file-sharing read handles"""
    records, process, descriptor = [], {}, None
    for line in output.splitlines():
        key, value = line[:1], line[1:]
        if key == 'p':
            process, descriptor = {'pid': value}, None
        elif key == 'u':
            process['uid'] = value
        elif key == 'f':
            descriptor = {'process': process, 'fd': value}
            records.append(descriptor)
        elif descriptor is not None and key in ('a', 't'):
            descriptor[key] = value
    require(records, 'Source consumer visibility unavailable')
    qualified = set()
    for record in records:
        process = record['process']
        require(record.get('a') == 'r' and record.get('t') in ('REG', 'DIR') and
                record['fd'].removeprefix('*').isdigit() and process.get('uid') == str(os.getuid()),
                'Source has active/unknown cwd, writer or reader; retain source')
        pid = process['pid']
        if pid not in qualified:
            executable = text(['ps', '-p', pid, '-o', 'comm='], safe)
            require(executable == '/System/Library/Frameworks/Virtualization.framework/Versions/A/'
                                  'XPCServices/com.apple.Virtualization.VirtualMachine.xpc/Contents/MacOS/'
                                  'com.apple.Virtualization.VirtualMachine',
                    'Source reader is not qualified Docker file sharing')
            handles = text(['lsof', '-nP', '-p', pid, '-Fn'], safe).splitlines()
            disk = Path.home() / 'Library/Containers/com.docker.docker/Data/vms/0/data/Docker.raw'
            require(disk.is_file() and 'n' + str(disk) in handles,
                    'Virtualization reader lacks actual Docker runtime backing evidence')
            qualified.add(pid)


def check(checkout, safe):
    # Exact/child mounts always hold, including stopped containers. Parent-only
    # exposure needs positive live qualification, not shared shutdown.
    ids = text(['docker', 'ps', '-aq'], safe).splitlines()
    for container in query(['docker', 'inspect', *ids], safe) if ids else []:
        for mount in container['Mounts']:
            if mount['Type'] == 'bind':
                source = Path(mount['Source']).resolve()
                require(source != checkout and checkout not in source.parents,
                        'Source bind dependency remains; qualify the actual consumer before source removal')
                if source in checkout.parents:
                    parent_mount_unused(container, mount, checkout, source, safe)
    result = subprocess.run(['lsof', '-nP', '-Fpuaftn', '+D', str(checkout)], cwd=safe,
                            capture_output=True, text=True, timeout=60)
    require(result.returncode in (0, 1) and not result.stderr.strip(), 'Source consumer visibility unavailable')
    # All actual container mounts/processes were checked above. Cached read-only
    # Docker VM handles are not an active task; unknown readers/writers still hold.
    if result.stdout.strip():
        readonly_docker_handles(result.stdout, safe)
    for directory, dirs, files in os.walk(checkout, followlinks=False):
        for path in [Path(directory), *(Path(directory) / name for name in [*dirs, *files])]:
            info = path.lstat()
            require(info.st_uid == os.getuid() and not getattr(info, 'st_flags', 0), 'Source filesystem protection/foreign owner')
            require(not stat.S_ISSOCK(info.st_mode), 'Source socket remains')
            if sys.platform == 'darwin':
                require(len(text(['ls', '-lde', str(path)], safe).splitlines()) == 1,
                        'Source ACL protection; no automatic stripping')
