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

} // namespace scanme
