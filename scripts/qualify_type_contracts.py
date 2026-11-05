"""Explicit runner qualification, separate from product PHPUnit and coverage.

Run inside the package runtime: ./bin/exec python3 scripts/qualify_type_contracts.py
Temporary suites are disposable probes; stdout contains the scenario results.
"""
from contextlib import contextmanager
from copy import deepcopy
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

# Qualification must not leave generated Python files in tracked source directories.
sys.dont_write_bytecode = True
import check_type_contracts as runner


ROOT = Path(__file__).resolve().parent.parent


@contextmanager
def suite_copy():
    with tempfile.TemporaryDirectory(prefix='type-contract-qualification-') as directory:
        suite = Path(directory) / 'suite'
        shutil.copytree(ROOT / 'type-contracts', suite)
        yield suite


class RealAnalyzerQualification(unittest.TestCase):
    """Mutate actual fixtures and execute the ordinary runner/analyzer path."""

    def execute(self, suite, expected_success=False):
        result = subprocess.run([sys.executable, str(ROOT / 'scripts/check_type_contracts.py'),
                                 '--suite', str(suite)], cwd=ROOT, capture_output=True, text=True, timeout=180)
        print(f'\nScenario {self.id()}: exit {result.returncode}\n{result.stdout}{result.stderr}', flush=True)
        self.assertEqual(result.returncode == 0, expected_success)
        if expected_success:
            self.assertIn('Passed 2 cases; 12 exact diagnostics', result.stdout)
        else:
            self.assertNotIn('[type-contracts] Passed', result.stdout)
        return result

    def test_current_contracts_pass(self):
        self.execute(ROOT / 'type-contracts', expected_success=True)

    def test_valid_case_regression_and_recovery_do_not_reuse_a_stale_pass(self):
        with suite_copy() as suite:
            positive = suite / 'positive.php'
            original = positive.read_text()
            self.execute(suite, expected_success=True)
            positive.write_text(original.replace('dumpType($userView->getUserId());',
                                                 'dumpType($userView->getEmail());'))
            self.execute(suite)
            positive.write_text(original)
            self.execute(suite, expected_success=True)

    def test_lost_positive_precision_fails(self):
        with suite_copy() as suite:
            positive = suite / 'positive.php'
            positive.write_text(positive.read_text().replace('dumpType($userView->getUserId());',
                                                             "dumpType(json_decode('{}'));"))
            self.execute(suite)

    def test_accepted_invalid_use_fails(self):
        with suite_copy() as suite:
            negative = suite / 'negative.php'
            negative.write_text(negative.read_text().replace('getById($roleId)', 'getById($userId)'))
            self.execute(suite)

    def test_negative_case_without_any_rejection_fails(self):
        with suite_copy() as suite:
            (suite / 'negative.php').write_text('<?php\ndeclare(strict_types=1);\n')
            self.execute(suite)

    def test_unexpected_analyzer_error_fails(self):
        with suite_copy() as suite:
            positive = suite / 'positive.php'
            positive.write_text(positive.read_text() + '\nunknownFixtureFunction();\n')
            self.execute(suite)

    def test_missing_empty_and_unregistered_files_fail(self):
        for mutation in ('missing', 'empty', 'unregistered'):
            with self.subTest(mutation=mutation), suite_copy() as suite:
                if mutation == 'missing':
                    (suite / 'negative.php').unlink()
                elif mutation == 'empty':
                    (suite / 'negative.php').write_text('')
                else:
                    (suite / 'extra.php').write_text('<?php\n')
                self.execute(suite)

    def test_missing_empty_and_invalid_case_declarations_fail(self):
        for mutation in ('missing', 'empty', 'malformed', 'duplicate', 'no-negative', 'no-diagnostics'):
            with self.subTest(mutation=mutation), suite_copy() as suite:
                manifest = suite / 'cases.json'
                document = json.loads(manifest.read_text())
                if mutation == 'missing':
                    manifest.unlink()
                elif mutation == 'empty':
                    manifest.write_text('{"cases": []}')
                elif mutation == 'malformed':
                    manifest.write_text('{')
                else:
                    if mutation == 'duplicate':
                        document['cases'].append(document['cases'][0])
                    elif mutation == 'no-negative':
                        document['cases'].pop()
                    else:
                        document['cases'][0]['diagnostics'] = []
                    manifest.write_text(json.dumps(document))
                self.execute(suite)

    def test_unavailable_analyzer_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(runner.ContractFailure, 'Missing analyzer'):
                runner.run_suite(Path(directory), ROOT / 'type-contracts')


class AnalyzerProtocolQualification(unittest.TestCase):
    """Challenge result matching independently of PHPStan's usual well-formed output."""

    @classmethod
    def setUpClass(cls):
        cls.case = runner.load_cases(ROOT / 'type-contracts')[0]
        cls.file = (ROOT / 'type-contracts' / cls.case['file']).resolve()
        cls.result = runner.analyze(ROOT, cls.file, 120)
        runner.check_result(cls.case, cls.file, cls.result)

    def test_exact_report_passes_but_bad_exit_or_stderr_fails(self):
        runner.check_result(self.case, self.file, self.result)
        for code, stderr in ((0, ''), (2, ''), (-15, ''), (1, 'analyzer warning')):
            with self.subTest(code=code, stderr=stderr), self.assertRaises(runner.ContractFailure):
                runner.check_result(self.case, self.file, (code, self.result[1], stderr))

    def test_missing_extra_wrong_file_line_identifier_and_message_fail(self):
        for mutation in ('missing', 'extra', 'file', 'line', 'identifier', 'message', 'global', 'totals'):
            report = json.loads(self.result[1])
            messages = report['files'][str(self.file)]['messages']
            if mutation == 'missing':
                messages.pop()
            elif mutation == 'extra':
                messages.append(deepcopy(messages[0]))
            elif mutation == 'file':
                report['files'][str(self.file) + '.other'] = report['files'].pop(str(self.file))
            elif mutation == 'global':
                report['errors'] = ['Internal analyzer failure']
            elif mutation == 'totals':
                report['totals']['file_errors'] -= 1
            else:
                messages[0][mutation] = {'line': 999, 'identifier': 'other.error', 'message': 'Other type'}[mutation]
            with self.subTest(mutation=mutation), self.assertRaises(runner.ContractFailure):
                runner.check_result(self.case, self.file, (1, json.dumps(report), ''))

    def test_partial_malformed_and_empty_reports_fail(self):
        for stdout in ('', '{', '{}', 'null', '{"totals":{"errors":0,"file_errors":6},"files":{}}'):
            with self.subTest(stdout=stdout), self.assertRaises(runner.ContractFailure):
                runner.check_result(self.case, self.file, (1, stdout, ''))

    def test_native_launch_timeout_and_signal_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            executable = Path(directory) / 'php'
            environment = {'PATH': directory + os.pathsep + os.environ['PATH']}
            with patch.dict(os.environ, {'PATH': directory}):
                with self.assertRaises(OSError):
                    runner.analyze(ROOT, self.file, 1)
            for body, timeout in (('import time; time.sleep(10)', 0.1),
                                  ('import os, signal; os.kill(os.getpid(), signal.SIGTERM)', 5)):
                executable.write_text(f'#!{sys.executable}\n{body}\n')
                executable.chmod(0o700)
                with patch.dict(os.environ, environment):
                    with self.assertRaises(runner.ContractFailure):
                        result = runner.analyze(ROOT, self.file, timeout)
                        runner.check_result(self.case, self.file, result)


if __name__ == '__main__':
    unittest.main(verbosity=2)
