#include "mask.hpp"
#include "simd/cpuid.hpp"
#include <climits>
#include <algorithm>

namespace scanme {

int penalty_scalar(const QRMatrix& m, int mask_id);
#ifdef BUILD_SSE2
int penalty_sse2(const QRMatrix& m, int mask_id);
#endif
#ifdef BUILD_SSE42
int penalty_sse42(const QRMatrix& m, int mask_id);
#endif
#ifdef BUILD_AVX2
int penalty_avx2(const QRMatrix& m, int mask_id);
#endif
#ifdef BUILD_AVX512
int penalty_avx512(const QRMatrix& m, int mask_id);
#endif
#ifdef BUILD_NEON
int penalty_neon(const QRMatrix& m, int mask_id);
#endif

void apply_mask(QRMatrix& m, int mask_id) {
    for (int y = 0; y < m.size; ++y)
        for (int x = 0; x < m.size; ++x)
            if (m.function_[y * m.size + x] == 0 && mask_condition(mask_id, x, y))
                m.set(x, y, m.get(x, y) ^ 1);
}

int calculate_penalty(const QRMatrix& m, int mask_id) {
    const auto& f = cpu_features();
#ifdef BUILD_AVX512
    if (f.avx512f && f.avx512bw) return penalty_avx512(m, mask_id);
#endif
#ifdef BUILD_AVX2
    if (f.avx2) return penalty_avx2(m, mask_id);
#endif
#ifdef BUILD_SSE42
    if (f.sse42) return penalty_sse42(m, mask_id);
#endif
#ifdef BUILD_SSE2
    if (f.sse2) return penalty_sse2(m, mask_id);
#endif
#ifdef BUILD_NEON
    if (f.neon) return penalty_neon(m, mask_id);
#endif
    return penalty_scalar(m, mask_id);
}

int select_best_mask(QRMatrix& m) {
    int best_mask = 0;
    int best_penalty = INT_MAX;
    for (int mask_id = 0; mask_id < 8; ++mask_id) {
        int p = calculate_penalty(m, mask_id);
        if (p < best_penalty) {
            best_penalty = p;
            best_mask = mask_id;
        }
    }
    apply_mask(m, best_mask);
    return best_mask;
}

} // namespace scanme
