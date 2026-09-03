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
 * The examples are now one generator — gallery.php — which the test below runs
 * and then verifies against the registry it claims to describe, so the count
 * and the coverage of the gallery are facts, not promises.
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
        self::assertNotSame(
            [],
            iterator_to_array(self::exampleProvider(), false),
            'examples/ has lost its contents',
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
            sprintf("%s did not reach the end:\n%s", basename($file), $output),
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

    /**
     * The gallery claims to show every symbology through every renderer.
     *
     * A gallery that quietly lost a symbology — a payload map that was not
     * extended, a page nobody regenerated — would go on looking current.
     * So this test runs the generator itself and checks the pages it wrote
     * against the registry, which is the only authority on what "every" means.
     */
    public function testTheGalleryCoversTheWholeRegistry(): void
    {
        $command = sprintf(
            '%s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::DIRECTORY . '/gallery.php'),
        );

        exec($command, $lines, $status);
        self::assertSame(0, $status, "gallery.php failed:\n" . implode("\n", $lines));

        $index = file_get_contents(self::DIRECTORY . '/index.md');
        self::assertIsString($index, 'gallery.php wrote no index.md');

        $registry = \CrazyGoat\ScanMePHP\Scanme::create()->getRegistry();

        foreach ($registry->describeGenerators() as $name => $_) {
            self::assertStringContainsString(
                '(codes/' . $name . '.md)',
                $index,
                "index.md does not link to the {$name} page",
            );

            $page = file_get_contents(sprintf('%s/codes/%s.md', self::DIRECTORY, $name));
            self::assertIsString($page, "gallery.php wrote no page for {$name}");

            foreach ($registry->rendererFormats() as $format) {
                self::assertStringContainsString(
                    $format,
                    $page,
                    "the {$name} page does not mention the {$format} renderer",
                );
            }
        }
    }

    /**
     * The gallery is committed, so it can go stale the way committed
     * documentation does: showing the library of some earlier commit while
     * the suite stays green. So the generator runs again here — after the
     * committed files are in place — and anything it changed or added is a
     * failure naming the files to regenerate with
     * `php examples/gallery.php`.
     */
    public function testTheCommittedGalleryIsCurrent(): void
    {
        $command = sprintf(
            '%s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::DIRECTORY . '/gallery.php'),
        );

        exec($command, $lines, $status);
        self::assertSame(0, $status, "gallery.php failed:\n" . implode("\n", $lines));

        exec('git status --porcelain -- examples/index.md examples/codes examples/assets', $changes, $gitStatus);
        self::assertSame(0, $gitStatus, 'git status could not be run');

        self::assertSame(
            [],
            array_filter($changes, static fn (string $line): bool => $line !== ''),
            "The committed gallery is stale. Regenerate it and commit the result:\n"
            . "  php examples/gallery.php\nDiffering or untracked files:\n"
            . implode("\n", $changes),
        );
    }
}
