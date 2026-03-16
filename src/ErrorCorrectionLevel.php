<?php

declare(strict_types=1);

namespace ScanMePHP;

enum ErrorCorrectionLevel: int
{
    case Low = 0;       // ~7% correction
    case Medium = 1;    // ~15% correction
    case Quartile = 2;  // ~25% correction
    case High = 3;      // ~30% correction

    public function getCapacityPercentage(): int
    {
        return match ($this) {
            self::Low => 7,
            self::Medium => 15,
            self::Quartile => 25,
            self::High => 30,
        };
    }
}
