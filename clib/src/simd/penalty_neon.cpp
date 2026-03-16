#ifdef BUILD_NEON
#include <arm_neon.h>
#include "../mask.hpp"
#include <algorithm>
#include <cstdlib>

namespace scanme {

static int count_dark_neon(const QRMatrix& m) {
    const int total = m.size * m.size;
    const uint8_t* data = m.modules.data();
    uint64x2_t acc = vdupq_n_u64(0);
    int i = 0;
    for (; i + 16 <= total; i += 16) {
        uint8x16_t v = vld1q_u8(data + i);
        uint16x8_t s16 = vpaddlq_u8(v);
        uint32x4_t s32 = vpaddlq_u16(s16);
        acc = vaddq_u64(acc, vpaddlq_u32(s32));
    }
    int dark = static_cast<int>(vgetq_lane_u64(acc, 0) + vgetq_lane_u64(acc, 1));
    for (; i < total; ++i) dark += data[i];
    return dark;
}

int penalty_neon(const QRMatrix& m) {
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

    int dark = count_dark_neon(m);
    int total = n * n;
    int prev = (dark * 100 / total / 5) * 5;
    int next = prev + 5;
    penalty += std::min(std::abs(prev - 50) / 5, std::abs(next - 50) / 5) * 10;

    return penalty;
}

} // namespace scanme
#endif // BUILD_NEON
