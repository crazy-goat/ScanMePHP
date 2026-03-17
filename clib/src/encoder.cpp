#include "encoder.hpp"
#include "reed_solomon.hpp"
#include "matrix.hpp"
#include "mask.hpp"
#include "tables.hpp"
#include "../include/scanme_qr.h"
#include <cstring>
#include <stdexcept>
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

// Direct byte packing — eliminates intermediate bit array.
// Packs mode indicator, character count, data bytes, terminator, and padding
// directly into codeword bytes.
static int build_data_codewords(
    const char* data, size_t len, int version, int /*ecl*/,
    uint8_t* codewords, int capacity)
{
    int cc_bits = (version <= 9) ? 8 : 16;

    if (cc_bits == 8) {
        // Mode 0100 (4 bits) + 8-bit count + data bytes
        // First byte: 0100 xxxx where xxxx = high 4 bits of len
        // But len fits in 8 bits, so: 0100 LLLL where LLLL = len >> 4... wait
        // Mode = 0100 (4 bits), count = len (8 bits) = 12 bits total header
        // Byte 0: 0100 HHHH where HHHH = high 4 bits of count
        // Byte 1 high nibble: LLLL (low 4 bits of count), then data starts
        //
        // 0100 cccc cccc dddd dddd dddd ...
        // byte[0] = 0100 cccc = 0x40 | (len >> 4)
        // byte[1] = cccc dddd = ((len & 0x0F) << 4) | (data[0] >> 4)
        // byte[i+2] = dddd dddd = ((data[i] & 0x0F) << 4) | (data[i+1] >> 4)  for i < len-1
        // byte[len+1] = dddd 0000 = (data[len-1] & 0x0F) << 4  (+ terminator zeros)

        int idx = 0;
        codewords[idx++] = static_cast<uint8_t>(0x40 | (len >> 4));

        uint8_t prev4 = static_cast<uint8_t>((len & 0x0F) << 4);
        for (size_t i = 0; i < len; ++i) {
            uint8_t b = static_cast<uint8_t>(data[i]);
            codewords[idx++] = static_cast<uint8_t>(prev4 | (b >> 4));
            prev4 = static_cast<uint8_t>((b & 0x0F) << 4);
        }
        // The remaining 4 bits of prev4 + terminator (up to 4 zero bits) + padding to byte boundary
        // Since we're at a 4-bit offset, the terminator zeros fill the rest of this byte
        codewords[idx++] = prev4; // low nibble is 0 (terminator + padding)

        // Pad with 0xEC, 0x11 alternating
        int pad_idx = 0;
        while (idx < capacity) {
            codewords[idx++] = (pad_idx++ % 2 == 0) ? 0xEC : 0x11;
        }
        return capacity;
    } else {
        // cc_bits == 16: mode (4 bits) + count (16 bits) = 20 bits header
        // 0100 cccc cccc cccc cccc dddd dddd ...
        // byte[0] = 0100 HHHH = 0x40 | (len >> 12)
        // byte[1] = next 8 bits of count = (len >> 4) & 0xFF
        // byte[2] = LLLL dddd = ((len & 0x0F) << 4) | (data[0] >> 4)
        // byte[i+3] = dddd dddd = ((data[i] & 0x0F) << 4) | (data[i+1] >> 4)
        // ...

        int idx = 0;
        codewords[idx++] = static_cast<uint8_t>(0x40 | (len >> 12));
        codewords[idx++] = static_cast<uint8_t>((len >> 4) & 0xFF);

        uint8_t prev4 = static_cast<uint8_t>((len & 0x0F) << 4);
        for (size_t i = 0; i < len; ++i) {
            uint8_t b = static_cast<uint8_t>(data[i]);
            codewords[idx++] = static_cast<uint8_t>(prev4 | (b >> 4));
            prev4 = static_cast<uint8_t>((b & 0x0F) << 4);
        }
        codewords[idx++] = prev4;

        int pad_idx = 0;
        while (idx < capacity) {
            codewords[idx++] = (pad_idx++ % 2 == 0) ? 0xEC : 0x11;
        }
        return capacity;
    }
}

