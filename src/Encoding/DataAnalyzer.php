<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

class DataAnalyzer
{
    private const ALPHANUMERIC_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    public function analyze(string $data): Mode
    {
        if ($this->isNumeric($data)) {
            return Mode::Numeric;
        }
        
        if ($this->isAlphanumeric($data)) {
            return Mode::Alphanumeric;
        }
        
        if ($this->isKanji($data)) {
            return Mode::Kanji;
        }
        
        return Mode::Byte;
    }

    public function isNumeric(string $data): bool
    {
        return preg_match('/^[0-9]+$/', $data) === 1;
    }

    public function isAlphanumeric(string $data): bool
    {
        for ($i = 0; $i < strlen($data); $i++) {
            if (strpos(self::ALPHANUMERIC_CHARS, $data[$i]) === false) {
                return false;
            }
        }
        return true;
    }

    public function isKanji(string $data): bool
    {
        // Check if data is valid Shift-JIS Kanji
        // This is a simplified check
        if (strlen($data) % 2 !== 0) {
            return false;
        }
        
        for ($i = 0; $i < strlen($data); $i += 2) {
            $byte1 = ord($data[$i]);
            $byte2 = ord($data[$i + 1]);
            $value = ($byte1 << 8) | $byte2;
            
            if (!(($value >= 0x8140 && $value <= 0x9ffc) ||
                  ($value >= 0xe040 && $value <= 0xebbf))) {
                return false;
            }
        }
        
        return true;
    }

    public function getDataLength(string $data, Mode $mode): int
    {
        return match ($mode) {
            Mode::Kanji => (int) (strlen($data) / 2),
            default => strlen($data),
        };
    }

    public function getBitsPerChar(Mode $mode): int
    {
        return match ($mode) {
            Mode::Numeric => 10 / 3, // ~3.33 bits per digit
            Mode::Alphanumeric => 11 / 2, // 5.5 bits per char
            Mode::Byte => 8,
            Mode::Kanji => 13 / 2, // 6.5 bits per char
        };
    }
}
