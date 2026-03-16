#include "reed_solomon.hpp"
#include "tables.hpp"
#include <vector>

namespace scanme {

std::vector<uint8_t> rs_generate_ec(
    const std::vector<uint8_t>& data,
    int ec_count
) {
    // Build generator polynomial: poly[0]=leading coeff, poly[ec_count]=constant
    std::vector<uint8_t> poly = {1};
    for (int i = 0; i < ec_count; ++i) {
        std::vector<uint8_t> newPoly(static_cast<size_t>(poly.size() + 1), 0);
        for (int j = 0; j < static_cast<int>(poly.size()); ++j) {
            newPoly[j] ^= poly[j];
            newPoly[j + 1] ^= gf_mul(poly[j], GF_EXP[i]);
        }
        poly = std::move(newPoly);
    }

    // Polynomial long division
    std::vector<uint8_t> ecc(static_cast<size_t>(ec_count), 0);
    for (uint8_t byte : data) {
        uint8_t factor = byte ^ ecc[0];
        for (int i = 0; i < ec_count - 1; ++i)
            ecc[i] = ecc[i + 1];
        ecc[ec_count - 1] = 0;
        for (int i = 0; i < ec_count; ++i)
            ecc[i] ^= gf_mul(poly[i + 1], factor);
    }
    return ecc;
}

} // namespace scanme