static int interleave(
    const uint8_t* data_cw, int data_len, int version, int ecl,
    uint8_t* output)
{
    (void)data_len;
    const auto& ei = EC_TABLE[version-1][ecl];
    int num_short = ei.g1_blocks;
    int num_long  = ei.g2_blocks;
    int num_blocks = num_short + num_long;
    int ec_len = ei.ec_per_block;
    int short_data = ei.g1_data;
    int long_data  = (num_long > 0) ? ei.g2_data : short_data;

    uint8_t block_data[255][256];
    uint8_t block_ecc[255][256];

    int k = 0;
    for (int i = 0; i < num_blocks; ++i) {
        int dlen = (i < num_short) ? short_data : long_data;
        std::memcpy(block_data[i], data_cw + k, static_cast<size_t>(dlen));
        k += dlen;

        std::span<const uint8_t> d(block_data[i], static_cast<size_t>(dlen));
        std::span<uint8_t> e(block_ecc[i], static_cast<size_t>(ec_len));
        rs_generate_ec(d, e);
    }

    int idx = 0;
    for (int col = 0; col < long_data; ++col) {
        for (int b = 0; b < num_blocks; ++b) {
            int dlen = (b < num_short) ? short_data : long_data;
            if (col < dlen)
                output[idx++] = block_data[b][col];
        }
    }
    for (int col = 0; col < ec_len; ++col) {
        for (int b = 0; b < num_blocks; ++b) {
            output[idx++] = block_ecc[b][col];
        }
    }

    return idx;
}

EncodeResult encode(const char* data, size_t len, ECL ecl) {
    if (len == 0) throw std::invalid_argument("empty data");

    int ecl_idx = static_cast<int>(ecl);
    int version = find_version(len, ecl_idx);
    if (version < 0) throw std::invalid_argument("data too large");

    int capacity = total_data_codewords(version, ecl_idx);

    // Stack buffers — max v40 data ~3000 bytes + ~1200 ECC = ~4200 bytes
    uint8_t data_cw[4096];
    uint8_t all_cw[8192];

    build_data_codewords(data, len, version, ecl_idx, data_cw, capacity);
    int total_len = interleave(data_cw, capacity, version, ecl_idx, all_cw);

    // Build matrix ONCE: place all function patterns and data, then select best mask
    QRMatrix m(version);
    place_finder_patterns(m);
    place_alignment_patterns(m);
    place_timing_patterns(m);
    place_dark_module(m);
    place_version_info(m);
    reserve_format_info(m);
    place_data(m, all_cw, total_len);

    int best_mask = select_best_mask(m, ecl_idx);

    place_format_info(m, ecl_idx, best_mask);
    apply_mask(m, best_mask);

    return {std::move(m), version};
}

EncodeResult encode_forced_mask(const char* data, size_t len, ECL ecl, int forced_mask) {
    if (len == 0) throw std::invalid_argument("empty data");
    int ecl_idx = static_cast<int>(ecl);
    int version = find_version(len, ecl_idx);
    if (version < 0) throw std::invalid_argument("data too large");

    int capacity = total_data_codewords(version, ecl_idx);
    uint8_t data_cw[4096];
    uint8_t all_cw[8192];

    build_data_codewords(data, len, version, ecl_idx, data_cw, capacity);
    int total_len = interleave(data_cw, capacity, version, ecl_idx, all_cw);

    QRMatrix m(version);
    place_finder_patterns(m);
    place_alignment_patterns(m);
    place_timing_patterns(m);
    place_dark_module(m);
    place_version_info(m);
    reserve_format_info(m);
    place_data(m, all_cw, total_len);

    place_format_info(m, ecl_idx, forced_mask);
    apply_mask(m, forced_mask);

    return {std::move(m), version};
}

EncodeResult encode_for_debug(const char* data, size_t len, ECL ecl, int penalties_out[8]) {
    int ecl_idx = static_cast<int>(ecl);
    int version = find_version(len, ecl_idx);
    if (version < 0) throw std::runtime_error("data too large");

    int capacity = total_data_codewords(version, ecl_idx);
    uint8_t data_cw[4096];
    uint8_t all_cw[8192];

    build_data_codewords(data, len, version, ecl_idx, data_cw, capacity);
    int total_len = interleave(data_cw, capacity, version, ecl_idx, all_cw);

    QRMatrix m(version);
    place_finder_patterns(m);
    place_alignment_patterns(m);
    place_timing_patterns(m);
    place_dark_module(m);
    place_version_info(m);
    reserve_format_info(m);
    place_data(m, all_cw, total_len);

    int best_mask = select_best_mask(m, ecl_idx, penalties_out);

    place_format_info(m, ecl_idx, best_mask);
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

        // Extract bits from Row3 rows to flat uint8_t array
        for (int y = 0; y < sz; ++y) {
            const auto& row = result.matrix.rows[y];
            uint8_t* dst = buf + y * sz;
            for (int x = 0; x < sz; ++x) {
                dst[x] = static_cast<uint8_t>((row.w[x >> 6] >> (x & 63)) & 1);
            }
        }

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

int scanme_qr_debug_penalties(
    const char* data,
    size_t      len,
    int         ecl,
    int         penalties_out[8]
) {
    try {
        auto ecl_enum = static_cast<scanme::ECL>(ecl);
        auto result   = scanme::encode_for_debug(data, len, ecl_enum, penalties_out);
        return result.version;
    } catch (...) {
        return -1;
    }
}

} // extern "C"
