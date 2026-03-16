#ifdef BUILD_AVX512
#include <immintrin.h>
#include "../mask.hpp"
#include <algorithm>
#include <cstdlib>

namespace scanme {

static int count_dark_avx512(const QRMatrix& m) {
    const int total = m.size * m.size;
    const uint8_t* data = m.modules.data();
    __m512i acc = _mm512_setzero_si512();
    int i = 0;
    for (; i + 64 <= total; i += 64) {
        __m512i v = _mm512_loadu_si512(reinterpret_cast<const __m512i*>(data + i));
        acc = _mm512_add_epi64(acc, _mm512_sad_epu8(v, _mm512_setzero_si512()));
    }
    int dark = static_cast<int>(_mm512_reduce_add_epi64(acc));
    for (; i < total; ++i) dark += data[i];
    return dark;
}

static int rule2_avx512(const QRMatrix& m) {
    int penalty = 0;
    const int n = m.size;
    for (int y = 0; y < n-1; ++y) {
        const uint8_t* row0 = m.modules.data() + y * n;
        const uint8_t* row1 = row0 + n;
        int x = 0;
        for (; x + 64 <= n - 1; x += 64) {
            __m512i r0a = _mm512_loadu_si512(row0 + x);
            __m512i r0b = _mm512_loadu_si512(row0 + x + 1);
            __m512i r1a = _mm512_loadu_si512(row1 + x);
            __m512i r1b = _mm512_loadu_si512(row1 + x + 1);
            uint64_t m0 = _mm512_cmpeq_epi8_mask(r0a, r0b);
            uint64_t m1 = _mm512_cmpeq_epi8_mask(r1a, r1b);
            uint64_t mv = _mm512_cmpeq_epi8_mask(r0a, r1a);
            uint64_t blocks = m0 & m1 & mv;
            penalty += 3 * static_cast<int>(__builtin_popcountll(blocks));
        }
        for (; x < n-1; ++x) {
            uint8_t c = row0[x];
            if (c == row0[x+1] && c == row1[x] && c == row1[x+1])
                penalty += 3;
        }
    }
    return penalty;
}

int penalty_avx512(const QRMatrix& m) {
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

    penalty += rule2_avx512(m);

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

    int dark = count_dark_avx512(m);
    int total = n * n;
    int prev = (dark * 100 / total / 5) * 5;
    int next = prev + 5;
    penalty += std::min(std::abs(prev - 50) / 5, std::abs(next - 50) / 5) * 10;

    return penalty;
}

} // namespace scanme
#endif // BUILD_AVX512
