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
    const auto& ei = EC_TABLE[version-1][ecl];
    int g1b = ei.g1_blocks, g1d = ei.g1_data, ec = ei.ec_per_block;
    int g2b = ei.g2_blocks, g2d = ei.g2_data;
    int total_blocks = g1b + g2b;

    // Split into blocks
    std::vector<std::vector<uint8_t>> data_blocks(total_blocks);
    std::vector<std::vector<uint8_t>> ec_blocks(total_blocks);

    int offset = 0;
    for (int i = 0; i < total_blocks; ++i) {
        int sz = (i < g1b) ? g1d : g2d;
        data_blocks[i].assign(data_cw.begin() + offset, data_cw.begin() + offset + sz);
        ec_blocks[i] = rs_generate_ec(data_blocks[i], ec);
        offset += sz;
    }

    // Interleave data
    std::vector<uint8_t> result;
    int max_data = (g2b > 0) ? g2d : g1d;
    for (int col = 0; col < max_data; ++col)
        for (int blk = 0; blk < total_blocks; ++blk)
            if (col < static_cast<int>(data_blocks[blk].size()))
                result.push_back(data_blocks[blk][col]);

    // Interleave EC
    for (int col = 0; col < ec; ++col)
        for (int blk = 0; blk < total_blocks; ++blk)
            result.push_back(ec_blocks[blk][col]);

    return result;
}

EncodeResult encode(const char* data, size_t len, ECL ecl) {
    if (len == 0) throw std::invalid_argument("empty data");

    int ecl_idx = static_cast<int>(ecl);
    int version = find_version(len, ecl_idx);
    if (version < 0) throw std::invalid_argument("data too large");

    auto data_cw = build_data_codewords(data, len, version, ecl_idx);
    auto all_cw  = interleave(data_cw, version, ecl_idx);

    // Add remainder bits
    int rem = REMAINDER_BITS[version - 1];
    for (int i = 0; i < rem; ++i) all_cw.push_back(0);

    QRMatrix m(version);
    place_finder_patterns(m);
    place_alignment_patterns(m);
    place_timing_patterns(m);
    place_dark_module(m);
    place_version_info(m);

    // Reserve format info area (filled after mask selection)
    place_format_info(m, ecl_idx, 0);

    place_data(m, all_cw);

    int best_mask = select_best_mask(m);

    // Re-place format info with correct mask
    // Undo the mask on format area first (it's function modules, not masked by apply_mask)
    place_format_info(m, ecl_idx, best_mask);

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
