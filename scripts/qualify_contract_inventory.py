#!/usr/bin/env python3
"""Explicit tooling qualification; deliberately outside product PHPUnit and coverage."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from contract_inventory import ROOT, SEMANTICS

SOURCE = '''<?php
namespace Fixture;
/** @template T */
interface Port { /** @return list<T> */ public function read(): array; }
trait Named { public function label(): string { return 'x'; } }
enum Mode: string { case ON = 'on'; }
#[\\Attribute] class Marker {}
#[Marker] class Item implements Port {
    use Named;
    public const LIMIT = 2;
    /** @var list<string> */ public array $values = [];
    public function __construct(public readonly int $id) {}
    /** @return list<string> */ public function read(): array {
        $mapper = fn (string $value): string => trim($value);
        $callback = function (string $value) use ($mapper): string { return $mapper($value); };
        return array_map($callback, $this->values);
    }
    public static function fromArray(array $data): self { return new self($data['id']); }
    public function toArray(): array { return ['id' => $this->id]; }
    public function invoke(): void { self::fromArray([])->toArray(); $factory = factory(...); }
}
function factory(int $id): Item { return new Item($id); }
'''


class InventoryQualification(unittest.TestCase):
    def setUp(self):
        scratch = ROOT / '.runs/tasks/TASK-00150/runs/qualification'
        scratch.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=scratch)
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        for directory in ('src', 'openapi', 'etc', 'planning/tasks'):
            (self.root / directory).mkdir(parents=True)
        self.source = self.root / 'src/Item.php'
        self.source.write_text(SOURCE)
        (self.root / 'openapi/Metadata.php').write_text('<?php namespace Fixture; #[Marker] class Metadata {}')
        (self.root / 'composer.lock').write_text('{}')
        (self.root / 'planning/tasks/00115-TASK.md').write_text('---\nticket: TICKET-00037\n---\n')
        self.config = {'schema': 1, 'roots': ['src', 'openapi'], 'concerns': [], 'rules': [
            {'task': 'TASK-00115', 'ticket': 'TICKET-00037', 'path': '(src|openapi)/.*'}]}
        self.config_path = self.root / 'etc/contract-owners.json'
        self.write_config()
        self.output = self.root / 'inventory.json'
        self.invoke('--refresh')
        self.baseline = json.loads(self.output.read_text())
        self.previous = self.root / 'previous.json'
        self.previous.write_text(self.output.read_text())

    def write_config(self):
        self.config_path.write_text(json.dumps(self.config))

    def invoke(self, *args, expected=0, message=None):
        result = subprocess.run([sys.executable, str(ROOT / 'scripts/contract_inventory.py'),
                                 '--root', str(self.root), '--output', str(self.output), *args],
                                text=True, capture_output=True, timeout=30)
        self.assertEqual(expected, result.returncode, result.stdout + result.stderr)
        if message:
            self.assertIn(message, result.stderr)
        return json.loads(self.output.read_text())

    def test_complete_declarations_members_callbacks_metadata_and_static_contracts(self):
        kinds = self.baseline['summary']['kinds']
        for kind in ('Stmt_Class', 'Stmt_Interface', 'Stmt_Trait', 'Stmt_Enum', 'Stmt_EnumCase',
                     'Stmt_Property', 'Stmt_ClassConst', 'Stmt_ClassMethod', 'Stmt_Function', 'Param',
                     'Expr_Closure', 'Expr_ArrowFunction', 'ClosureUse', 'Attribute', 'Stmt_TraitUse',
                     'Expr_FuncCall', 'Expr_MethodCall', 'Expr_StaticCall', 'Expr_PropertyFetch', 'Expr_New'):
            self.assertGreater(kinds[kind], 0, kind)
        contracts = json.dumps([r['contract'] for r in self.baseline['boundaries']])
        self.assertIn('@return list<T>', contracts)
        self.assertIn('Fixture\\\\Port', contracts)
        self.assertTrue(any('::fromArray' in r['id'] for r in self.baseline['boundaries']))
        self.assertTrue(any('::toArray' in r['id'] for r in self.baseline['boundaries']))
        self.assertTrue(self.baseline['discovery_complete'])
        self.assertFalse(self.baseline['semantic_audit_complete'])
        self.assertTrue(all(j['status'] == 'pending' for r in self.baseline['boundaries']
                            for j in r['judgments'].values()))

    def test_declaration_free_metadata_is_still_a_boundary(self):
        (self.root / 'openapi/bootstrap.php').write_text('<?php declare(strict_types=1);')
        result = self.invoke(expected=1)
        self.assertEqual(3, result['summary']['files'])
        self.assertTrue(any(r['id'] == 'file:openapi/bootstrap.php' for r in result['boundaries']))

    def test_output_is_deterministic(self):
        self.assertEqual(self.baseline, self.invoke())

    def test_addition_fails_and_reports_added_boundary(self):
        self.source.write_text(SOURCE + '\nfunction added(): void {}')
        result = self.invoke('--previous', str(self.previous), expected=1, message='drift')
        self.assertIn('function:Fixture\\added', result['difference']['added'])
        self.assertTrue(result['discovery_complete'])
        self.assertFalse(result['structural_match'])

    def test_removal_fails_and_reports_removed_boundary(self):
        (self.root / 'openapi/Metadata.php').unlink()
        (self.root / 'openapi/bootstrap.php').write_text('<?php // intentionally declaration-free')
        result = self.invoke('--previous', str(self.previous), expected=1)
        self.assertIn('type:Fixture\\Metadata', result['difference']['removed'])

    def test_path_move_preserves_type_identity_and_reports_move(self):
        self.source.rename(self.root / 'src/Moved.php')
        result = self.invoke('--previous', str(self.previous), expected=1)
        self.assertIn('type:Fixture\\Item', result['difference']['moved'])

    def test_namespace_move_is_removed_and_added_not_silently_accepted(self):
        self.source.write_text(SOURCE.replace('namespace Fixture;', 'namespace Relocated;'))
        result = self.invoke('--previous', str(self.previous), expected=1)
        self.assertIn('type:Fixture\\Item', result['difference']['removed'])
        self.assertIn('type:Relocated\\Item', result['difference']['added'])

    def test_signature_and_phpdoc_drift(self):
        self.source.write_text(SOURCE.replace('@return list<T>', '@return list<string>'))
        result = self.invoke('--previous', str(self.previous), expected=1)
        self.assertIn('type:Fixture\\Port::read()', result['difference']['changed'])

    def test_unassigned_owner_fails_closed(self):
        self.config['rules'][0]['path'] = 'openapi/.*'
        self.write_config()
        result = self.invoke(expected=1, message='found 0')
        self.assertFalse(result['discovery_complete'])

    def test_duplicate_owner_fails_even_when_task_is_identical(self):
        self.config['rules'].append(copy.deepcopy(self.config['rules'][0]))
        self.write_config()
        self.invoke(expected=1, message='found 2')

    def test_duplicate_declaration_fails_across_files_and_case(self):
        (self.root / 'src/Other.php').write_text('<?php namespace Fixture; class ITEM {}')
        self.invoke(expected=1, message='Duplicate declaration')

    def test_duplicate_method_fails(self):
        self.source.write_text('<?php class Broken { function f() {} function F() {} }')
        self.invoke(expected=1, message='Duplicate declaration')

    def test_duplicate_property_and_constant_declarations_fail(self):
        self.source.write_text('<?php class Broken { public int $x; public string $x; }')
        self.invoke(expected=1, message='Duplicate declaration')
        self.source.write_text('<?php class Broken { const X = 1; const X = 2; }')
        self.invoke(expected=1, message='Duplicate declaration')

    def test_enum_case_and_method_can_share_name(self):
        self.source.write_text('<?php enum Shared { case SAME; public function SAME(): void {} }')
        self.invoke('--refresh')

    def test_overlapping_secondary_concerns_fail(self):
        (self.root / 'planning/tasks/00128-TASK.md').write_text('ticket: TICKET-00038\n')
        concern = {'task': 'TASK-00128', 'ticket': 'TICKET-00038', 'path': 'src/.*'}
        self.config['concerns'] = [concern, {**concern, 'path': '.*Item.php'}]
        self.write_config()
        self.invoke(expected=1, message='Duplicate contract concern')

    def test_unreadable_file_fails_even_under_root(self):
        self.source.chmod(0)
        self.addCleanup(self.source.chmod, 0o644)
        self.invoke(expected=1, message='Unreadable')

    def test_unreadable_directory_fails(self):
        path = self.root / 'src/private'
        path.mkdir()
        path.chmod(0)
        self.addCleanup(path.chmod, 0o755)
        self.invoke(expected=1, message='Unreadable')

    def test_linked_input_fails_instead_of_omission(self):
        (self.root / 'src/Linked.php').symlink_to(self.source)
        self.invoke(expected=1, message='Linked')

    def test_parse_failure_invalidates_preexisting_success_output(self):
        self.source.write_text('<?php class Broken {')
        result = self.invoke(expected=1, message='AST discovery failed')
        self.assertFalse(result['discovery_complete'])
        self.assertNotIn('boundaries', result)

    def test_empty_or_missing_root_fails(self):
        (self.root / 'openapi/Metadata.php').unlink()
        self.invoke(expected=1, message='Empty discovery root')
        (self.root / 'openapi').rmdir()
        self.invoke(expected=1)

    def test_configuration_cannot_narrow_discovery_roots(self):
        self.config['roots'] = ['src']
        self.write_config()
        self.invoke(expected=1, message='exactly src and openapi')

    def test_wrong_task_parent_fails(self):
        self.config['rules'][0]['ticket'] = 'TICKET-00040'
        self.write_config()
        self.invoke(expected=1, message='parent mismatch')

    def test_missing_or_partial_lock_fails(self):
        lock = self.root / 'etc/contract-inventory.json'
        lock.unlink()
        self.invoke(expected=1, message='Missing structural inventory')
        lock.write_text('{"schema":1,"owners":{}}')
        self.invoke(expected=1, message='drift')

    def test_incomplete_previous_inventory_rejected(self):
        self.previous.write_text('{"schema":1,"discovery_complete":false}')
        self.invoke('--previous', str(self.previous), expected=1, message='incomplete')

    def test_truncated_previous_boundaries_rejected(self):
        partial = copy.deepcopy(self.baseline)
        partial['boundaries'].pop()
        partial['summary']['boundaries'] -= 1
        self.previous.write_text(json.dumps(partial))
        self.invoke('--previous', str(self.previous), expected=1, message='Partial or inconsistent')

    def test_special_php_input_fails(self):
        os.mkfifo(self.root / 'src/Special.php')
        self.invoke(expected=1, message='special discovery input')

    def test_secondary_concern_judgment_keeps_single_primary_edit_owner(self):
        (self.root / 'planning/tasks/00128-TASK.md').write_text('ticket: TICKET-00038\n')
        self.config['concerns'] = [{'task': 'TASK-00128', 'ticket': 'TICKET-00038', 'path': 'src/.*'}]
        self.write_config()
        self.baseline = self.invoke('--refresh')
        entry = self.judgment()
        entry['task'] = 'TASK-00128'
        result = self.submit([entry])
        boundary = next(r for r in result['boundaries'] if r['id'] == entry['boundary'])
        self.assertEqual('TASK-00115', boundary['owner']['task'])
        self.assertEqual('unchanged', boundary['judgments']['TASK-00128']['status'])
        self.assertEqual('pending', boundary['judgments']['TASK-00115']['status'])

    def judgment(self):
        boundary = next(r for r in self.baseline['boundaries'] if r['kind'] == 'Stmt_Interface')
        return {'boundary': boundary['id'], 'task': boundary['owner']['task'], 'status': 'unchanged',
                'evidence': ['task-owned/qualified.log'], **{key: 'Type-only fixture; N/A with test rationale' for key in SEMANTICS}}

    def submit(self, records, candidate=None, expected=0, message=None):
        path = self.root / 'judgments.json'
        path.write_text(json.dumps({'candidate': candidate or self.baseline['candidate'], 'records': records}))
        return self.invoke('--judgments', str(path), expected=expected, message=message)

    def test_owner_judgment_preserves_pending_remainder_and_never_self_accepts(self):
        result = self.submit([self.judgment()])
        statuses = [j['status'] for r in result['boundaries'] for j in r['judgments'].values()]
        self.assertEqual(1, statuses.count('unchanged'))
        self.assertIn('pending', statuses)
        self.assertFalse(result['semantic_audit_complete'])

    def test_stale_judgment_rejected_after_body_only_change(self):
        self.source.write_text(SOURCE.replace("return 'x'", "return 'y'"))
        self.submit([self.judgment()], expected=1, message='Stale judgment')

    def test_duplicate_and_wrong_owner_judgments_rejected(self):
        entry = self.judgment()
        self.submit([entry, entry], expected=1, message='Duplicate judgment')
        entry['task'] = 'TASK-00116'
        self.submit([entry], expected=1, message='Wrong judgment owner')

    def test_disposition_without_semantics_or_evidence_rejected(self):
        entry = self.judgment()
        del entry['failure']
        self.submit([entry], expected=1, message='semantics')
        entry = self.judgment()
        entry['evidence'] = []
        self.submit([entry], expected=1, message='evidence locators')

    def test_refresh_is_explicit_and_does_not_accept_semantics(self):
        self.source.write_text(SOURCE + '\nfunction added(): void {}')
        result = self.invoke('--refresh')
        self.assertTrue(result['structural_match'])
        self.assertFalse(result['semantic_audit_complete'])
        self.invoke()


if __name__ == '__main__':
    unittest.main(verbosity=2)
