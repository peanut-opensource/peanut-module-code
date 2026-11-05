<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/scripts/run-php-test';

/** Tests the runner using real child PHPUnit/script execution, not only source string matching. */
final class PhpTestRunnerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = dirname(__DIR__) . '/.runner-fixture-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_file($this->directory . '/' . $name)) {
                unlink($this->directory . '/' . $name);
            }
        }
        rmdir($this->directory);
    }

    public function testAliasedPhpunitAndLocalInheritanceAreRecognized(): void
    {
        $source = <<<'PHP'
<?php
namespace RunnerExample;
use PHPUnit\Framework\TestCase as RealTest;
abstract class Base extends RealTest {}
final class Derived extends Base { public function testOne(): void { self::assertTrue(true); } }
PHP;
        self::assertSame('phpunit', \peanutTestKind($source));
        self::assertSame('script', \peanutTestKind('<?php // extends PHPUnit\\Framework\\TestCase\n echo "contract";'));
    }

    public function testPassingPhpunitActuallyInvokesAnAssertion(): void
    {
        [$code, $output] = $this->runFixture('PassingTest.php', <<<'PHP'
<?php
final class PassingTest extends \PHPUnit\Framework\TestCase
{
    public function testRuns(): void { self::assertSame(4, 2 + 2); }
}
PHP);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('1 test, 1 assertion', $output);
        self::assertStringContainsString('[php-test] phpunit', $output);
    }

    public function testIsolatedPhpunitDoesNotPreloadExplicitEnvironmentInParent(): void
    {
        $root = dirname(__DIR__, 3);
        $environment = $root . '/server/.env.runner-' . bin2hex(random_bytes(6));
        $handle = fopen($environment, 'x');
        self::assertNotFalse($handle);
        fwrite($handle, "APP_ENV=development\nDEPLOYMENT_MODE=standalone\n");
        fclose($handle);
        chmod($environment, 0600);
        try {
            [$code, $output] = $this->runFixture('IsolatedTest.php', <<<'PHP'
<?php
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class IsolatedTest extends \PHPUnit\Framework\TestCase
{
    public function testRuns(): void
    {
        self::assertFalse(getenv('APP_ENV'));
        self::assertNotFalse(getenv('PEANUT_SERVER_ENV_FILE'));
    }
}
PHP, $environment);
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('1 test, 2 assertions', $output);
        } finally {
            unlink($environment);
        }
    }

    public function testFailingPhpunitDoesNotBecomeARequireOnlySuccess(): void
    {
        [$code, $output] = $this->runFixture('FailingTest.php', <<<'PHP'
<?php
final class FailingTest extends \PHPUnit\Framework\TestCase
{
    public function testRuns(): void { self::assertSame('expected', 'actual'); }
}
PHP);
        self::assertNotSame(0, $code, $output);
        self::assertStringContainsString('FAILURES!', $output);
    }

    public function testEmptyPhpunitCannotBeReportedAsPassed(): void
    {
        [$code, $output] = $this->runFixture('EmptyTest.php', '<?php final class EmptyTest extends \\PHPUnit\\Framework\\TestCase {}');
        self::assertNotSame(0, $code, $output);
    }

    public function testExecutableScriptStillRunsAndPropagatesFailure(): void
    {
        [$code, $output] = $this->runFixture('Contract.php', '<?php if (2 + 2 !== 4) { throw new RuntimeException("bad"); } echo "SCRIPT_ASSERTION_EXECUTED\\n";');
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('SCRIPT_ASSERTION_EXECUTED', $output);
        self::assertStringContainsString('[php-test] script', $output);
        [$failed, $error] = $this->runFixture('FailedContract.php', '<?php fwrite(STDERR, "INTENTIONAL_FAILURE"); exit(17);');
        self::assertSame(17, $failed, $error);
        [$guarded, $guardedOutput] = $this->runFixture('GuardedContract.php', '<?php if (realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { fwrite(STDERR, "GUARDED_MAIN_EXECUTED"); exit(19); }');
        self::assertSame(19, $guarded, $guardedOutput);
        self::assertStringContainsString('GUARDED_MAIN_EXECUTED', $guardedOutput);
    }

    public function testExecutableScriptReceivesNativeArgumentsAfterRunnerSeparator(): void
    {
        [$code, $output] = $this->runFixture('Arguments.php', <<<'PHP'
<?php
if (getopt('', ['mode:']) !== ['mode' => 'fixture'] || $argc !== 2 || $argv[0] !== __FILE__) {
    throw new RuntimeException('SCRIPT_ARGUMENTS_CHANGED');
}
echo "NATIVE_ARGUMENTS_EXECUTED\n";
PHP, null, ['--', '--mode=fixture']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('NATIVE_ARGUMENTS_EXECUTED', $output);
    }

    public function testRequiredMysqlPhpunitCannotSucceedBySkipping(): void
    {
        [$code, $output] = $this->runFixture('SkippedTest.php', <<<'PHP'
<?php
final class SkippedTest extends \PHPUnit\Framework\TestCase
{
    public function testRequiredDatabase(): void { self::markTestSkipped('fixture database unavailable'); }
}
PHP, null, ['--fail-on-skipped']);
        self::assertNotSame(0, $code, $output);
        self::assertStringContainsString('Skipped: 1', $output);
    }

    /** @return array{int,string} */
    private function runFixture(string $name, string $source, ?string $environmentFile = null, array $arguments = []): array
    {
        $file = $this->directory . '/' . $name;
        self::assertNotFalse(file_put_contents($file, $source));
        $root = dirname(__DIR__, 3);
        // Child tests are isolated syntax/runner fixtures and must not inherit a real database environment.
        $environment = getenv();
        unset($environment['PEANUT_SERVER_ENV_FILE'], $environment['PEANUT_INTEGRATION']);
        foreach (function_exists('peanutBackendEnvironmentKeys') ? \peanutBackendEnvironmentKeys() : [] as $key) {
            unset($environment[$key], $environment['PHP_' . $key]);
        }
        $process = proc_open(
            [PHP_BINARY, $root . '/scripts/run-php-test', ...($environmentFile === null ? [] : ['--env-file=' . $environmentFile]), $file, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $environment,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $output . (string) $error];
    }
}
