#pragma once
#include "matrix.hpp"
#include <cstdint>
#include <vector>

namespace scanme {

enum class ECL { L = 0, M = 1, Q = 2, H = 3 };

struct EncodeResult {
    QRMatrix matrix;
    int version;
};

EncodeResult encode(const char* data, size_t len, ECL ecl);
EncodeResult encode_forced_mask(const char* data, size_t len, ECL ecl, int mask);
EncodeResult encode_for_debug(const char* data, size_t len, ECL ecl, int penalties_out[8]);

} // namespace scanme
