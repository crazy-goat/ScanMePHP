// Verifies the lane-parallel mask selection against the scalar nayuki-style
// reference for every version (1..40), every ECL and many random payloads,
// comparing all 8 penalties (not just the winner).
#include "encoder.hpp"
#include "mask.hpp"
#include "matrix.hpp"
#include "tables.hpp"
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <string>

// Internal pipeline pieces re-declared here (they are static in encoder.cpp),
// so we drive the matrix build through the public encode() and rebuild the
// pre-mask matrix by un-applying the chosen mask + format bits. Simpler: build
// the unmasked matrix ourselves via the same placement functions.
namespace scanme {
int find_version_for_test(size_t len, int ecl) {
    for (int v = 1; v <= 40; ++v)
        if (static_cast<int>(len) <= BYTE_CAPACITY[v-1][ecl]) return v;
    return -1;
}
}

static uint64_t rng_state = 0x9E3779B97F4A7C15ull;
static uint64_t rnd() {
    rng_state ^= rng_state << 13; rng_state ^= rng_state >> 7; rng_state ^= rng_state << 17;
    return rng_state;
}

int main(int argc, char** argv) {
    int rounds = argc > 1 ? std::atoi(argv[1]) : 6;
    int failures = 0, checked = 0;
    for (int version = 1; version <= 40; ++version) {
        for (int ecl = 0; ecl < 4; ++ecl) {
            int cap = scanme::BYTE_CAPACITY[version-1][ecl];
            for (int r = 0; r < rounds; ++r) {
                // Vary payload length so the padding/EC layout differs too.
                int len = r == 0 ? cap : 1 + static_cast<int>(rnd() % static_cast<uint64_t>(cap));
                if (version > 1 && len <= scanme::BYTE_CAPACITY[version-2][ecl])
                    len = scanme::BYTE_CAPACITY[version-2][ecl] + 1;
                std::string data(static_cast<size_t>(len), 0);
                for (auto& ch : data) ch = static_cast<char>(rnd());
                // Bias some payloads toward long runs to exercise the n>=2 path.
                if (r % 3 == 1) for (size_t i = 0; i < data.size(); ++i) if (rnd() % 4) data[i] = (rnd() & 1) ? 0 : static_cast<char>(0xFF);

                // Build the pre-mask matrix exactly like encode() does, via a
                // debug entry point that stops before mask selection.
                scanme::QRMatrix m = scanme::build_unmasked_matrix(data.data(), data.size(), static_cast<scanme::ECL>(ecl));
                int p_fast[8], p_ref[8];
                int best_fast = scanme::select_best_mask(m, ecl, p_fast);
                int best_ref  = scanme::select_best_mask_reference(m, ecl, p_ref);
                ++checked;
                if (best_fast != best_ref || std::memcmp(p_fast, p_ref, sizeof(p_fast)) != 0) {
                    ++failures;
                    std::printf("MISMATCH v%d ecl=%d len=%d round=%d: best %d vs %d\n", version, ecl, len, r, best_fast, best_ref);
                    for (int k = 0; k < 8; ++k) std::printf("  mask %d: fast=%d ref=%d\n", k, p_fast[k], p_ref[k]);
                    if (failures > 10) return 1;
                }
            }
        }
    }
    std::printf("%d matrices checked, %d mismatches\n", checked, failures);
    return failures ? 1 : 0;
}
