#!/usr/bin/env python3
"""The Intelligent Mail codewords, computed from the specification's rules.

Shared by `intelligent_mail_placement.py`, which needs a character vector to
match zint's bars against, and `intelligent_mail_reference.py`, which needs
payloads that reach every rule. Nothing here is a table: the routing offsets,
the CRC-11 and the 1365 characters are all arithmetic, and the only table in
the symbology — which bar reads which bit of which character — is what the
placement tool measures rather than transcribes.

Kept in Python beside the PHP rather than derived from it: a fixture generated
by the code it verifies would agree with itself.
"""

import string

GENERATOR = 0x0F35
CHARACTER_BITS = 13
CODEWORDS = 10

# Routing code lengths and what the standard adds to each, so that a five digit
# ZIP and a nine digit ZIP+4 that read the same as numbers do not collide: the
# offset is the count of every shorter code that exists.
ROUTING_OFFSET = {0: 0, 5: 1, 9: 100001, 11: 1000100001}


def value(tracking: str, routing: str) -> int:
    """The payload as one 102-bit number."""
    number = ROUTING_OFFSET[len(routing)] + (int(routing) if routing else 0)

    # The second digit of the barcode identifier runs 0 to 4, so it is worth
    # five rather than ten. Everything after it is decimal.
    number = number * 10 + int(tracking[0])
    number = number * 5 + int(tracking[1])
    for digit in tracking[2:]:
        number = number * 10 + int(digit)

    return number


def frame_check(number: int) -> int:
    """The 11-bit frame check sequence over the 102-bit value."""
    data = number.to_bytes(13, "big")
    fcs = 0x7FF

    # The first byte carries six bits of the value, the other twelve carry
    # eight each: 102 bits, most significant first.
    for index, byte in enumerate(data):
        bits = 6 if index == 0 else 8
        shifted = byte << (11 - bits)
        for _ in range(bits):
            if (fcs ^ shifted) & 0x400:
                fcs = ((fcs << 1) ^ GENERATOR) & 0x7FF
            else:
                fcs = (fcs << 1) & 0x7FF
            shifted <<= 1

    return fcs


def reverse(pattern: int) -> int:
    result = 0
    for bit in range(CHARACTER_BITS):
        result = (result << 1) | ((pattern >> bit) & 1)
    return result


def table(bits_set: int, length: int) -> list[int]:
    """The 13-bit patterns with `bits_set` bits set, in the standard's order.

    A pattern and its mirror image are adjacent, counted up from the bottom;
    the patterns that are their own mirror image fill in from the top.
    """
    lut = [0] * length
    lower, upper = 0, length - 1

    for pattern in range(1 << CHARACTER_BITS):
        if bin(pattern).count("1") != bits_set:
            continue
        mirrored = reverse(pattern)
        if mirrored < pattern:
            continue
        if mirrored == pattern:
            lut[upper] = pattern
            upper -= 1
        else:
            lut[lower] = pattern
            lut[lower + 1] = mirrored
            lower += 2

    return lut


CHARACTERS = table(5, 1287) + table(2, 78)


def codewords(number: int, fcs: int) -> list[int]:
    words = [0] * CODEWORDS

    # The last codeword is worth half as much as the others: its low bit is
    # spent on the frame check sequence instead.
    words[9], number = number % 636, number // 636
    for index in range(8, 0, -1):
        words[index], number = number % 1365, number // 1365
    words[0] = number

    words[9] *= 2
    if fcs & 0x400:
        words[0] += 659

    return words


def characters(words: list[int], fcs: int) -> list[int]:
    """The ten 13-bit characters, each inverted when its check bit is set."""
    return [
        CHARACTERS[word] ^ (0x1FFF if fcs & (1 << index) else 0)
        for index, word in enumerate(words)
    ]


def vector(tracking: str, routing: str) -> list[int]:
    number = value(tracking, routing)
    fcs = frame_check(number)
    return characters(codewords(number, fcs), fcs)


def zint(tracking: str, routing: str) -> str:
    """The payload in the form zint's ONECODE takes it."""
    return tracking + ("-" + routing if routing else "")
