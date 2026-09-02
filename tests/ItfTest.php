<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\Itf\ItfGenerator;
use CrazyGoat\ScanMePHP\Generator\Itf\ItfOptions;
use CrazyGoat\ScanMePHP\Generator\Itf\Patterns;
use CrazyGoat\ScanMePHP\Generator\Itf14\Backend\PhpBackend as Itf14Backend;
use CrazyGoat\ScanMePHP\Generator\Itf14\Itf14Generator;
use CrazyGoat\ScanMePHP\Generator\Itf14\Itf14Options;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ITF and ITF-14 beyond their module patterns, which ItfReferenceTest pins
 * against an independent encoder over all one hundred digit pairs.
 *
 * What is left is what the bars cannot show: that the table satisfies the
 * two-of-five invariant, that a pair really is one digit's elements drawn as
 * bars and another's as the spaces between them, that an odd digit count is
 * refused rather than padded, and that ITF-14's frame encloses the quiet zone
 * rather than replacing it.
 */
class ItfTest extends TestCase
{
    private const WIDE_PER_DIGIT = 2;

    /**
     * Five elements, exactly two of them wide — the invariant the symbology is
     * named for, and the one a scanner uses to reject a character it half read.
     */
    public function testEveryPatternIsFiveElementsWithTwoWide(): void
    {
        foreach (Patterns::PATTERNS as $digit => $pattern) {
            self::assertSame(Patterns::ELEMENTS, \strlen($pattern), "pattern for {$digit}");
            self::assertMatchesRegularExpression('/^[nw]{5}$/', $pattern, "pattern for {$digit}");
            self::assertSame(
                self::WIDE_PER_DIGIT,
                substr_count($pattern, 'w'),
                "wide elements in {$digit}"
            );
        }

        self::assertCount(
            10,
            array_unique(Patterns::PATTERNS),
            'two digits share a pattern, which makes one of them unreadable'
        );
    }

    /**
     * The interleave itself: the first digit of a pair supplies the bars and
     * the second the spaces, and getting that backwards produces a symbol that
     * scans as the digits transposed.
     */
    #[DataProvider('pairProvider')]
    public function testAPairIsOneDigitsBarsAndAnothersSpaces(string $pair): void
    {
        $symbol = Scanme::create()->generate($pair, Symbology::Itf);
        $modules = $symbol->toModuleString();

        // Between the four-module start guard and the stop guard.
        $block = substr($modules, \strlen(Patterns::START), -\strlen(Patterns::stop(3)));
        $elements = $this->runLengths($block);

        self::assertCount(10, $elements, 'a pair is ten elements');

        $bars = '';
        $spaces = '';
        foreach ($elements as $index => $width) {
            $kind = $width === 1 ? 'n' : 'w';
            if ($index % 2 === 0) {
                $bars .= $kind;
            } else {
                $spaces .= $kind;
            }
        }

        self::assertSame(Patterns::PATTERNS[(int) $pair[0]], $bars, 'bars come from the first digit');
        self::assertSame(Patterns::PATTERNS[(int) $pair[1]], $spaces, 'spaces come from the second');
    }

    /** @return iterable<string, array{string}> */
    public static function pairProvider(): iterable
    {
        // A pair of equal digits cannot distinguish the two roles, so the
        // cases here are deliberately asymmetric.
        foreach (['01', '10', '27', '72', '39', '93', '05', '50'] as $pair) {
            yield $pair => [$pair];
        }
    }

