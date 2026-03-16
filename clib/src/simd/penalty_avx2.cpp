#ifdef BUILD_AVX2
#include <immintrin.h>
#include "../mask.hpp"
#include <algorithm>
#include <cstdlib>

namespace scanme {

static int count_dark_avx2(const QRMatrix& m) {
    const int total = m.size * m.size;
    const uint8_t* data = m.modules.data();
    __m256i acc = _mm256_setzero_si256();
    int i = 0;
    for (; i + 32 <= total; i += 32) {
        __m256i v = _mm256_loadu_si256(reinterpret_cast<const __m256i*>(data + i));
        acc = _mm256_add_epi64(acc, _mm256_sad_epu8(v, _mm256_setzero_si256()));
    }
    __m128i lo  = _mm256_castsi256_si128(acc);
    __m128i hi  = _mm256_extracti128_si256(acc, 1);
    __m128i sum = _mm_add_epi64(lo, hi);
    int dark = static_cast<int>(_mm_cvtsi128_si32(sum))
             + static_cast<int>(_mm_cvtsi128_si32(_mm_srli_si128(sum, 8)));
    for (; i < total; ++i) dark += data[i];
    return dark;
}

int penalty_avx2(const QRMatrix& m) {
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
    for (int y = 0; y < n-1; ++y)
        for (int x = 0; x < n-1; ++x) {
            uint8_t c = m.get(x, y);
            if (c == m.get(x+1, y) && c == m.get(x, y+1) && c == m.get(x+1, y+1))
                penalty += 3;
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

    int dark = count_dark_avx2(m);
    int total = n * n;
    int prev = (dark * 100 / total / 5) * 5;
    int next = prev + 5;
    penalty += std::min(std::abs(prev - 50) / 5, std::abs(next - 50) / 5) * 10;

    return penalty;
}

} // namespace scanme
#endif // BUILD_AVX2
