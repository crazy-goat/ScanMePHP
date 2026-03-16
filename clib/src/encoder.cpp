#include "encoder.hpp"
#include "reed_solomon.hpp"
#include "matrix.hpp"
#include "mask.hpp"
#include "tables.hpp"
#include "../include/scanme_qr.h"
#include <cstring>
#include <stdexcept>
#include <vector>
#include <algorithm>

namespace scanme {

static int find_version(size_t len, int ecl) {
    for (int v = 1; v <= 40; ++v) {
        if (static_cast<int>(len) <= BYTE_CAPACITY[v-1][ecl])
            return v;
    }
    return -1;
}

// Total data codewords for a version/ecl
static int total_data_codewords(int version, int ecl) {
    const auto& ei = EC_TABLE[version-1][ecl];
    int g1 = ei.g1_blocks * ei.g1_data;
    int g2 = ei.g2_blocks * ei.g2_data;
    return g1 + g2;
}

static void push_bits(std::vector<uint8_t>& bits, uint32_t val, int count) {
    for (int i = count - 1; i >= 0; --i)
        bits.push_back(static_cast<uint8_t>((val >> i) & 1));
}

static std::vector<uint8_t> build_data_codewords(
    const char* data, size_t len, int version, int ecl)
{
    int capacity = total_data_codewords(version, ecl);
    int max_bits = capacity * 8;
    std::vector<uint8_t> bits;
    bits.reserve(static_cast<size_t>(max_bits + 32));

    // Mode indicator: byte mode = 0100
    push_bits(bits, 0b0100, 4);

    // Character count indicator
    int cc_bits = (version <= 9) ? 8 : 16;
    push_bits(bits, static_cast<uint32_t>(len), cc_bits);

    // Data bytes
    for (size_t i = 0; i < len; ++i)
        push_bits(bits, static_cast<uint8_t>(data[i]), 8);

    // Terminator (up to 4 zeros)
    int term = std::min(4, max_bits - static_cast<int>(bits.size()));
    for (int i = 0; i < term; ++i) bits.push_back(0);

    // Pad to byte boundary
    while (bits.size() % 8 != 0) bits.push_back(0);

    // Pad codewords
    static const uint8_t PAD[] = {0xEC, 0x11};
    int pad_idx = 0;
    while (static_cast<int>(bits.size()) < max_bits) {
        push_bits(bits, PAD[pad_idx++ % 2], 8);
    }

    // Pack bits to bytes
    std::vector<uint8_t> codewords;
    codewords.reserve(static_cast<size_t>(capacity));
    for (int i = 0; i < capacity; ++i) {
        uint8_t byte = 0;
        for (int b = 0; b < 8; ++b)
            byte = static_cast<uint8_t>((byte << 1) | bits[static_cast<size_t>(i*8+b)]);
        codewords.push_back(byte);
    }
    return codewords;
}

static std::vector<uint8_t> interleave(
    const std::vector<uint8_t>& data_cw, int version, int ecl)
{
    // PHP's ReedSolomon::encode() treats ALL data as a single block with no
    // interleaving. It generates ECC for the entire data array as one block,
    // using getEccCount() which returns total ECC codewords (not per-block).
    // We must match this exactly.
    const auto& ei = EC_TABLE[version-1][ecl];
    int total_blocks = ei.g1_blocks + ei.g2_blocks;
    int ec_per_block = ei.ec_per_block;
    int total_ec = total_blocks * ec_per_block;

    // Generate ECC for entire data as one block (PHP behavior)
    auto ecc = rs_generate_ec(data_cw, total_ec);

    // Result: all data codewords followed by all ECC codewords (no interleaving)
    std::vector<uint8_t> result(data_cw);
    result.insert(result.end(), ecc.begin(), ecc.end());
    return result;
}

EncodeResult encode(const char* data, size_t len, ECL ecl) {
    if (len == 0) throw std::invalid_argument("empty data");

    int ecl_idx = static_cast<int>(ecl);
    int version = find_version(len, ecl_idx);
    if (version < 0) throw std::invalid_argument("data too large");

    auto data_cw = build_data_codewords(data, len, version, ecl_idx);
    auto all_cw  = interleave(data_cw, version, ecl_idx);
    // Note: remainder bits are NOT added here. PHP's placeData leaves remainder
    // cells at 0 without masking them. place_data() marks those cells as
    // function_=2 so apply_mask() skips them, matching PHP behavior exactly.

    // Build temp matrix with mask=0 — matches PHP Encoder which builds
    // a temp matrix with mask=0 then evaluates all masks on it
    QRMatrix temp(version);
    place_finder_patterns(temp);
    place_alignment_patterns(temp);
    place_timing_patterns(temp);
    place_dark_module(temp);
    place_version_info(temp);
    place_format_info(temp, ecl_idx, 0);
    place_data(temp, all_cw);
    apply_mask(temp, 0);

    // Evaluate all masks on temp matrix (with mask 0 already applied)
    int best_mask = select_best_mask(temp);
    // temp now has best_mask applied on top of mask_0

    // Build final matrix: data XOR best_mask
    QRMatrix m(version);
    place_finder_patterns(m);
    place_alignment_patterns(m);
    place_timing_patterns(m);
    place_dark_module(m);
    place_version_info(m);
    place_format_info(m, ecl_idx, best_mask);
    place_data(m, all_cw);
    apply_mask(m, best_mask);

    return {std::move(m), version};
}

} // namespace scanme

// C API
extern "C" {

int scanme_qr_encode(
    const char*         data,
    size_t              len,
    int                 ecl,
    scanme_qr_result_t* out
) {
    out->modules = nullptr;
    out->size    = 0;
    out->version = 0;
    try {
        auto ecl_enum = static_cast<scanme::ECL>(ecl);
        auto result   = scanme::encode(data, len, ecl_enum);
        int sz        = result.matrix.size;
        uint8_t* buf  = new uint8_t[static_cast<size_t>(sz * sz)];
        std::memcpy(buf, result.matrix.modules.data(), static_cast<size_t>(sz * sz));
        out->modules = buf;
        out->size    = sz;
        out->version = result.version;
        return 0;
    } catch (...) {
        return -1;
    }
}

void scanme_qr_result_free(scanme_qr_result_t* out) {
    if (out && out->modules) {
        delete[] out->modules;
        out->modules = nullptr;
    }
}

const char* scanme_qr_version(void) {
    return "1.0.0";
}

} // extern "C"