    public function testTheGuardsAreAsymmetricSoTheScanDirectionIsKnown(): void
    {
        $modules = Scanme::create()->generate('1234', Symbology::Itf)->toModuleString();

        self::assertSame(Patterns::START, substr($modules, 0, 4));
        self::assertSame(Patterns::stop(3), substr($modules, -5));
        self::assertNotSame(
            Patterns::START,
            strrev(Patterns::stop(3)),
            'a symmetric pair of guards would leave a reversed scan undetectable'
        );
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function widthProvider(): iterable
    {
        // Payload, wide ratio, expected width.
        yield 'one pair, ratio 3' => ['12', 3, 27];
        yield 'one pair, ratio 2' => ['12', 2, 4 + 14 + 4];
        yield 'five pairs, ratio 3' => ['1234567890', 3, 99];
        yield 'eight pairs, ratio 3' => ['0000000000000000', 3, 153];
    }

    #[DataProvider('widthProvider')]
    public function testTheWidthIsThePairsPlusTwoAsymmetricGuards(
        string $data,
        int $ratio,
        int $expected
    ): void {
        $symbol = Scanme::create()->generate($data, Symbology::Itf, new ItfOptions(wideRatio: $ratio));

        self::assertSame($expected, $symbol->getWidth());
        self::assertSame($expected, Patterns::width(\strlen($data), $ratio));
        self::assertSame(1, $symbol->getHeight(), 'ITF has no descender row');
    }

    public function testAWiderRatioWidensOnlyTheWideElements(): void
    {
        $scanme = Scanme::create();
        $narrow = $scanme->generate('1234567890', Symbology::Itf, new ItfOptions(wideRatio: 2));
        $wide = $scanme->generate('1234567890', Symbology::Itf, new ItfOptions(wideRatio: 3));

        // Four wide elements per pair, five pairs, plus the stop guard's bar.
        self::assertSame($narrow->getWidth() + 4 * 5 + 1, $wide->getWidth());
        self::assertSame(2, $narrow->getMetadataValue('wideRatio'));
    }

    /** @return iterable<string, array{int}> */
    public static function badRatioProvider(): iterable
    {
        yield 'one' => [1];
        yield 'four' => [4];
        yield 'zero' => [0];
    }

    #[DataProvider('badRatioProvider')]
    public function testTheRatioIsRefusedOutsideTheStandardsRange(int $ratio): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be 2 or 3');

        new ItfOptions(wideRatio: $ratio);
    }

