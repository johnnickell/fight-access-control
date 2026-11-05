#!/usr/bin/env python3
"""Discover PHP contracts without executing source; reconcile structural ownership only."""
import argparse
import collections
import hashlib
import json
from pathlib import Path
import re
import stat
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
DECLARATIONS = {'Stmt_Class', 'Stmt_Interface', 'Stmt_Trait', 'Stmt_Enum', 'Stmt_Function'}
NAMED_MEMBERS = {'Stmt_ClassMethod', 'Stmt_EnumCase'}
BOUNDARIES = DECLARATIONS | NAMED_MEMBERS | {
    'Stmt_Property', 'PropertyItem', 'Stmt_ClassConst', 'Stmt_Const', 'Const', 'Param', 'PropertyHook',
    'Stmt_Use', 'Stmt_GroupUse',
    'Expr_Closure', 'Expr_ArrowFunction', 'ClosureUse', 'Attribute', 'Stmt_TraitUse',
    'Expr_FuncCall', 'Expr_MethodCall', 'Expr_NullsafeMethodCall', 'Expr_StaticCall',
    'Expr_New', 'Expr_PropertyFetch', 'Expr_NullsafePropertyFetch', 'Expr_StaticPropertyFetch',
}
SEMANTICS = ('actual_contract', 'callers', 'input', 'result', 'absence', 'failure',
             'validation', 'permissions', 'secrets', 'events_effects', 'finding', 'disposition', 'verification')


def require(condition, message):
    if not condition:
        raise ValueError(message)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True)


def digest(value):
    return hashlib.sha256(canonical(value).encode()).hexdigest()


def load(path):
    return json.loads(path.read_text())


def save(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + '.tmp')
    temporary.write_text(json.dumps(value, sort_keys=True, indent=2) + '\n')
    temporary.replace(path)


def files(root, roots):
    require(roots == ['src', 'openapi'], 'Discovery must include exactly src and openapi')
    result = {}
    def visit(directory):
        info = directory.lstat()
        require(stat.S_ISDIR(info.st_mode), 'Missing, linked or invalid discovery directory: ' + str(directory))
        require(info.st_mode & 0o444 and info.st_mode & 0o111, 'Unreadable discovery directory: ' + str(directory))
        for entry in sorted(directory.iterdir()):
            info = entry.lstat()
            require(not stat.S_ISLNK(info.st_mode), 'Linked discovery input: ' + str(entry))
            if stat.S_ISDIR(info.st_mode):
                visit(entry)
            elif entry.suffix.lower() == '.php':
                require(stat.S_ISREG(info.st_mode) and info.st_mode & 0o444,
                        'Unreadable or special discovery input: ' + str(entry))
                result[entry.relative_to(root).as_posix()] = hashlib.sha256(entry.read_bytes()).hexdigest()
    for name in roots:
        start = len(result)
        visit(root / name)
        require(len(result) > start, 'Empty discovery root: ' + name)
    return result


def matches(rule, path):
    return re.fullmatch(rule['path'], path) and not re.fullmatch(rule.get('except', '(?!)'), path)


def validate_config(config, root):
    require(config['schema'] == 1 and config['rules'], 'Invalid ownership configuration')
    for rule in config['rules'] + config['concerns']:
        require(re.fullmatch(r'TASK-\d{5}', rule['task']), 'Invalid correction task')
        require(re.fullmatch(r'TICKET-\d{5}', rule['ticket']), 'Invalid requirement owner')
        task = (root / 'planning/tasks' / (rule['task'][5:] + '-TASK.md')).read_text()
        require(re.search(r'^ticket: ' + rule['ticket'] + r'\s*$', task, re.M), 'Correction task/parent mismatch')
        re.compile(rule['path'])
        re.compile(rule.get('except', '(?!)'))
    require(len({canonical(r) for r in config['concerns']}) == len(config['concerns']), 'Duplicate concern rule')


