#ifdef BUILD_SSE2
#include <emmintrin.h>
#include "../mask.hpp"
#include <algorithm>
#include <cstdlib>

namespace scanme {

static int count_dark_sse2(const QRMatrix& m) {
    const int total = m.size * m.size;
    const uint8_t* data = m.modules.data();
    __m128i acc = _mm_setzero_si128();
    int i = 0;
    for (; i + 16 <= total; i += 16) {
        __m128i v = _mm_loadu_si128(reinterpret_cast<const __m128i*>(data + i));
        acc = _mm_add_epi64(acc, _mm_sad_epu8(v, _mm_setzero_si128()));
    }
    int dark = static_cast<int>(_mm_cvtsi128_si32(acc))
             + static_cast<int>(_mm_cvtsi128_si32(_mm_srli_si128(acc, 8)));
    for (; i < total; ++i) dark += data[i];
    return dark;
}

int penalty_sse2(const QRMatrix& m) {
    int penalty = 0;
    const int n = m.size;

    for (int y = 0; y < n; ++y) {
        int run = 1;
        for (int x = 1; x < n; ++x) {
            if (m.get(x, y) == m.get(x-1, y)) {
                if (++run == 5) penalty += 3;
                else if (run > 5) penalty += 1;
            } else { run = 1; }
        }
    }
    for (int x = 0; x < n; ++x) {
        int run = 1;
        for (int y = 1; y < n; ++y) {
            if (m.get(x, y) == m.get(x, y-1)) {
                if (++run == 5) penalty += 3;
                else if (run > 5) penalty += 1;
            } else { run = 1; }
        }
    }
    for (int y = 0; y < n-1; ++y) {
        for (int x = 0; x < n-1; ++x) {
            uint8_t c = m.get(x, y);
            if (c == m.get(x+1, y) && c == m.get(x, y+1) && c == m.get(x+1, y+1))
                penalty += 3;
        }
    }
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

    int dark = count_dark_sse2(m);
    int total = n * n;
    int prev = (dark * 100 / total / 5) * 5;
    int next = prev + 5;
    penalty += std::min(std::abs(prev - 50) / 5, std::abs(next - 50) / 5) * 10;

    return penalty;
}

} // namespace scanme
#endif // BUILD_SSE2
