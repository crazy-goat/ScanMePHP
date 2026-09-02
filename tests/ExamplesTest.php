<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every file in examples/ and requires it to succeed.
 *
 * The examples in this repository were once a set of v1 scripts that no longer
 * ran at all: the API they demonstrated had been deleted, and nothing noticed
 * because nothing executed them. Documentation that is never run is a claim
 * about the library rather than a fact about it, and the first person to find
 * out is someone evaluating the package.
 *
 * This test is deliberately shallow — it checks the exit status and that
 * something was printed, not the output itself. Pinning the output would make
 * every cosmetic edit a test failure and teach maintainers to update the
 * expectation without reading it. The examples' job is to run against the real
 * API; the suites that check what the API produces are elsewhere.
 */
final class ExamplesTest extends TestCase
{
    private const DIRECTORY = __DIR__ . '/../examples';

    /** @return iterable<string, array{string}> */
    public static function exampleProvider(): iterable
    {
        $files = glob(self::DIRECTORY . '/*.php');
        self::assertIsArray($files, 'examples/ could not be listed');
        sort($files);

        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }

    public function testThereAreExamplesToRun(): void
    {
        // A glob that silently matches nothing would make every assertion
        // below vacuous, and this suite would report green on an empty
        // directory.
        self::assertGreaterThanOrEqual(
            5,
            iterator_count(self::exampleProvider()),
            'examples/ has lost its contents'
        );
    }

    #[DataProvider('exampleProvider')]
    public function testTheExampleRuns(string $file): void
    {
        $command = sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file));

        exec($command, $lines, $status);
        $output = implode("\n", $lines);

        self::assertSame(0, $status, sprintf("%s failed:\n%s", basename($file), $output));
        self::assertNotSame('', trim($output), basename($file) . ' printed nothing');
        self::assertStringContainsString(
            'Done.',
            $output,
            sprintf("%s did not reach the end:\n%s", basename($file), $output)
        );
    }

    /**
     * Every example must be listed in examples/README.md.
     *
     * A file nobody links to is a file nobody opens, and the table is the only
     * place a reader chooses which example to read.
     */
    public function testTheReadmeListsEveryExample(): void
    {
        $readme = file_get_contents(self::DIRECTORY . '/README.md');
        self::assertIsString($readme);

        foreach (self::exampleProvider() as $name => $_) {
            self::assertStringContainsString($name, $readme, "examples/README.md does not mention {$name}");
        }
    }
}
