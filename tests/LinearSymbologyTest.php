<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\Exception\IncompatibleRendererException;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Generator\Code128\Backend\PhpBackend as Code128Backend;
use CrazyGoat\ScanMePHP\Generator\Code128\Code128Generator;
use CrazyGoat\ScanMePHP\Generator\Ean13\Ean13Generator;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\TestCase;

/**
 * Code 128 and EAN-13: the linear symbologies, and with them the parts of the
 * Symbol contract that QR never exercises — asymmetric quiet zones, bar height
 * as presentation, human-readable text, and rows of differing heights.
 *
 * Pattern tables are hand-transcribed from the standards, so they are checked
 * against properties a scanner actually relies on rather than against a copy
 * of the same table: element widths per character, bar parity, and for EAN-13
 * a cross-check against the element-width formulation of the same table.
 */
class LinearSymbologyTest extends TestCase
{
    private Code128Generator $code128;

    private Ean13Generator $ean13;

    protected function setUp(): void
    {
        $this->code128 = new Code128Generator();
        $this->ean13 = new Ean13Generator();
    }

    /**
     * Run lengths of a module string, i.e. its element widths.
     *
     * @return list<int>
     */
    private function widths(string $modules): array
    {
        preg_match_all('/(.)\1*/', $modules, $runs);

        return array_map(strlen(...), $runs[0]);
    }

    // ---------------------------------------------------------------- Code 128

