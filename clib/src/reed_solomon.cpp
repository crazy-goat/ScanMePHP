#include "reed_solomon.hpp"
#include "tables.hpp"
#include <vector>

namespace scanme {

std::vector<uint8_t> rs_generate_ec(
    const std::vector<uint8_t>& data,
    int ec_count
) {
    std::vector<uint8_t> result(ec_count, 0);

    // Build generator polynomial
    std::vector<uint8_t> gen(ec_count + 1, 0);
    gen[0] = 1;
    for (int i = 0; i < ec_count; ++i) {
        for (int j = i; j >= 0; --j) {
            gen[j + 1] ^= gf_mul(gen[j], GF_EXP[i]);
        }
    }

    for (uint8_t byte : data) {
        uint8_t factor = byte ^ result[0];
        for (int i = 0; i < ec_count - 1; ++i) {
            result[i] = result[i + 1] ^ gf_mul(factor, gen[ec_count - 1 - i]);
        }
        result[ec_count - 1] = gf_mul(factor, gen[0]);
    }

    return result;
}

} // namespace scanme
