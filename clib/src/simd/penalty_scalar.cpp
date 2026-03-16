#include "../mask.hpp"
#include <algorithm>
#include <cstdlib>

namespace scanme {

int penalty_scalar(const QRMatrix& m, int mask_id) {
    int penalty = 0;
    const int n = m.size;

    // Rule 1: 5+ consecutive same-color in rows
    for (int y = 0; y < n; ++y) {
        int run = 1;
        uint8_t prev = get_masked(m, 0, y, mask_id);
        for (int x = 1; x < n; ++x) {
            uint8_t cur = get_masked(m, x, y, mask_id);
            if (cur == prev) {
                if (++run == 5) penalty += 3;
                else if (run > 5) penalty += 1;
            } else { run = 1; prev = cur; }
        }
    }
    // Rule 1: cols
    for (int x = 0; x < n; ++x) {
        int run = 1;
        uint8_t prev = get_masked(m, x, 0, mask_id);
        for (int y = 1; y < n; ++y) {
            uint8_t cur = get_masked(m, x, y, mask_id);
            if (cur == prev) {
                if (++run == 5) penalty += 3;
                else if (run > 5) penalty += 1;
            } else { run = 1; prev = cur; }
        }
    }

    // Rule 2: 2x2 same-color blocks
    for (int y = 0; y < n-1; ++y) {
        for (int x = 0; x < n-1; ++x) {
            uint8_t c = get_masked(m, x, y, mask_id);
            if (c == get_masked(m, x+1, y, mask_id) &&
                c == get_masked(m, x, y+1, mask_id) &&
                c == get_masked(m, x+1, y+1, mask_id))
                penalty += 3;
        }
    }

    // Rule 3: finder-like patterns — matches PHP MaskSelector exactly
    // PHP checks both patterns with || in a single loop bounded by n-7.
    // Pattern2 (9-elem) can only match when x <= n-9; for x > n-9 it fails
    // because out-of-bounds Matrix::get() returns false (0) but pattern[8]=1.
    static const uint8_t pat7[] = {1,0,1,1,1,0,1};
    static const uint8_t pat9[] = {1,0,1,1,1,0,1,0,1};

    // Check rows
    for (int y = 0; y < n; ++y) {
        for (int x = 0; x <= n-7; ++x) {
            bool p7 = true;
            for (int i = 0; i < 7 && p7; ++i)
                if (get_masked(m, x+i, y, mask_id) != pat7[i]) p7 = false;
            bool p9 = false;
            if (!p7 && x <= n-9) {
                p9 = true;
                for (int i = 0; i < 9 && p9; ++i)
                    if (get_masked(m, x+i, y, mask_id) != pat9[i]) p9 = false;
            }
            if (p7 || p9) penalty += 40;
        }
    }
    // Check cols
    for (int x = 0; x < n; ++x) {
        for (int y = 0; y <= n-7; ++y) {
            bool p7 = true;
            for (int i = 0; i < 7 && p7; ++i)
                if (get_masked(m, x, y+i, mask_id) != pat7[i]) p7 = false;
            bool p9 = false;
            if (!p7 && y <= n-9) {
                p9 = true;
                for (int i = 0; i < 9 && p9; ++i)
                    if (get_masked(m, x, y+i, mask_id) != pat9[i]) p9 = false;
            }
            if (p7 || p9) penalty += 40;
        }
    }

    // Rule 4: dark module ratio — matches PHP evaluateRule4 exactly
    int dark = 0;
    for (int y = 0; y < n; ++y)
        for (int x = 0; x < n; ++x)
            dark += get_masked(m, x, y, mask_id);
    int total = n * n;
    double pct = (dark * 100.0) / total;
    penalty += (int)(std::abs(pct - 50.0) / 5) * 5 * 10;

    return penalty;
}

} // namespace scanme