    /**
     * Two symbols small enough to work out by hand, which pins the start-code
     * choice, the table lookup and the weighted modulo 103 together.
     */
    public function testCode128MatchesHandComputedSymbols(): void
    {
        // "A": not digits, so Start B (104); 'A' is value ord-32 = 33;
        // check = (104 + 1×33) mod 103 = 137 mod 103 = 34.
        $this->assertSame([104, 33, 34], (new Code128Backend())->symbolValues('A'));

        // "00": all digits, even length, so Start C (105); the pair is value 0;
        // check = (105 + 1×0) mod 103 = 2.
        $this->assertSame([105, 0, 2], (new Code128Backend())->symbolValues('00'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function code128WidthProvider(): iterable
    {
        // Symbol characters = start + payload + check; every character is 11
        // modules and the stop pattern is 13.
        yield 'one letter' => ['A', 3];
        yield 'letters' => ['ABC', 5];
        yield 'even digit run' => ['12345678', 6];        // Start C, four pairs
        yield 'odd digit run' => ['1234567', 7];          // Start C, three pairs, then B
        yield 'two digits go straight to C' => ['12', 3]; // all digits, even length
        yield 'three digits stay in B' => ['123', 5];      // odd, and too short for C to pay
        yield 'mixed' => ['ABC-123', 9];
        yield 'long tail digits' => ['X1234567890', 9];   // B, then C for the run
    }

    /** @dataProvider code128WidthProvider */
    public function testCode128WidthFollowsTheSymbolCharacterCount(string $data, int $characters): void
    {
        $symbol = $this->code128->generate($data);

        $this->assertSame($characters * 11 + 13, $symbol->getWidth(), $data);
        $this->assertCount($characters, (new Code128Backend())->symbolValues($data));
    }

    /**
     * Every symbol character must span 11 modules in four bars and spaces with
     * an even number of dark modules. That parity is the standard's built-in
     * misread check, so a single mistranscribed width would break it.
     */
    public function testCode128CharactersObeyTheWidthAndParityRules(): void
    {
        // Cover symbol values 0–94 through code set B and 0–99 through set C,
        // reading the patterns back out of real output rather than a table copy.
        $samples = [];
        for ($value = 0; $value <= 94; $value++) {
            $samples[] = \chr($value + 32);
        }
        for ($pair = 0; $pair <= 99; $pair++) {
            $samples[] = str_pad((string) $pair, 2, '0', STR_PAD_LEFT);
        }

        $patterns = [];
        foreach ($samples as $sample) {
            $modules = $this->code128->generate($sample)->toModuleString();
            $this->assertSame('1', $modules[0], 'a symbol must open with a bar');
            $this->assertSame('1', $modules[\strlen($modules) - 1], 'and close with one');

            $characters = (\strlen($modules) - 13) / 11;
            $this->assertSame((float) (int) $characters, (float) $characters);

            for ($index = 0; $index < (int) $characters; $index++) {
                $pattern = substr($modules, $index * 11, 11);
                $widths = $this->widths($pattern);

                $this->assertCount(6, $widths, "character $index of '$sample' must be 6 elements");
                $this->assertSame(11, array_sum($widths));
                $this->assertSame(
                    0,
                    ($widths[0] + $widths[2] + $widths[4]) % 2,
                    "bars of character $index of '$sample' must span an even number of modules"
                );
                $patterns[$pattern] = true;
            }

            $stop = substr($modules, -13);
            $this->assertSame([2, 3, 3, 1, 1, 1, 2], $this->widths($stop), 'stop pattern');
        }

        // Distinct patterns actually observed: 100 values plus Start B and
        // Start C. A duplicate would make two characters indistinguishable.
        $this->assertGreaterThanOrEqual(100, \count($patterns));
    }

    public function testCode128UsesSetCOnlyWhenItPaysForItself(): void
    {
        $backend = new Code128Backend();
        $codeC = 99;
        $startC = 105;

        // Six digits mid-payload: one switch buys three pairs instead of six
        // characters, so it wins.
        $this->assertContains($codeC, $backend->symbolValues('X123456X'));

        // Four digits mid-payload: the switch and the switch back cost as much
        // as they save, so staying in B is not worse.
        $this->assertNotContains($codeC, $backend->symbolValues('X1234X'));

        // Four digits ending the payload need no switch back, so C wins.
        $this->assertContains($codeC, $backend->symbolValues('X1234'));

        $this->assertSame($startC, $backend->symbolValues('1234')[0], 'a digits-only payload starts in C');
    }

    /** @return iterable<string, array{string}> */
    public static function code128RejectedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'tab' => ["A\tB"];
        yield 'newline' => ["A\nB"];
        yield 'null byte' => ["A\0B"];
        yield 'del' => ["A\x7fB"];
        yield 'utf-8 beyond ascii' => ['zażółć'];
    }

    /** @dataProvider code128RejectedProvider */
    public function testCode128RejectsWhatItCannotEncode(string $data): void
    {
        $this->assertFalse($this->code128->canEncode($data));

        $this->expectException(UnsupportedDataException::class);
        Scanme::create()->render($data, Symbology::Code128, Format::Svg);
    }

    public function testCode128AcceptsEveryPrintableAsciiCharacter(): void
    {
        $all = '';
        for ($byte = 32; $byte <= 126; $byte++) {
            $all .= \chr($byte);
        }

        $this->assertTrue($this->code128->canEncode($all));
        $symbol = $this->code128->generate($all);
        $this->assertSame($all, $symbol->getText());
    }

    // ----------------------------------------------------------------- EAN-13

    /**
     * Cross-check the left-hand digit patterns against the other way the same
     * standard states them: four element widths per digit, starting with a
     * space. Two independent transcriptions agreeing is real evidence; a
     * decoder built from the same table would only prove it agrees with itself.
     */
    public function testEan13DigitPatternsMatchTheElementWidthFormulation(): void
    {
        $widthsPerDigit = [
            0 => [3, 2, 1, 1], 1 => [2, 2, 2, 1], 2 => [2, 1, 2, 2], 3 => [1, 4, 1, 1], 4 => [1, 1, 3, 2],
            5 => [1, 2, 3, 1], 6 => [1, 1, 1, 4], 7 => [1, 3, 1, 2], 8 => [1, 2, 1, 3], 9 => [3, 1, 1, 2],
        ];

        foreach ($widthsPerDigit as $digit => $widths) {
            $expected = '';
            foreach ($widths as $index => $width) {
                $expected .= str_repeat($index % 2 === 0 ? '0' : '1', $width);
            }

            // Digit 0 of an EAN-13 whose first digit is 0 uses all-odd parity,
            // so the six left positions are the plain left-odd patterns.
            $symbol = $this->ean13->generate('0' . str_repeat((string) $digit, 6) . '00000');
            $this->assertSame(
                $expected,
                substr($symbol->toModuleString(), 3, 7),
                "left-odd pattern for digit $digit"
            );
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function ean13Provider(): iterable
    {
        // Both check digits worked out by hand from the weighted modulo 10.
        yield 'twelve digits get a check digit' => ['590123412345', '5901234123457'];
        yield 'thirteen digits are verified' => ['5901234123457', '5901234123457'];
        yield 'another article number' => ['400638133393', '4006381333931'];
        yield 'check digit zero' => ['000000000000', '0000000000000'];
    }

    /** @dataProvider ean13Provider */
    public function testEan13NormalisesAndVerifiesTheCheckDigit(string $data, string $expected): void
    {
        $symbol = $this->ean13->generate($data);

        $this->assertSame($expected, $symbol->getText());
        $this->assertSame((int) $expected[12], $symbol->getMetadataValue('checkDigit'));
        $this->assertTrue($this->ean13->canEncode($data));
    }

    public function testEan13RejectsAWrongCheckDigitInsteadOfCorrectingIt(): void
    {
        // A caller passing 13 digits is asserting a specific article number;
        // silently encoding a different one would be worse than failing.
        $this->assertFalse($this->ean13->canEncode('5901234123450'));

        $this->expectException(UnsupportedDataException::class);
        Scanme::create()->render('5901234123450', Symbology::Ean13, Format::Svg);
    }

    /** @return iterable<string, array{string}> */
    public static function ean13RejectedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['12345678901'];
        yield 'too long' => ['12345678901234'];
        yield 'not digits' => ['59012341234A'];
        yield 'spaces' => ['5901234 12345'];
    }

    /** @dataProvider ean13RejectedProvider */
    public function testEan13RejectsMalformedInput(string $data): void
    {
        $this->assertFalse($this->ean13->canEncode($data));
    }

    public function testEan13HasTheFixedStructureTheStandardPrescribes(): void
    {
        $symbol = $this->ean13->generate('5901234123457');
        $modules = $symbol->rows()[0];

        $this->assertSame(95, $symbol->getWidth(), '3 + 42 + 5 + 42 + 3 modules');
        $this->assertSame('101', substr($modules, 0, 3), 'start guard');
        $this->assertSame('01010', substr($modules, 45, 5), 'centre guard');
        $this->assertSame('101', substr($modules, 92, 3), 'end guard');

        // The asymmetry is in the standard and a caller must not have to know it.
        $this->assertSame(11, $symbol->getQuietZone()->left);
        $this->assertSame(7, $symbol->getQuietZone()->right);

        // Left digits end with a bar, right digits start with one; that
        // reflected parity is what lets a scanner read the symbol either way.
        for ($position = 0; $position < 6; $position++) {
            $left = substr($modules, 3 + $position * 7, 7);
            $right = substr($modules, 50 + $position * 7, 7);
            $this->assertSame('0', $left[0], "left digit $position starts with a space");
            $this->assertSame('1', $left[6], "left digit $position ends with a bar");
            $this->assertSame('1', $right[0], "right digit $position starts with a bar");
            $this->assertSame('0', $right[6], "right digit $position ends with a space");
            $this->assertSame(
                0,
                substr_count($right, '1') % 2,
                "right digit $position must have even bar parity"
            );
        }
    }

    /**
     * The first digit is not drawn: it selects which of the six left-hand
     * digits use even parity, so two article numbers differing only in the
     * first digit must still produce different modules.
     */
    public function testEan13FirstDigitIsCarriedByLeftHandParity(): void
    {
        $seen = [];
        for ($first = 0; $first <= 9; $first++) {
            $symbol = $this->ean13->generate($first . '00000000000');
            $modules = $symbol->rows()[0];

            $parity = '';
            for ($position = 0; $position < 6; $position++) {
                $digit = substr($modules, 3 + $position * 7, 7);
                $parity .= substr_count($digit, '1') % 2 === 1 ? 'L' : 'G';
            }

            $this->assertSame('L', $parity[0], 'the first left position is always odd parity');
            $seen[$parity] = $first;
        }

        $this->assertCount(10, $seen, 'each first digit needs its own parity pattern');
    }

    /**
     * The three guards run below the other bars, which is what leaves room for
     * the digits. Row heights carry that, so the grid stays a plain two-level
     * bitmap and every renderer draws it without a special case.
     */
    public function testEan13GuardBarsDescendBelowTheOtherBars(): void
    {
        $symbol = $this->ean13->generate('5901234123457');

        $this->assertSame(2, $symbol->getHeight());
        $this->assertSame([64, 5], $symbol->getRowHeights());
        $this->assertSame(69, $symbol->getModuleHeight());
        $this->assertFalse($symbol->hasUniformRows());

        $descenders = $symbol->rows()[1];
        $expected = str_repeat('0', 95);
        foreach ([[0, '101'], [45, '01010'], [92, '101']] as [$offset, $guard]) {
            $expected = substr_replace($expected, $guard, $offset, \strlen($guard));
        }

        $this->assertSame($expected, $descenders, 'only the guards descend');
    }

    // ------------------------------------------------------- both symbologies

    public function testBothCarryHumanReadableTextAndSaySo(): void
    {
        $scanme = Scanme::create();

        foreach ([[Symbology::Code128, 'ABC-123'], [Symbology::Ean13, '5901234123457']] as [$name, $data]) {
            $capabilities = $scanme->getRegistry()->getGenerator($name)->getCapabilities();
            $this->assertTrue($capabilities->providesText, $capabilities->title);
            $this->assertFalse($capabilities->hasErrorCorrection(), $capabilities->title . ' has no ECC');

            $this->assertStringContainsString($data, $scanme->render($data, $name, Format::Svg));
            $this->assertFalse($scanme->supports($name, Format::Png), 'the fontless PNG writer cannot print it');
        }
    }

    public function testTextlessRenderingUnlocksTheFontlessPngWriter(): void
    {
        $scanme = Scanme::create();

        try {
            $scanme->render('5901234123457', Symbology::Ean13, Format::Png);
            $this->fail('expected the PNG renderer to refuse the human-readable text');
        } catch (IncompatibleRendererException $e) {
            $this->assertStringContainsString('showText: false', $e->getMessage(), 'the message must say the way out');
        }

        $png = $scanme->render(
            '5901234123457',
            Symbology::Ean13,
            Format::Png,
            new PngOptions(moduleSize: 2, showText: false)
        );
        $header = unpack('Nwidth/Nheight', substr($png, 16, 8));

        $this->assertSame((95 + 11 + 7) * 2, $header['width']);
        $this->assertSame(69 * 2, $header['height'], 'bars plus the guard descent');
    }

    public function testBarHeightIsPresentationAndDoesNotTouchTheModules(): void
    {
        $symbol = $this->ean13->generate('5901234123457');
        $scanme = Scanme::create();

        $short = $scanme->renderSymbol($symbol, Format::Png, new PngOptions(moduleSize: 1, barHeight: 20, showText: false));
        $tall = $scanme->renderSymbol($symbol, Format::Png, new PngOptions(moduleSize: 1, barHeight: 80, showText: false));

        $this->assertSame(20, unpack('Nwidth/Nheight', substr($short, 16, 8))['height']);
        $this->assertSame(80, unpack('Nwidth/Nheight', substr($tall, 16, 8))['height']);
        // The guard descent keeps its share of the height rather than being
        // flattened away by the override.
        $this->assertSame(95, $symbol->getWidth(), 'the modules are untouched either way');
    }

    public function testEncodingIsStable(): void
    {
        // Two calls, and two generator instances, must agree module for module.
        foreach ([[$this->code128, 'ABC-123'], [$this->ean13, '5901234123457']] as [$generator, $data]) {
            $first = $generator->generate($data)->toModuleString();
            $this->assertSame($first, $generator->generate($data)->toModuleString());
        }

        $this->assertSame(
            (new Code128Generator())->generate('ABC-123')->toModuleString(),
            $this->code128->generate('ABC-123')->toModuleString()
        );
        $this->assertSame(
            (new Ean13Generator())->generate('590123412345')->toModuleString(),
            $this->ean13->generate('5901234123457')->toModuleString(),
            'a supplied and a computed check digit must give the same symbol'
        );
    }

    public function testSymbolShapeMatchesTheDeclaredCapabilities(): void
    {
        foreach ([[$this->code128, 'ABC'], [$this->ean13, '590123412345']] as [$generator, $data]) {
            $capabilities = $generator->getCapabilities();
            $symbol = $generator->generate($data);

            $this->assertSame($capabilities->dimension, $symbol->getDimension(), $capabilities->title);
            $this->assertSame($capabilities->moduleShape, $symbol->getModuleShape(), $capabilities->title);
            $this->assertSame(
                $capabilities->name,
                $symbol->getMetadataValue('symbology'),
                $capabilities->title
            );
            $this->assertSame('php', $generator->getActiveBackend()?->getName());
        }
    }

    public function testRegistryFindsTheRightSymbologyForAPayload(): void
    {
        $registry = Scanme::create()->getRegistry();

        // An EAN-shaped payload is encodable by all three, and the caller gets
        // to choose rather than have one guessed for them.
        $this->assertSame(['qrcode', 'code128', 'ean13'], $registry->generatorsFor('5901234123457'));
        $this->assertSame(['qrcode', 'code128'], $registry->generatorsFor('ABC-123'));
        $this->assertSame(['qrcode'], $registry->generatorsFor("binary\0data"));
    }
}
