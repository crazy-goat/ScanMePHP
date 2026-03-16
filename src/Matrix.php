<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

class Matrix
{
    private array $data;
    private int $version;
    private int $size;

    public function __construct(int $version)
    {
        $this->version = $version;
        $this->size = 17 + ($version * 4);
        $this->data = array_fill(0, $this->size, array_fill(0, $this->size, false));
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function get(int $x, int $y): bool
    {
        if ($x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size) {
            return false;
        }
        return $this->data[$y][$x];
    }

    public function set(int $x, int $y, bool $value): void
    {
        if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
            $this->data[$y][$x] = $value;
        }
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    private const ALIGNMENT_POSITIONS = [
        [],
        [],
        [6, 18],
        [6, 22],
        [6, 26],
        [6, 30],
        [6, 34],
        [6, 22, 38],
        [6, 24, 42],
        [6, 26, 46],
        [6, 28, 50],
        [6, 30, 54],
        [6, 32, 58],
        [6, 34, 62],
        [6, 26, 46, 66],
        [6, 26, 48, 70],
        [6, 26, 50, 74],
        [6, 30, 54, 78],
        [6, 30, 56, 82],
        [6, 30, 58, 86],
        [6, 34, 62, 90],
        [6, 28, 50, 72, 94],
        [6, 26, 50, 74, 98],
        [6, 30, 54, 78, 102],
        [6, 28, 54, 80, 106],
        [6, 32, 58, 84, 110],
        [6, 30, 58, 86, 114],
        [6, 34, 62, 90, 118],
        [6, 26, 50, 74, 98, 122],
        [6, 30, 54, 78, 102, 126],
        [6, 26, 52, 78, 104, 130],
        [6, 30, 56, 82, 108, 134],
        [6, 34, 60, 86, 112, 138],
        [6, 30, 58, 86, 114, 142],
        [6, 34, 62, 90, 118, 146],
        [6, 30, 54, 78, 102, 126, 150],
        [6, 24, 50, 76, 102, 128, 154],
        [6, 28, 54, 80, 106, 132, 158],
        [6, 32, 58, 84, 110, 136, 162],
        [6, 26, 54, 82, 110, 138, 166],
        [6, 30, 58, 86, 114, 142, 170],
    ];

    public function isReserved(int $x, int $y): bool
    {
        if ($x < 9 && $y < 9) {
            return true;
        }
        
        if ($x >= $this->size - 8 && $y < 9) {
            return true;
        }
        
        if ($x < 9 && $y >= $this->size - 8) {
            return true;
        }
        
        if ($x === 6 || $y === 6) {
            return true;
        }
        
        if ($x === 8 && $y === 4 * $this->version + 9) {
            return true;
        }
        
        if (($x < 9 && $y === 8) || ($x === 8 && $y < 9)) {
            return true;
        }
        if (($x >= $this->size - 8 && $y === 8) || ($x === 8 && $y >= $this->size - 8)) {
            return true;
        }
        
        if ($this->version >= 7) {
            if (($x < 6 && $y >= $this->size - 11 && $y < $this->size - 8) ||
                ($x >= $this->size - 11 && $x < $this->size - 8 && $y < 6)) {
                return true;
            }
        }
        
        if ($this->version >= 2) {
            $positions = self::ALIGNMENT_POSITIONS[$this->version];
            foreach ($positions as $cy) {
                foreach ($positions as $cx) {
                    if ($cx <= 8 && $cy <= 8) continue;
                    if ($cx >= $this->size - 8 && $cy <= 8) continue;
                    if ($cx <= 8 && $cy >= $this->size - 8) continue;
                    if (abs($x - $cx) <= 2 && abs($y - $cy) <= 2) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }

    public function clone(): self
    {
        $clone = new self($this->version);
        $clone->data = $this->data;
        return $clone;
    }
}
