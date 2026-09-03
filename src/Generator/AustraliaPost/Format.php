<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\AustraliaPost;

/**
 * Which of Australia Post's barcodes a symbol is.
 *
 * The Format Control Code is the first two digits of every symbol and it says
 * what the mail is, not what it says: the same eight-digit sorting code drawn
 * as a Reply Paid article and as an ordinary one are two different symbols,
 * because the FCC is what routes the article once it has been sorted.
 *
 * It is a choice and not a property of the payload, which is why it is an
 * option rather than something parsed out of the data string — a caller who
 * had to prefix "45" would be writing the encoding by hand, and a caller who
 * mistyped it would get a legal symbol saying something else.
 *
 * Standard is the one with room for customer information; the other three are
 * a sorting code and nothing else. The two further codes the standard defines
 * for longer customer fields — 59 and 62 — are not choices at all: they follow
 * from how much customer information there is, so {@see Payload} picks them.
 */
enum Format: string
{
    /** The Standard Customer Barcode. FCC 11, 59 or 62 by customer field. */
    case Standard = 'standard';

    /** Reply Paid. FCC 45. */
    case ReplyPaid = 'reply-paid';

    /** Routing. FCC 87. */
    case Routing = 'routing';

    /** Redirection. FCC 92. */
    case Redirection = 'redirection';

    /**
     * The Format Control Code, as its two digits.
     *
     * Standard answers for the empty customer field; the two wider codes come
     * from {@see Payload::formatControlCode()}, which knows the field.
     */
    public function code(): string
    {
        return match ($this) {
            self::Standard => '11',
            self::ReplyPaid => '45',
            self::Routing => '87',
            self::Redirection => '92',
        };
    }

    /** Whether this format has a customer information field at all. */
    public function carriesCustomerInformation(): bool
    {
        return $this === self::Standard;
    }
}
