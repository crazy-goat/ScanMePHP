<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

enum Mode: int
{
    case Numeric = 1;       // Mode indicator: 0001
    case Alphanumeric = 2;  // Mode indicator: 0010
    case Byte = 4;          // Mode indicator: 0100
    case Kanji = 8;         // Mode indicator: 1000

    public function getModeIndicator(): int
    {
        return match ($this) {
            self::Numeric => 0b0001,
            self::Alphanumeric => 0b0010,
            self::Byte => 0b0100,
            self::Kanji => 0b1000,
        };
    }

    public function getCharacterCountBits(int $version): int
    {
        return match ($this) {
            self::Numeric => $version <= 9 ? 10 : ($version <= 26 ? 12 : 14),
            self::Alphanumeric => $version <= 9 ? 9 : ($version <= 26 ? 11 : 13),
            self::Byte => $version <= 9 ? 8 : 16,
            self::Kanji => $version <= 9 ? 8 : ($version <= 26 ? 10 : 12),
        };
    }
}
