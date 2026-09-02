<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataMatrix;

use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What ECC200 encoding can be told to do.
 *
 * There is no error correction level to pick: ECC200 fixes the amount of
 * recovery data per symbol size, which is why the capabilities report no
 * levels. What remains is the symbol's shape.
 */
final class DataMatrixOptions implements GeneratorOptionsInterface
{
    /**
     * @param bool $rectangular Use the rectangular sizes (8×18 through 16×48)
     *        instead of square ones. They exist for marking parts too narrow
     *        for a square symbol and hold much less data, so square is the
     *        default.
     * @param string|null $size Force a specific size by name, e.g. '26x26'.
     *        Encoding fails if the data does not fit it.
     */
    public function __construct(
        public readonly bool $rectangular = false,
        public readonly ?string $size = null,
    ) {
        if ($this->size !== null && !Specs::byName($this->size) instanceof \CrazyGoat\ScanMePHP\Generator\DataMatrix\SymbolSpec) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Data Matrix size "%s"; available: %s',
                $this->size,
                implode(', ', array_map(
                    static fn (SymbolSpec $spec): string => $spec->name(),
                    Specs::all()
                ))
            ));
        }
    }
}
