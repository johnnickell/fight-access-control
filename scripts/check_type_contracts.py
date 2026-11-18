"""Check precise PHPStan probes and case-bound rejections, never arbitrary analyzer failure."""
import argparse
from collections import Counter
import json
import os
from pathlib import Path
import signal
import subprocess
import sys


class ContractFailure(Exception):
    """A required fixture or analyzer result is incomplete or disagrees with its contract."""


def require(condition, message):
    if not condition:
        raise ContractFailure(message)


def load_cases(suite):
    document = json.loads((suite / 'cases.json').read_text())
    require(isinstance(document, dict) and set(document) == {'cases'}, 'Invalid suite document')
    cases = document['cases']
    require(isinstance(cases, list) and cases, 'Missing or empty fixture cases')
    ids, files, kinds = set(), set(), set()
    for case in cases:
        require(isinstance(case, dict) and set(case) == {'id', 'kind', 'file', 'diagnostics'},
                'Invalid case declaration')
        identity = case['id']
        require(isinstance(identity, str) and identity.strip() and identity not in ids, 'Missing/duplicate case id')
        ids.add(identity)
        require(case['kind'] in ('positive', 'negative'), f'{identity}: invalid case kind')
        kinds.add(case['kind'])
        name = case['file']
        require(isinstance(name, str) and name and not Path(name).is_absolute(), f'{identity}: invalid fixture path')
        file = (suite / name).resolve(strict=True)
        require(file.is_relative_to(suite) and file.suffix == '.php' and file.is_file(),
                f'{identity}: fixture must be a PHP file inside the suite')
        require(file not in files and file.read_text().strip(), f'{identity}: duplicate or empty fixture')
        files.add(file)
        diagnostics = case['diagnostics']
        require(isinstance(diagnostics, list) and diagnostics, f'{identity}: missing expected diagnostics')
        for diagnostic in diagnostics:
            require(isinstance(diagnostic, dict) and set(diagnostic) == {'line', 'identifier', 'message'},
                    f'{identity}: invalid diagnostic declaration')
            require(type(diagnostic['line']) is int and diagnostic['line'] > 0,
                    f'{identity}: invalid diagnostic line')
            require(all(isinstance(diagnostic[key], str) and diagnostic[key].strip()
                        for key in ('identifier', 'message')), f'{identity}: empty diagnostic identity/message')
            is_probe = diagnostic['identifier'] == 'phpstan.dumpType'
            require(is_probe == (case['kind'] == 'positive'), f'{identity}: incorrect diagnostic kind')
            if is_probe:
                require(diagnostic['message'].startswith('Dumped type: '), f'{identity}: invalid precision probe')
    require(kinds == {'positive', 'negative'}, 'Both positive and negative cases are required')
    discovered = {file.resolve(strict=True) for file in suite.rglob('*.php')}
    require(discovered == files, 'Unregistered or missing PHP fixtures: ' + str(discovered ^ files))
    return cases


def analyze(root, file, timeout):
    command = ['php', '-d', 'memory_limit=512M', str(root / 'vendor/bin/phpstan'), 'analyse',
               '-c', str(root / 'phpstan-type-contracts.neon'), '--no-progress', '--no-ansi',
               '--error-format=json', str(file)]
    process = subprocess.Popen(command, cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                               text=True, start_new_session=True)
    try:
        stdout, stderr = process.communicate(timeout=timeout)
    except (subprocess.TimeoutExpired, KeyboardInterrupt):
        os.killpg(process.pid, signal.SIGKILL)
        stdout, stderr = process.communicate()
        raise ContractFailure(f'Analyzer interrupted/timed out for {file}\n{stdout}\n{stderr}') from None
    return process.returncode, stdout, stderr


def diagnostic_key(diagnostic):
    return diagnostic['line'], diagnostic['identifier'], diagnostic['message']


def check_result(case, file, result):
    code, stdout, stderr = result
    identity = case['id']
    require(code == 1, f'{identity}: expected analyzer diagnostic exit 1, got {code}\n{stdout}\n{stderr}')
    require(not stderr.strip(), f'{identity}: unexpected analyzer stderr\n{stderr}')
    try:
        report = json.loads(stdout)
        require(isinstance(report, dict) and set(report) == {'totals', 'files', 'errors'},
                f'{identity}: incomplete analyzer report')
        expected = Counter(diagnostic_key(diagnostic) for diagnostic in case['diagnostics'])
        require(report['errors'] == [] and report['totals'] == {'errors': 0, 'file_errors': sum(expected.values())},
                f'{identity}: unexpected global errors or diagnostic totals')
        require(isinstance(report['files'], dict) and set(report['files']) == {str(file)},
                f'{identity}: unexpected/missing diagnostic file')
        output = report['files'][str(file)]
        require(output['errors'] == sum(expected.values()) and isinstance(output['messages'], list),
                f'{identity}: incomplete diagnostic messages')
        actual = Counter(diagnostic_key(diagnostic) for diagnostic in output['messages'])
        require(expected == actual,
                f'{identity}: diagnostic mismatch\nMissing: {expected - actual}\nUnexpected: {actual - expected}')
    except (KeyError, TypeError, ValueError) as error:
        raise ContractFailure(f'{identity}: malformed analyzer output: {error}\n{stdout}') from error


def run_suite(root, suite, timeout=120):
    require(timeout > 0, 'Analyzer timeout must be positive')
    for name in ('vendor/bin/phpstan', 'vendor/autoload.php', 'phpstan-type-contracts.neon'):
        require((root / name).is_file(), f'Missing analyzer/dependency/config input: {name}')
    cases = load_cases(suite)
    for case in cases:
        file = (suite / case['file']).resolve(strict=True)
        result = analyze(root, file, timeout)
        try:
            check_result(case, file, result)
        except ContractFailure as error:
            raise ContractFailure(f'{error}\nAnalyzer stdout:\n{result[1]}\nAnalyzer stderr:\n{result[2]}') from error
        print(f"[type-contracts] PASS {case['id']} ({case['kind']}, {len(case['diagnostics'])} diagnostics)", flush=True)
    print(f'[type-contracts] Passed {len(cases)} cases; '
          f"{sum(len(case['diagnostics']) for case in cases)} exact diagnostics", flush=True)


def main():
    root = Path(__file__).resolve().parent.parent
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--suite', type=Path, default=root / 'type-contracts')
    parser.add_argument('--timeout', type=float, default=120)
    arguments = parser.parse_args()
    try:
        run_suite(root, arguments.suite.resolve(strict=True), arguments.timeout)
    except (ContractFailure, OSError, ValueError, TypeError) as error:
        print(f'[type-contracts] FAIL {error}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
