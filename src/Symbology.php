<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * The symbologies shipped with this library.
 *
 * Every API that takes a symbology accepts `string|Symbology`, never Symbology
 * alone: the registry is open, and a closed enum would make a generator
 * registered from outside this package a second-class citizen that could only
 * ever be addressed by a magic string. Built-ins get the enum for
 * autocompletion and typo safety, custom ones keep working with a plain name.
 *
 * A case is added here only once the generator behind it is registered by
 * default — an enum case that resolves to UnknownGeneratorException would be
 * worse than no case at all.
 */
enum Symbology: string
{
    case QrCode = 'qrcode';
    case Gs1Qr = 'gs1-qr';
    case Code128 = 'code128';
    case Gs1128 = 'gs1-128';
    case Code39 = 'code39';
    case Code39Extended = 'code39ext';
    case Code93 = 'code93';
    case Codabar = 'codabar';
    case Ean13 = 'ean13';
    case Ean8 = 'ean8';
    case UpcA = 'upc-a';
    case UpcE = 'upc-e';
    case Ean2 = 'ean2';
    case Ean5 = 'ean5';
    case Itf = 'itf';
    case Itf14 = 'itf14';
    case DataMatrix = 'data-matrix';
    case Aztec = 'aztec';
    case Gs1DataMatrix = 'gs1-data-matrix';
    case Pdf417 = 'pdf417';
    case MaxiCode = 'maxicode';
    case DataBarOmni = 'databar-omni';
    case DataBarLimited = 'databar-limited';
    case DataBarExpanded = 'databar-expanded';

    /** The name the registry resolves, for either accepted form. */
    public static function nameOf(string|self $symbology): string
    {
        return $symbology instanceof self ? $symbology->value : $symbology;
    }
}