def clean(value):
    """Keep normalized native/static declarations, not parser coordinates or implementation bodies."""
    if isinstance(value, list):
        return [clean(item) for item in value]
    if not isinstance(value, dict):
        return value
    result = {key: clean(item) for key, item in value.items() if key not in ('attributes', 'stmts')}
    comments = (value.get('attributes') or {}).get('comments', [])
    docs = [c['text'] for c in comments if c.get('nodeType') == 'Comment_Doc']
    if docs:
        result['phpdoc'] = docs
    return result


def name(value):
    if isinstance(value, str):
        return value
    if isinstance(value, dict):
        return name(value.get('name', value.get('var', 'dynamic')))
    return 'anonymous'


def header(node):
    kind = node['nodeType']
    if kind in DECLARATIONS | NAMED_MEMBERS | {'Expr_Closure', 'Expr_ArrowFunction', 'PropertyHook'}:
        return clean({key: value for key, value in node.items() if key not in ('stmts', 'expr')})
    if kind.startswith('Expr_') and kind not in {'Expr_Closure', 'Expr_ArrowFunction'}:
        # Static syntax is a relationship hint, not resolved dispatch or a caller judgment.
        return {key: clean(node[key]) for key in ('nodeType', 'name', 'class') if key in node} | {
            'receiver': name(node.get('var')), 'first_class_callable': any(
                a.get('nodeType') == 'VariadicPlaceholder' for a in node.get('args', []))}
    return clean(node)


def discover(root, config):
    before = files(root, config['roots'])
    process = subprocess.run(['php', str(ROOT / 'scripts/inventory_ast.php')], cwd=root,
                             input=json.dumps(list(before)), text=True, capture_output=True, timeout=120)
    require(process.returncode == 0, 'PHP AST discovery failed: ' + process.stderr[-4000:])
    trees = json.loads(process.stdout)
    require(set(trees) == set(before), 'Partial AST discovery')
    records = []
    unique = set()
    declarations = set()
    for path, tree in sorted(trees.items()):
        require(isinstance(tree, list), 'Incomplete AST: ' + path)
        owners = [r for r in config['rules'] if matches(r, path)]
        require(len(owners) == 1, f'Expected one correction owner, found {len(owners)}: {path}')
        owner = {key: owners[0][key] for key in ('ticket', 'task')}
        concerns = [{key: rule[key] for key in ('ticket', 'task')}
                    for rule in config['concerns'] if matches(rule, path)]
        require(len({o['task'] for o in [owner, *concerns]}) == 1 + len(concerns),
                'Duplicate contract concern owner: ' + path)
        counters = collections.Counter()
        def record(identifier, kind, contract, line, parent):
            require(identifier not in unique, 'Duplicate boundary: ' + identifier)
            unique.add(identifier)
            records.append({'id': identifier, 'kind': kind, 'path': path, 'line': line, 'parent': parent,
                            'owner': owner, 'concerns': concerns, 'contract': contract,
                            'contract_hash': digest(contract), 'source_hash': before[path]})
        file_id = 'file:' + path
        record(file_id, 'file', {}, 1, None)
        def walk(value, parent, namespace='', enclosing_type=''):
            if isinstance(value, list):
                for item in value:
                    walk(item, parent, namespace, enclosing_type)
                return
            if not isinstance(value, dict) or 'nodeType' not in value:
                return
            kind = value['nodeType']
            if kind == 'Stmt_Namespace':
                namespace = name(value['name']) if value['name'] else ''
            identifier = parent
            docs = [c['text'] for c in (value.get('attributes') or {}).get('comments', [])
                    if c.get('nodeType') == 'Comment_Doc']
            if kind in BOUNDARIES or docs:
                label = name(value.get('name'))
                if kind in DECLARATIONS and value.get('name'):
                    label = name(value.get('namespacedName', namespace + '\\' + label)).lstrip('\\')
                    identifier = ('function:' if kind == 'Stmt_Function' else 'type:') + label
                elif kind in NAMED_MEMBERS:
                    identifier = parent + ('::case:' + label if kind == 'Stmt_EnumCase' else '::' + label + '()')
                elif kind == 'PropertyItem':
                    identifier = enclosing_type + '::$' + label
                elif kind == 'Const':
                    identifier = (enclosing_type or namespace) + '::constant:' + label
                elif kind == 'Param':
                    identifier = parent + '/param:$' + name(value['var'])
                else:
                    if kind == 'Stmt_Property':
                        label = ','.join(name(p['name']) for p in value['props'])
                    elif kind in {'Stmt_ClassConst', 'Stmt_Const'}:
                        label = ','.join(name(p['name']) for p in value['consts'])
                    elif kind.startswith('Expr_'):
                        label = name(value.get('class', value.get('name', kind)))
                    key = parent + '/' + kind + ':' + label
                    counters[key] += 1
                    identifier = key + '#' + str(counters[key])
                if kind in DECLARATIONS | NAMED_MEMBERS | {'PropertyItem', 'Const'}:
                    # PHP type/function/method names are case-insensitive, including across files.
                    collision = identifier.lower() if kind in DECLARATIONS | {'Stmt_ClassMethod'} else identifier
                    require(collision not in declarations, 'Duplicate declaration: ' + identifier)
                    declarations.add(collision)
                contract = header(value) if kind in BOUNDARIES else {'nodeType': kind, 'phpdoc': docs}
                record(identifier, kind, contract, (value.get('attributes') or {}).get('startLine'), parent)
                if kind in DECLARATIONS - {'Stmt_Function'}:
                    enclosing_type = identifier
            for key, child in value.items():
                if key not in ('attributes', 'namespacedName'):
                    walk(child, identifier, namespace, enclosing_type)
        walk(tree, file_id)
    require(before == files(root, config['roots']), 'Discovery inputs changed during parsing')
    return before, sorted(records, key=lambda r: r['id'])


