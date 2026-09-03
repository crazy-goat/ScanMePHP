<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\AustraliaPost;

use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What an Australia Post symbol can be told to be.
 *
 * One decision, and it is a real one: the Format Control Code. The same
 * sorting code drawn as a Reply Paid article and as an ordinary one are two
 * different symbols, and nothing in the data string says which was meant — so
 * it is asked for here rather than guessed, and the default is the ordinary
 * one.
 *
 * There is nothing else to choose. The bar height is a render option, because
 * it has to scale the ascender, tracker and descender together; the two wider
 * Standard codes follow from how much customer information there is; and the
 * error correction is fixed at four Reed–Solomon codewords by the standard.
 */
final readonly class AustraliaPostOptions implements GeneratorOptionsInterface
{
    public function __construct(public Format $format = Format::Standard)
    {
    }
}
