<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class CodingStandardTest extends TestCase
{
    public function test_that_phpcs_rejects_structural_naming_and_documentation_violations(): void
    {
        $result = $this->runPhpcs(<<<'PHP'
<?php

namespace Fixture;

class BrokenStyle
{
    private const string mixedCase = 'value';

    private function helper(): int
    {
        $answer = 42;
        return $answer;
    }
    public function Execute(): void
    {
    }
}
PHP);

        self::assertNotSame(0, $result->getExitCode());
        $output = $result->getOutput().$result->getErrorOutput();
        foreach (
            [
                'SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing',
                'Squiz.Commenting.ClassComment.Missing',
                'Squiz.Commenting.FunctionComment.Missing',
                'Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase',
                'SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder',
                'SlevomatCodingStandard.Classes.MethodSpacing.IncorrectLinesCountBetweenMethods',
                'SlevomatCodingStandard.ControlStructures.JumpStatementsSpacing.IncorrectLinesCountBefore'
            ] as $source
        ) {
            self::assertStringContainsString($source, $output);
        }
    }

    public function test_that_phpcs_rejects_partially_keyed_arrays(): void
    {
        $result = $this->runPhpcs(<<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

/**
 * Class BrokenArray
 */
final class BrokenArray
{
    /**
     * Returns values
     *
     * @return array<int|string, string>
     */
    public function values(): array
    {
        return [
            'first',
            2 => 'second'
        ];
    }
}
PHP);

        self::assertNotSame(0, $result->getExitCode());
        self::assertStringContainsString(
            'SlevomatCodingStandard.Arrays.DisallowPartiallyKeyed.DisallowedPartiallyKeyed',
            $result->getOutput().$result->getErrorOutput()
        );
    }

    public function test_that_the_lean_published_rules_accept_a_compliant_fixture(): void
    {
        $result = $this->runPhpcs(<<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

/**
 * Class CompliantService
 */
final class CompliantService
{
    public const string READY_TO_USE = 'ready';

    /**
     * Returns values
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        return [
            'status' => self::READY_TO_USE
        ];
    }
}
PHP);

        self::assertSame(0, $result->getExitCode(), $result->getOutput().$result->getErrorOutput());
    }

    private function runPhpcs(string $source): Process
    {
        $root = dirname(__DIR__, 2);
        $fixture = tempnam(sys_get_temp_dir(), 'fight-access-control-style-');
        self::assertIsString($fixture);
        file_put_contents($fixture, $source."\n");
        $process = new Process(
            [
                'php',
                $root.'/vendor/bin/phpcs',
                '--standard='.$root.'/phpcs.xml',
                '--report=full',
                '-s',
                $fixture
            ],
            $root
        );

        try {
            $process->run();
        } finally {
            unlink($fixture);
        }

        return $process;
    }
}