def structural(records):
    groups = collections.defaultdict(list)
    for record in records:
        groups[record['owner']['task']].append({key: record[key] for key in
                                              ('id', 'kind', 'path', 'owner', 'concerns', 'contract_hash')})
    return {'schema': 1, 'owners': {task: {'boundaries': len(rows), 'sha256': digest(rows)}
                                   for task, rows in sorted(groups.items())}}


def difference(previous, records):
    require(previous.get('schema') == 1 and previous.get('discovery_complete') is True,
            'Previous inventory is incomplete or unsupported')
    prior_records = previous['boundaries']
    require(previous['summary']['boundaries'] == len(prior_records)
            and previous['summary']['files'] == len(previous['identity']['files'])
            and previous['candidate'] == digest(previous['identity'])
            and previous['structure'] == structural(prior_records), 'Partial or inconsistent previous inventory')
    require({r['path'] for r in prior_records if r['kind'] == 'file'} == set(previous['identity']['files']),
            'Missing previous file boundaries')
    require(all(previous['identity']['files'].get(r['path']) == r['source_hash'] for r in prior_records),
            'Inconsistent previous source identity')
    old = {r['id']: r for r in prior_records}
    require(len(old) == len(previous['boundaries']), 'Duplicate previous boundary')
    new = {r['id']: r for r in records}
    result = {'added': sorted(new.keys() - old.keys()), 'removed': sorted(old.keys() - new.keys()),
              'moved': [], 'changed': []}
    for identifier in sorted(old.keys() & new.keys()):
        if old[identifier]['path'] != new[identifier]['path']:
            result['moved'].append(identifier)
        if any(old[identifier][key] != new[identifier][key] for key in ('owner', 'concerns', 'contract_hash')):
            result['changed'].append(identifier)
    return result


