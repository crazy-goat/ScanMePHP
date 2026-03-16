#include "reed_solomon.hpp"
#include "tables.hpp"
#include <cstring>
#include <memory>
#include <mutex>
#include <vector>

namespace scanme {

// Precomputed factor table: table[factor][i] = gf_mul(gen_poly[i+1], factor)
// Cached per ec_count since the generator polynomial depends on it.
// Max ec_count in QR is ~2430 (v40, ECL=H), so we use dynamic allocation.
struct RsFactorTable {
    int ec_count = 0;
    // table[factor * ec_count + i] = gf_mul(gen_poly[i+1], factor)
    // Only factors 1..255 are stored (factor 0 always gives 0)
    std::vector<uint8_t> table; // 255 * ec_count entries

    const uint8_t* row(uint8_t factor) const noexcept {
        return table.data() + (factor - 1) * ec_count;
    }
};

static RsFactorTable cached_table{};
static std::mutex table_mutex;

static void build_factor_table(RsFactorTable& ft, int ec_count) {
    // Build generator polynomial using dynamic allocation
    std::vector<uint8_t> poly(1, 1); // starts as {1}

    for (int i = 0; i < ec_count; ++i) {
        std::vector<uint8_t> new_poly(poly.size() + 1, 0);
        // GF_EXP has 512 entries covering indices 0..509 correctly.
        // For i >= 255, use modular indexing since alpha^255 = 1 in GF(256).
        uint8_t alpha_i = GF_EXP[i % 255];
        for (size_t j = 0; j < poly.size(); ++j) {
            new_poly[j] ^= poly[j];
            new_poly[j + 1] ^= gf_mul(poly[j], alpha_i);
        }
        poly = std::move(new_poly);
    }

    // Build factor table: for each non-zero factor (1-255),
    // precompute the XOR row
    ft.ec_count = ec_count;
    ft.table.resize(static_cast<size_t>(255 * ec_count));

    for (int factor = 1; factor < 256; ++factor) {
        uint8_t* row = ft.table.data() + (factor - 1) * ec_count;
        for (int i = 0; i < ec_count; ++i) {
            row[i] = gf_mul(poly[static_cast<size_t>(i + 1)], static_cast<uint8_t>(factor));
        }
    }
}

void rs_generate_ec(
    std::span<const uint8_t> data,
    std::span<uint8_t> ecc
) {
    int ec_count = static_cast<int>(ecc.size());

    // Get or build factor table
    RsFactorTable* ft;
    {
        std::lock_guard<std::mutex> lock(table_mutex);
        if (cached_table.ec_count != ec_count) {
            build_factor_table(cached_table, ec_count);
        }
        ft = &cached_table;
    }

    // Zero out ECC buffer
    std::memset(ecc.data(), 0, static_cast<size_t>(ec_count));

    // Polynomial long division with precomputed factor table
    for (uint8_t byte : data) {
        uint8_t factor = byte ^ ecc[0];

        // Shift ECC buffer left by 1
        std::memmove(ecc.data(), ecc.data() + 1, static_cast<size_t>(ec_count - 1));
        ecc[ec_count - 1] = 0;

        if (factor != 0) {
            const uint8_t* row = ft->row(factor);
            // XOR the precomputed row into the ECC buffer
            for (int i = 0; i < ec_count; ++i) {
                ecc[i] ^= row[i];
            }
        }
    }
}

} // namespace scanme
