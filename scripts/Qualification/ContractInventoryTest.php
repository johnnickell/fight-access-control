<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\Qualification;

use Fight\AccessControl\Tooling\ContractInventory\Inventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

final class ContractInventoryTest extends TestCase
{
    private const string SOURCE = <<<'PHP'
        <?php
        namespace Fixture;
        /** @template T */
        interface Port { /** @return list<T> */ public function read(): array; }
        trait Named { public function label(): string { return 'x'; } }
        enum Mode: string { case ON = 'on'; }
        #[\Attribute] class Marker {}
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
        PHP;

    private const array RULE = ['task' => 'TASK-00115', 'ticket' => 'TICKET-00037', 'path' => '(src|openapi)/.*'];

    private const array CONCERN = ['task' => 'TASK-00128', 'ticket' => 'TICKET-00038', 'path' => 'src/.*'];

    private const string ROOT = __DIR__.'/../..';

    private string $fixture;

    private array $baseline;

    protected function setUp(): void
    {
        $this->fixture = self::ROOT.'/.runs/tasks/TASK-00196/runs/qualification/'.bin2hex(random_bytes(8));
        foreach (['src', 'openapi', 'etc', 'planning/tasks'] as $directory) {
            mkdir($this->fixture.'/'.$directory, 0755, true);
        }

        $this->put('src/Item.php', self::SOURCE);
        $this->put('openapi/Metadata.php', '<?php namespace Fixture; #[Marker] class Metadata {}');
        $this->put('composer.lock', '{}');
        $this->put('planning/tasks/00115-TASK.md', "ticket: TICKET-00037\n");
        $this->put('planning/tasks/00128-TASK.md', "ticket: TICKET-00038\n");
        $this->config();
        $this->baseline = $this->invoke(['--refresh']);
        $this->put('previous.json', file_get_contents($this->fixture.'/inventory.json'));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->fixture)) {
            return;
        }

        // Permission fixtures must be accessible again before enumeration/removal.
        if (is_dir($this->fixture.'/src/private')) {
            chmod($this->fixture.'/src/private', 0755);
        }

        $directory = new RecursiveDirectoryIterator($this->fixture, RecursiveDirectoryIterator::SKIP_DOTS);
        $items = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->fixture);
    }

    public function testDiscoversContractsAndProducesDeterministicPendingReport(): void
    {
        $kinds = [
            'Stmt_Class', 'Stmt_Interface', 'Stmt_Trait', 'Stmt_Enum', 'Stmt_EnumCase', 'Stmt_Property',
            'Stmt_ClassConst', 'Stmt_ClassMethod', 'Stmt_Function', 'Param', 'Expr_Closure', 'Expr_ArrowFunction',
            'ClosureUse', 'Attribute', 'Stmt_TraitUse', 'Expr_FuncCall', 'Expr_MethodCall', 'Expr_StaticCall',
            'Expr_PropertyFetch', 'Expr_New'
        ];
        foreach ($kinds as $kind) {
            self::assertGreaterThan(0, $this->baseline['summary']['kinds'][$kind]);
        }

        $contracts = json_encode(array_column($this->baseline['boundaries'], 'contract'), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('@return list<T>', $contracts);
        self::assertStringContainsString('Fixture\\\\Port', $contracts);
        $ids = array_column($this->baseline['boundaries'], 'id');
        foreach (['fromArray', 'toArray'] as $method) {
            self::assertContains('type:Fixture\\Item::'.$method.'()', $ids);
        }

        foreach ($this->baseline['boundaries'] as $row) {
            self::assertSame('pending', $row['judgments']['TASK-00115']['status']);
        }

        self::assertTrue($this->baseline['discovery_complete']);
        self::assertFalse($this->baseline['semantic_audit_complete']);
        self::assertSame($this->baseline, $this->invoke());
    }

    #[DataProvider('drifts')]
    public function testReportsStructuralDrift(string $mutation, array $expected): void
    {
        match ($mutation) {
            'metadata' => $this->put('openapi/bootstrap.php', '<?php declare(strict_types=1);'),
            'addition' => $this->put('src/Item.php', self::SOURCE."\nfunction added(): void {}"),
            'removal' => $this->put('openapi/Metadata.php', '<?php // declaration-free'),
            'move' => rename($this->fixture.'/src/Item.php', $this->fixture.'/src/Moved.php'),
            'namespace' => $this->put(
                'src/Item.php',
                str_replace('namespace Fixture;', 'namespace Relocated;', self::SOURCE)
            ),
            'signature' => $this->put('src/Item.php', str_replace('list<T>', 'list<string>', self::SOURCE)),
        };
        $report = $this->invoke(['--previous', $this->fixture.'/previous.json'], 1, 'drift');
        self::assertTrue($report['discovery_complete']);
        self::assertFalse($report['structural_match']);
        foreach ($expected as $kind => $id) {
            self::assertContains($id, $report['difference'][$kind]);
        }
    }

    public static function drifts(): iterable
    {
        yield 'declaration-free file' => ['metadata', ['added' => 'file:openapi/bootstrap.php']];
        yield 'addition' => ['addition', ['added' => 'function:Fixture\\added']];
        yield 'removal' => ['removal', ['removed' => 'type:Fixture\\Metadata']];
        yield 'file move' => ['move', ['moved' => 'type:Fixture\\Item']];
        yield 'namespace move' => ['namespace', ['removed' => 'type:Fixture\\Item', 'added' => 'type:Relocated\\Item']];
        yield 'PHPDoc change' => ['signature', ['changed' => 'type:Fixture\\Port::read()']];
    }

    #[DataProvider('failures')]
    public function testReplacesSuccessReportOnIncompleteDiscovery(string $mutation, string $message): void
    {
        $arguments = [];
        switch ($mutation) {
            case 'unowned':
                $this->config(rules: [['path' => 'openapi/.*'] + self::RULE]);
                break;
            case 'two owners':
                $this->config(rules: [self::RULE, self::RULE]);
                break;
            case 'type':
                $this->put('src/Other.php', '<?php namespace Fixture; class ITEM {}');
                break;
            case 'method':
                $this->put('src/Item.php', '<?php class Broken { function f() {} function F() {} }');
                break;
            case 'property':
                $this->put('src/Item.php', '<?php class Broken { public int $x; public string $x; }');
                break;
            case 'constant':
                $this->put('src/Item.php', '<?php class Broken { const X = 1; const X = 2; }');
                break;
            case 'concerns':
                $this->config(concerns: [self::CONCERN, ['path' => '.*Item.php'] + self::CONCERN]);
                break;
            case 'unreadable file':
                chmod($this->fixture.'/src/Item.php', 0);
                break;
            case 'unreadable directory':
                mkdir($this->fixture.'/src/private', 0);
                break;
            case 'link':
                symlink('Item.php', $this->fixture.'/src/Linked.php');
                break;
            case 'parse':
                $this->put('src/Item.php', '<?php class Broken {');
                break;
            case 'empty root':
            case 'missing root':
                unlink($this->fixture.'/openapi/Metadata.php');
                if ($mutation === 'missing root') {
                    rmdir($this->fixture.'/openapi');
                }

                break;
            case 'narrowed roots':
                $this->config(roots: ['src']);
                break;
            case 'wrong parent':
                $this->config(rules: [['ticket' => 'TICKET-00040'] + self::RULE]);
                break;
            case 'lock missing':
                unlink($this->fixture.'/etc/contract-inventory.json');
                break;
            case 'incomplete previous':
            case 'truncated previous':
                $previous = $this->baseline;
                if ($mutation === 'incomplete previous') {
                    $previous['discovery_complete'] = false;
                } else {
                    array_pop($previous['boundaries']);
                    --$previous['summary']['boundaries'];
                }

                $this->put('previous.json', json_encode($previous, JSON_THROW_ON_ERROR));
                $arguments = ['--previous', $this->fixture.'/previous.json'];
                break;
            case 'fifo':
                new Process(['mkfifo', $this->fixture.'/src/Special.php'])->mustRun();
                break;
        }

        $result = $this->invoke($arguments, 1, $message);
        self::assertFalse($result['discovery_complete']);
        self::assertArrayNotHasKey('boundaries', $result);
    }

    public static function failures(): iterable
    {
        $messages = [
            'unowned'              => 'found 0',
            'two owners'           => 'found 2',
            'type'                 => 'Duplicate declaration',
            'method'               => 'Duplicate declaration',
            'property'             => 'Duplicate declaration',
            'constant'             => 'Duplicate declaration',
            'concerns'             => 'Duplicate contract concern',
            'unreadable file'      => 'Unreadable',
            'unreadable directory' => 'Unreadable',
            'link'                 => 'Linked',
            'parse'                => 'AST discovery failed',
            'empty root'           => 'Empty discovery root',
            'missing root'         => 'invalid discovery directory',
            'narrowed roots'       => 'exactly src and openapi',
            'wrong parent'         => 'parent mismatch',
            'lock missing'         => 'Missing structural inventory',
            'incomplete previous'  => 'incomplete',
            'truncated previous'   => 'Partial or inconsistent',
            'fifo'                 => 'special discovery input'
        ];
        foreach ($messages as $case => $message) {
            yield $case => [$case, $message];
        }
    }

    public function testPartialLockDriftsAndExplicitRefreshPreservesPendingSemantics(): void
    {
        $this->put('etc/contract-inventory.json', '{"schema":1,"owners":{}}');
        $this->invoke([], 1, 'drift');
        $this->put('src/Item.php', '<?php enum Shared { case SAME; public function SAME(): void {} }');
        $report = $this->invoke(['--refresh']);
        self::assertTrue($report['structural_match']);
        self::assertFalse($report['semantic_audit_complete']);
        $this->invoke();
    }

    public function testOwnerAndConcernJudgmentsKeepUnreviewedSlotsPending(): void
    {
        $this->config(concerns: [self::CONCERN]);
        $this->baseline = $this->invoke(['--refresh']);
        $entry = $this->judgment();
        $entry['task'] = 'TASK-00128';
        $report = $this->submit([$entry]);
        $row = array_column($report['boundaries'], null, 'id')[$entry['boundary']];
        self::assertSame('TASK-00115', $row['owner']['task']);
        self::assertSame('pending', $row['judgments']['TASK-00115']['status']);
        self::assertSame('unchanged', $row['judgments']['TASK-00128']['status']);
        self::assertFalse($report['semantic_audit_complete']);
        $entry['task'] = 'TASK-00115';
        $report = $this->submit([$entry]);
        $row = array_column($report['boundaries'], null, 'id')[$entry['boundary']];
        self::assertSame('unchanged', $row['judgments']['TASK-00115']['status']);
        self::assertSame('pending', $row['judgments']['TASK-00128']['status']);
    }

    #[DataProvider('invalidJudgments')]
    public function testRejectsInvalidJudgments(string $mutation, string $message): void
    {
        $entry = $this->judgment();
        switch ($mutation) {
            case 'stale':
                $this->put('src/Item.php', str_replace("return 'x'", "return 'y'", self::SOURCE));
                break;
            case 'owner':
                $entry['task'] = 'TASK-00116';
                break;
            case 'boundary':
                $entry['boundary'] = 'unknown';
                break;
            case 'status':
                $entry['status'] = 'accepted';
                break;
            case 'semantics':
                unset($entry['failure']);
                break;
            case 'evidence':
                $entry['evidence'] = [];
                break;
        }

        $report = $this->submit($mutation === 'duplicate' ? [$entry, $entry] : [$entry], 1, $message);
        self::assertFalse($report['discovery_complete']);
    }

    public static function invalidJudgments(): iterable
    {
        $messages = [
            'stale'     => 'Stale judgment',
            'duplicate' => 'Duplicate judgment',
            'owner'     => 'Wrong judgment owner',
            'boundary'  => 'Unknown judgment boundary',
            'status'    => 'Unsupported judgment status',
            'semantics' => 'semantics',
            'evidence'  => 'evidence locators'
        ];
        foreach ($messages as $case => $message) {
            yield $case => [$case, $message];
        }
    }

    public function testUsageKeepsHelpAndInvalidArgumentExitsSeparateFromInventoryFailures(): void
    {
        foreach ([['--help', 0], ['--unknown', 2], ['--output', 2]] as [$flag, $exit]) {
            $process = new Process([PHP_BINARY, self::ROOT.'/scripts/contract_inventory.php', $flag]);
            self::assertSame($exit, $process->run(), $process->getErrorOutput());
        }
    }

    public function testLongOptionPrefixesPreserveCliBehavior(): void
    {
        $command = [PHP_BINARY, self::ROOT.'/scripts/contract_inventory.php'];
        $output = $this->fixture.'/abbreviated.json';
        $this->put('judgments.json', json_encode([
            'candidate' => $this->baseline['candidate'],
            'records'   => [$this->judgment()]
        ], JSON_THROW_ON_ERROR));
        unlink($this->fixture.'/etc/contract-inventory.json');
        $process = new Process([
            ...$command, '--roo='.$this->fixture, '--o', $output, '--p', $this->fixture.'/previous.json',
            '--j', $this->fixture.'/judgments.json', '--ref'
        ]);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $report = json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['structural_match']);
        self::assertSame(['added' => [], 'changed' => [], 'moved' => [], 'removed' => []], $report['difference']);
        $boundaries = array_column($report['boundaries'], null, 'id');
        self::assertSame('unchanged', $boundaries['type:Fixture\\Port']['judgments']['TASK-00115']['status']);

        $help = new Process([...$command, '--roo='.$this->fixture.'/missing', '--he']);
        self::assertSame(0, $help->run(), $help->getErrorOutput());
        self::assertStringContainsString('usage:', $help->getOutput());

        $invalidOptions = [
            '--r=unused'   => 'ambiguous',
            '--rooo'       => 'unrecognized',
            '--ref=unused' => 'ignored explicit'
        ];
        foreach ($invalidOptions as $flag => $message) {
            $invalid = new Process([...$command, $flag]);
            self::assertSame(2, $invalid->run(), $invalid->getErrorOutput());
            self::assertStringContainsString($message, $invalid->getErrorOutput());
        }
    }

    private function config(array $rules = [self::RULE], array $concerns = [], array $roots = ['src', 'openapi']): void
    {
        $config = ['schema' => 1, 'rules' => $rules, 'roots' => $roots, 'concerns' => $concerns];
        $this->put('etc/contract-owners.json', json_encode($config, JSON_THROW_ON_ERROR));
    }

    private function put(string $path, string $content): void
    {
        file_put_contents($this->fixture.'/'.$path, $content);
    }

    private function invoke(array $arguments = [], int $exit = 0, ?string $message = null): array
    {
        $output = $this->fixture.'/inventory.json';
        $command = [PHP_BINARY, self::ROOT.'/scripts/contract_inventory.php'];
        $command = [...$command, '--root', $this->fixture, '--output', $output, ...$arguments];

        $process = new Process($command, timeout: 30);
        self::assertSame($exit, $process->run(), $process->getOutput().$process->getErrorOutput());
        if ($message !== null) {
            self::assertStringContainsString($message, $process->getErrorOutput());
        }

        return json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
    }

    private function judgment(): array
    {
        return [
            'boundary' => 'type:Fixture\\Port',
            'task'     => 'TASK-00115',
            'status'   => 'unchanged',
            'evidence' => ['task-owned/qualified.log']
        ] + array_fill_keys(Inventory::SEMANTICS, 'Type-only fixture; N/A with rationale');
    }

    private function submit(array $records, int $exit = 0, ?string $message = null): array
    {
        $judgments = ['candidate' => $this->baseline['candidate'], 'records' => $records];
        $this->put('judgments.json', json_encode($judgments, JSON_THROW_ON_ERROR));

        return $this->invoke(['--judgments', $this->fixture.'/judgments.json'], $exit, $message);
    }
}
