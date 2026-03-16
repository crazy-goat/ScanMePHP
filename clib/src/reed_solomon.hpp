#pragma once
#include <cstdint>
#include <vector>

namespace scanme {

std::vector<uint8_t> rs_generate_ec(
    const std::vector<uint8_t>& data,
    int ec_count
);

} // namespace scanme