    /**
     * The decision this symbology forces, and the one most encoders get wrong.
     *
     * An odd digit count has no partner for its last digit. Padding it with a
     * leading zero is what zxing-cpp and most libraries do, and it is a change
     * to the data: a caller who asks for '123' and is handed a symbol reading
     * '0123' has a different number on their goods. Refusing says so.
     */
    #[DataProvider('oddProvider')]
    public function testAnOddDigitCountIsRefusedRatherThanPadded(string $data): void
    {
        $registry = Scanme::create()->getRegistry();

        self::assertFalse($registry->getGenerator(Symbology::Itf->value)->canEncode($data));

        try {
            Scanme::create()->generate($data, Symbology::Itf);
            self::fail('an odd digit count was encoded, which means it was padded');
        } catch (UnsupportedDataException $expected) {
            self::assertStringContainsString('even number of digits', $expected->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function oddProvider(): iterable
    {
        yield 'three digits' => ['123'];
        yield 'one digit' => ['7'];
        yield 'thirteen digits' => ['1234567890123'];
    }

    /**
     * And the padding, had we done it, would have produced this — kept as a
     * test so the refusal above is measured against what it refuses.
     */
    public function testTheSymbolAPaddingEncoderWouldHaveProducedIsADifferentNumber(): void
    {
        $scanme = Scanme::create();

        self::assertSame(
            '0123',
            $scanme->generate('0123', Symbology::Itf)->getText(),
            'a caller who wants the leading zero writes it'
        );
        self::assertNotSame(
            $scanme->generate('0123', Symbology::Itf)->toModuleString(),
            $scanme->generate('1230', Symbology::Itf)->toModuleString(),
            'which end the zero goes on is not a detail'
        );
    }

    /**
     * The check digit flips which parity encodes, so canEncode() cannot answer
     * without reading the options — one of the few places in this library
     * where that is true.
     */
    public function testTheCheckDigitMakesAnOddPayloadTheEncodableOne(): void
    {
        $generator = new ItfGenerator();
        $with = new ItfOptions(checkDigit: true);

        self::assertTrue($generator->canEncode('1234'));
        self::assertFalse($generator->canEncode('1234', $with));
        self::assertFalse($generator->canEncode('123456789'));
        self::assertTrue($generator->canEncode('123456789', $with));

        $symbol = Scanme::create()->generate('123456789', Symbology::Itf, $with);

        // Weighted modulo 10, the same algorithm as every GTIN.
        self::assertSame(5, $symbol->getMetadataValue('checkDigit'));
        self::assertSame(10, $symbol->getMetadataValue('digits'));
        // Part of the number, so it is printed — unlike Code 39's, which is a
        // property of the symbol rather than a digit of the data.
        self::assertSame('1234567895', $symbol->getText());
        self::assertSame(Patterns::width(10, 3), $symbol->getWidth());
    }

    public function testNonDigitsAreRefusedByBothLayers(): void
    {
        $scanme = Scanme::create();

        foreach (['12A4', '', '12.4', '１２'] as $data) {
            self::assertFalse(
                $scanme->getRegistry()->getGenerator(Symbology::Itf->value)->canEncode($data),
                "canEncode({$data})"
            );
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('digits only');

        (new ItfGenerator())->generate('12A4');
    }

    // --- ITF-14 -----------------------------------------------------------

    /** @return iterable<string, array{string, string, int}> */
    public static function itf14Provider(): iterable
    {
        yield 'computed check digit' => ['1234567890123', '12345678901231', 1];
        yield 'supplied check digit' => ['12345678901231', '12345678901231', 1];
        yield 'zeros' => ['0000000000000', '00000000000000', 0];
        yield 'nines' => ['9999999999999', '99999999999997', 7];
    }

    #[DataProvider('itf14Provider')]
    public function testItf14TakesThirteenDigitsOrFourteen(
        string $data,
        string $expected,
        int $check
    ): void {
        $symbol = Scanme::create()->generate($data, Symbology::Itf14);

        self::assertSame($expected, $symbol->getText());
        self::assertSame($check, $symbol->getMetadataValue('checkDigit'));
    }

    public function testItf14RefusesAWrongCheckDigitRatherThanCorrectingIt(): void
    {
        $registry = Scanme::create()->getRegistry();

        self::assertFalse($registry->getGenerator(Symbology::Itf14->value)->canEncode('12345678901232'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('check digit');

        (new Itf14Generator())->generate('12345678901232');
    }

    /**
     * The bearer bar, and the mistake it is easy to make with it.
     *
     * GS1 measures the 10X quiet zone from the bars and puts the frame outside
     * it. Drawing the frame flush against the bars leaves no quiet zone, and
     * the symbol does not scan — which is how the layout here was corrected,
     * so the arithmetic is pinned rather than described.
     */
    public function testTheBearerBarEnclosesTheQuietZoneRatherThanReplacingIt(): void
    {
        $symbol = Scanme::create()->generate('1234567890123', Symbology::Itf14);
        $bars = Patterns::width(14, 3);

        self::assertSame($bars + 2 * (Patterns::QUIET_ZONE + Itf14Backend::BEARER), $symbol->getWidth());
        self::assertSame(3, $symbol->getHeight());
        self::assertSame(
            [Itf14Backend::BEARER, Patterns::BAR_HEIGHT, Itf14Backend::BEARER],
            $symbol->getRowHeights()
        );
        self::assertTrue($symbol->getQuietZone()->isEmpty(), 'the quiet zone is inside the frame');

        $rows = $this->rowsOf($symbol);

        self::assertSame(str_repeat('1', $symbol->getWidth()), $rows[0], 'top bearer is solid');
        self::assertSame($rows[0], $rows[2], 'the two bearer rows are the same');

        $bearer = str_repeat('1', Itf14Backend::BEARER);
        $quiet = str_repeat('0', Patterns::QUIET_ZONE);

        self::assertSame($bearer . $quiet, substr($rows[1], 0, Itf14Backend::BEARER + Patterns::QUIET_ZONE));
        self::assertSame($quiet . $bearer, substr($rows[1], -(Itf14Backend::BEARER + Patterns::QUIET_ZONE)));
        self::assertSame(
            Patterns::modules('12345678901231', 3),
            substr($rows[1], Itf14Backend::BEARER + Patterns::QUIET_ZONE, $bars)
        );
    }

    /**
     * Turning the frame off leaves an ordinary one-row ITF, quiet zone back
     * outside where every other linear symbology keeps it.
     */
    public function testWithoutTheBearerBarItIsAnOrdinaryLinearSymbol(): void
    {
        $scanme = Scanme::create();
        $symbol = $scanme->generate(
            '1234567890123',
            Symbology::Itf14,
            new Itf14Options(bearerBar: false)
        );

        self::assertSame(1, $symbol->getHeight());
        self::assertSame(Patterns::width(14, 3), $symbol->getWidth());
        self::assertSame(Patterns::QUIET_ZONE, $symbol->getQuietZone()->left);
        self::assertSame(0, $symbol->getQuietZone()->top);
        self::assertFalse($symbol->getMetadataValue('bearerBar'));

        // The bars themselves are the same either way, which is why a decoder
        // reports both as ITF.
        self::assertSame(
            $symbol->toModuleString(),
            Patterns::modules('12345678901231', 3)
        );
    }

    public function testAnItf14IsAFourteenDigitItfPlusThreeThingsACallerShouldNotHaveToRemember(): void
    {
        $scanme = Scanme::create();

        // Same bars, different symbol: the check digit is computed, the count
        // is fixed and the frame is drawn.
        $itf = $scanme->generate('12345678901231', Symbology::Itf);
        $itf14 = $scanme->generate('1234567890123', Symbology::Itf14, new Itf14Options(bearerBar: false));

        self::assertSame($itf->toModuleString(), $itf14->toModuleString());
        self::assertTrue($scanme->getRegistry()->getGenerator(Symbology::Itf14->value)->canEncode('1234567890123'));
        self::assertFalse($scanme->getRegistry()->getGenerator(Symbology::Itf->value)->canEncode('1234567890123'));
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function registrationProvider(): iterable
    {
        yield 'itf' => ['itf', 'ITF', ['itf', 'interleaved-2-of-5', 'i25']];
        yield 'itf14' => ['itf14', 'ITF-14', ['itf14', 'itf-14', 'gtin-14']];
    }

    #[DataProvider('registrationProvider')]
    public function testBothAreRegisteredAndDescribeThemselves(
        string $name,
        string $title,
        array $names
    ): void {
        $capabilities = Scanme::create()->getRegistry()->getGenerator($name)->getCapabilities();

        self::assertSame($title, $capabilities->title);
        self::assertSame($names, $capabilities->allNames());
        self::assertTrue($capabilities->providesText);
        self::assertFalse($capabilities->hasErrorCorrection());
        self::assertNotNull($capabilities->optionsClass);
    }

    public function testEveryOutputFormatAcceptsBoth(): void
    {
        $scanme = Scanme::create();

        foreach ($scanme->getRegistry()->rendererFormats() as $format) {
            foreach ([[Symbology::Itf, '1234567890'], [Symbology::Itf14, '1234567890123']] as [$symbology, $data]) {
                self::assertTrue($scanme->supports($symbology, $format), "{$symbology->value} in {$format}");
                self::assertNotSame('', $scanme->render($data, $symbology, $format));
            }
        }
    }

    public function testBothRunOnThePurePhpBackend(): void
    {
        foreach ([new ItfGenerator(), new Itf14Generator()] as $generator) {
            $backend = $generator->getActiveBackend();

            self::assertNotNull($backend);
            self::assertSame('php', $backend->getName());
        }
    }

    /** @return list<string> */
    private function rowsOf(\CrazyGoat\ScanMePHP\Symbol $symbol): array
    {
        $rows = [];
        for ($row = 0; $row < $symbol->getHeight(); $row++) {
            $rows[] = substr($symbol->toModuleString(), $row * $symbol->getWidth(), $symbol->getWidth());
        }

        return $rows;
    }

    /** @return list<int> */
    private function runLengths(string $modules): array
    {
        preg_match_all('/(.)\1*/', $modules, $matches);

        return array_map(strlen(...), $matches[0]);
    }
}
