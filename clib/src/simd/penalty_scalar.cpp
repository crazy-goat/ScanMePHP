#include "../mask.hpp"
#include <algorithm>
#include <cstdlib>

namespace scanme {

int penalty_scalar(const QRMatrix& m) {
    int penalty = 0;
    const int n = m.size;

    // Rule 1: 5+ consecutive same-color in rows
    for (int y = 0; y < n; ++y) {
        int run = 1;
        for (int x = 1; x < n; ++x) {
            if (m.get(x, y) == m.get(x-1, y)) {
                if (++run == 5) penalty += 3;
                else if (run > 5) penalty += 1;
            } else { run = 1; }
        }
    }
    // Rule 1: cols
    for (int x = 0; x < n; ++x) {
        int run = 1;
        for (int y = 1; y < n; ++y) {
            if (m.get(x, y) == m.get(x, y-1)) {
                if (++run == 5) penalty += 3;
                else if (run > 5) penalty += 1;
            } else { run = 1; }
        }
    }

    // Rule 2: 2x2 same-color blocks
    for (int y = 0; y < n-1; ++y) {
        for (int x = 0; x < n-1; ++x) {
            uint8_t c = m.get(x, y);
            if (c == m.get(x+1, y) && c == m.get(x, y+1) && c == m.get(x+1, y+1))
                penalty += 3;
        }
    }

    // Rule 3: finder-like patterns
    static const uint8_t pat1[] = {1,0,1,1,1,0,1,0,0,0,0};
    static const uint8_t pat2[] = {0,0,0,0,1,0,1,1,1,0,1};
    for (int y = 0; y < n; ++y) {
        for (int x = 0; x <= n-11; ++x) {
            bool p1 = true, p2 = true;
            for (int i = 0; i < 11 && (p1||p2); ++i) {
                uint8_t v = m.get(x+i, y);
                if (v != pat1[i]) p1 = false;
                if (v != pat2[i]) p2 = false;
            }
            if (p1 || p2) penalty += 40;
        }
    }
    for (int x = 0; x < n; ++x) {
        for (int y = 0; y <= n-11; ++y) {
            bool p1 = true, p2 = true;
            for (int i = 0; i < 11 && (p1||p2); ++i) {
                uint8_t v = m.get(x, y+i);
                if (v != pat1[i]) p1 = false;
                if (v != pat2[i]) p2 = false;
            }
            if (p1 || p2) penalty += 40;
        }
    }

    // Rule 4: dark module ratio
    int dark = 0;
    for (int i = 0; i < n*n; ++i) dark += m.modules[i];
    int total = n * n;
    int prev = (dark * 100 / total / 5) * 5;
    int next = prev + 5;
    penalty += std::min(std::abs(prev - 50) / 5, std::abs(next - 50) / 5) * 10;

    return penalty;
}

} // namespace scanme
