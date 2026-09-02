<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * A line of human-readable text, and where across the symbol it belongs.
 *
 * A plain symbol needs none of this: one line, centred, underneath. What needs
 * it is a symbol assembled from two — an EAN-13 with an add-on — where the
 * main digits go under the main bars and the add-on's over its own, and
 * centring either on the whole image would put it under or over the wrong
 * half.
 *
 * The span is in the symbol's own module columns, quiet zone excluded, the
 * same coordinates as the modules. A renderer adds its own offsets.
 */
final class TextRegion
{
    /**
     * @param string $text The line to draw. Empty is not a region.
     * @param TextPlacement $placement Which side of the bars it goes on
     * @param int $x First module column the text is centred over
     * @param int $width Module columns it spans
     */
    public function __construct(
        public readonly string $text,
        public readonly TextPlacement $placement,
        public readonly int $x,
        public readonly int $width,
    ) {
        if ($this->text === '') {
            throw new \InvalidArgumentException('A text region needs text; omit the region instead');
        }

        if ($this->x < 0) {
            throw new \InvalidArgumentException('A text region cannot start left of the symbol, got ' . $this->x);
        }

        if ($this->width < 1) {
            throw new \InvalidArgumentException('A text region must span at least one module, got ' . $this->width);
        }
    }

    /** One module past the last column this region covers. */
    public function end(): int
    {
        return $this->x + $this->width;
    }

    /** Whether two regions would be drawn over one another. */
    public function overlaps(self $other): bool
    {
        return $this->placement === $other->placement
            && $this->x < $other->end()
            && $other->x < $this->end();
    }

    /** The module column the text should be centred on. */
    public function centre(): int
    {
        return $this->x + intdiv($this->width, 2);
    }
}
