<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

use CrazyGoat\ScanMePHP\Exception\InvalidDataException;

class DataEncoder
{
    private const ALPHANUMERIC_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    public function encode(string $data, Mode $mode, int $version): array
    {
        $bits = [];
        
        $modeIndicator = $mode->getModeIndicator();
        for ($i = 3; $i >= 0; $i--) {
            $bits[] = ($modeIndicator >> $i) & 1;
        }
        
        $charCountBits = $mode->getCharacterCountBits($version);
        $charCount = match ($mode) {
            Mode::Kanji => (int) (strlen($data) / 2),
            default => strlen($data),
        };
        
        for ($i = $charCountBits - 1; $i >= 0; $i--) {
            $bits[] = ($charCount >> $i) & 1;
        }
        
        $dataBits = match ($mode) {
            Mode::Numeric => $this->encodeNumeric($data),
            Mode::Alphanumeric => $this->encodeAlphanumeric($data),
            Mode::Byte => $this->encodeByte($data),
            Mode::Kanji => $this->encodeKanji($data),
        };
        
        return array_merge($bits, $dataBits);
    }

    private function encodeNumeric(string $data): array
    {
        $bits = [];
        
        for ($i = 0; $i < strlen($data); $i += 3) {
            $group = substr($data, $i, 3);
            $value = (int) $group;
            $numDigits = strlen($group);
            
            $numBits = match ($numDigits) {
                1 => 4,
                2 => 7,
                3 => 10,
            };
            
            for ($j = $numBits - 1; $j >= 0; $j--) {
                $bits[] = ($value >> $j) & 1;
            }
        }
        
        return $bits;
    }

    private function encodeAlphanumeric(string $data): array
    {
        $bits = [];
        
        for ($i = 0; $i < strlen($data); $i += 2) {
            if ($i + 1 < strlen($data)) {
                // Two characters: 11 bits
                $char1 = strpos(self::ALPHANUMERIC_CHARS, $data[$i]);
                $char2 = strpos(self::ALPHANUMERIC_CHARS, $data[$i + 1]);
                
                if ($char1 === false || $char2 === false) {
                    throw InvalidDataException::incompatibleMode('Alphanumeric', $data);
                }
                
                $value = $char1 * 45 + $char2;
                for ($j = 10; $j >= 0; $j--) {
                    $bits[] = ($value >> $j) & 1;
                }
            } else {
                // Single character: 6 bits
                $char1 = strpos(self::ALPHANUMERIC_CHARS, $data[$i]);
                
                if ($char1 === false) {
                    throw InvalidDataException::incompatibleMode('Alphanumeric', $data);
                }
                
                for ($j = 5; $j >= 0; $j--) {
                    $bits[] = ($char1 >> $j) & 1;
                }
            }
        }
        
        return $bits;
    }

    private function encodeByte(string $data): array
    {
        $bits = [];
        
        for ($i = 0; $i < strlen($data); $i++) {
            $byte = ord($data[$i]);
            for ($j = 7; $j >= 0; $j--) {
                $bits[] = ($byte >> $j) & 1;
            }
        }
        
        return $bits;
    }

    private function encodeKanji(string $data): array
    {
        $bits = [];
        
        // Simplified Kanji encoding - assumes Shift-JIS
        for ($i = 0; $i < strlen($data); $i += 2) {
            if ($i + 1 >= strlen($data)) {
                break;
            }
            
            $byte1 = ord($data[$i]);
            $byte2 = ord($data[$i + 1]);
            $value = ($byte1 << 8) | $byte2;
            
            // Convert to 13-bit value
            if ($value >= 0x8140 && $value <= 0x9ffc) {
                $value -= 0x8140;
            } elseif ($value >= 0xe040 && $value <= 0xebbf) {
                $value -= 0xc140;
            } else {
                continue; // Skip invalid Kanji
            }
            
            $value = (($value >> 8) * 0xc0) + ($value & 0xff);
            
            for ($j = 12; $j >= 0; $j--) {
                $bits[] = ($value >> $j) & 1;
            }
        }
        
        return $bits;
    }

    private function bitsToBytes(array $bits): array
    {
        $bytes = [];
        $count = count($bits);
        
        for ($i = 0; $i < $count; $i += 8) {
            $byte = 0;
            $bitsInThisByte = 0;
            for ($j = 0; $j < 8 && $i + $j < $count; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
                $bitsInThisByte++;
            }
            if ($bitsInThisByte < 8) {
                $byte <<= (8 - $bitsInThisByte);
            }
            $bytes[] = $byte;
        }
        
        return $bytes;
    }

    public function addTerminatorAndPadding(array $bits, int $totalCapacity): array
    {
        $totalBits = $totalCapacity * 8;
        
        $terminatorLength = min(4, $totalBits - count($bits));
        for ($i = 0; $i < $terminatorLength; $i++) {
            $bits[] = 0;
        }
        
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        
        $padBytes = [0xec, 0x11];
        $padIndex = 0;
        while (count($bits) < $totalBits) {
            $byte = $padBytes[$padIndex % 2];
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($byte >> $i) & 1;
            }
            $padIndex++;
        }
        
        return $this->bitsToBytes($bits);
    }
}