def judgments(path, candidate, records):
    supplied = load(path) if path else {'candidate': candidate, 'records': []}
    require(supplied['candidate'] == candidate, 'Stale judgment candidate')
    lookup = {r['id']: r for r in records}
    seen = set()
    for judgment in supplied['records']:
        identifier, task = judgment['boundary'], judgment['task']
        require(identifier in lookup, 'Unknown judgment boundary: ' + identifier)
        record = lookup[identifier]
        require(task in [o['task'] for o in [record['owner'], *record['concerns']]], 'Wrong judgment owner')
        require((identifier, task) not in seen, 'Duplicate judgment')
        seen.add((identifier, task))
        require(judgment['status'] in ('pending', 'corrected', 'unchanged'), 'Unsupported judgment status')
        require(all(isinstance(judgment.get(key), str) and judgment[key].strip() for key in SEMANTICS),
                'Judgment needs contract/callers/semantics/disposition/verification; justify each N/A')
        require(isinstance(judgment.get('evidence'), list), 'Missing judgment evidence list')
        if judgment['status'] != 'pending':
            require(judgment['evidence'] and all(isinstance(e, str) and e.strip() for e in judgment['evidence']),
                    'Verified disposition requires evidence locators')
        record.setdefault('judgments', {})[task] = judgment
    for record in records:
        for owner in [record['owner'], *record['concerns']]:
            record.setdefault('judgments', {}).setdefault(owner['task'], {
                'boundary': record['id'], 'task': owner['task'], 'status': 'pending', 'evidence': [],
                **{key: 'Pending owner review; discovery supplies no semantic judgment' for key in SEMANTICS}})
    # Supplied judgments are owner assertions, never independent acceptance or verified evidence by this checker.


def run(args):
    root = args.root.resolve()
    config = load(root / 'etc/contract-owners.json')
    validate_config(config, root)
    inputs, records = discover(root, config)
    tools = {p: hashlib.sha256((ROOT / p).read_bytes()).hexdigest() for p in
             ('scripts/inventory_ast.php', 'scripts/contract_inventory.py')}
    identity = {'files': inputs, 'configuration': digest(config), 'tools': tools,
                'lockfile': hashlib.sha256((root / 'composer.lock').read_bytes()).hexdigest(),
                'installed': hashlib.sha256((ROOT / 'vendor/composer/installed.json').read_bytes()).hexdigest()}
    candidate = digest(identity)
    head = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=root, text=True, capture_output=True, check=True).stdout.strip()
    observed = structural(records)
    lock = root / 'etc/contract-inventory.json'
    require(args.refresh or lock.is_file(), 'Missing structural inventory; review ownership and explicitly --refresh')
    expected = observed if args.refresh else load(lock)
    drift = observed != expected
    changed = sorted(task for task in observed['owners'].keys() | expected['owners'].keys()
                     if observed['owners'].get(task) != expected['owners'].get(task))
    output = {'schema': 1, 'discovery_complete': True, 'structural_match': not drift,
              'semantic_audit_complete': False, 'candidate': candidate, 'identity': identity, 'head': head,
              'summary': {'files': len(inputs), 'boundaries': len(records),
                          'kinds': dict(sorted(collections.Counter(r['kind'] for r in records).items()))},
              'drift_owners': changed, 'structure': observed, 'boundaries': records}
    if args.previous:
        output['difference'] = difference(load(args.previous), records)
    judgments(args.judgments, candidate, records)
    if args.refresh:
        save(lock, observed)
    if args.output:
        save(args.output, output)
    print(f"Contract inventory: {len(inputs)} files, {len(records)} boundaries; semantic judgments remain owner-supplied")
    if drift:
        print('Structural ownership drift: ' + ', '.join(changed) + '; inspect --output/--previous before --refresh', file=sys.stderr)
    else:
        print('Structural ownership matches; this is not semantic audit completion')
    return output


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT)
    parser.add_argument('--output', type=Path, help='Ignored candidate evidence JSON')
    parser.add_argument('--previous', type=Path, help='Earlier complete candidate inventory for detailed drift')
    parser.add_argument('--judgments', type=Path, help='Private owner-supplied candidate-bound evidence fragment')
    parser.add_argument('--refresh', action='store_true', help='Explicitly replace tracked structural fingerprints')
    args = parser.parse_args()
    try:
        result = run(args)
        return 0 if result['structural_match'] else 1
    except (OSError, ValueError, KeyError, TypeError, AttributeError, subprocess.SubprocessError) as error:
        if args.output and not isinstance(error, BrokenPipeError):
            save(args.output, {'schema': 1, 'discovery_complete': False, 'semantic_audit_complete': False,
                               'error': str(error)})
        print('Contract inventory failed: ' + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
