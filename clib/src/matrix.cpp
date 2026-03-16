#include "matrix.hpp"
#include "tables.hpp"
#include <cstdlib>

namespace scanme {

QRMatrix::QRMatrix(int version_)
    : size(17 + version_ * 4)
    , version(version_)
    , modules(static_cast<size_t>(size * size), 0)
    , function_(static_cast<size_t>(size * size), 0)
{}

static void place_finder(QRMatrix& m, int tx, int ty) {
    for (int dy = -1; dy <= 7; ++dy) {
        for (int dx = -1; dx <= 7; ++dx) {
            int x = tx + dx, y = ty + dy;
            if (x < 0 || x >= m.size || y < 0 || y >= m.size) continue;
            uint8_t v = 0;
            if (dx >= 0 && dx <= 6 && dy >= 0 && dy <= 6) {
                if (dx == 0 || dx == 6 || dy == 0 || dy == 6) v = 1;
                else if (dx >= 2 && dx <= 4 && dy >= 2 && dy <= 4) v = 1;
            }
            m.set_function(x, y, v);
        }
    }
}

void place_finder_patterns(QRMatrix& m) {
    place_finder(m, 0, 0);
    place_finder(m, m.size - 7, 0);
    place_finder(m, 0, m.size - 7);
}

void place_alignment_patterns(QRMatrix& m) {
    if (m.version < 2) return;
    // PHP uses ALIGNMENT_POSITIONS[$version] (direct index, 0-based array),
    // so version 2 gets index 2 = [6,18] (v3's positions). Match this off-by-one.
    int count = ALIGN_COUNT[m.version];
    const auto& pos = ALIGN_POS[m.version];
    for (int i = 0; i < count; ++i) {
        for (int j = 0; j < count; ++j) {
            int cx = pos[j], cy = pos[i];
            // Skip if overlaps finder patterns
            if ((cx <= 8 && cy <= 8) ||
                (cx >= m.size - 8 && cy <= 8) ||
                (cx <= 8 && cy >= m.size - 8)) continue;
            for (int dy = -2; dy <= 2; ++dy) {
                for (int dx = -2; dx <= 2; ++dx) {
                    uint8_t v = (std::abs(dx) == 2 || std::abs(dy) == 2 || (dx == 0 && dy == 0)) ? 1 : 0;
                    m.set_function(cx + dx, cy + dy, v);
                }
            }
        }
    }
}

void place_timing_patterns(QRMatrix& m) {
    for (int i = 8; i < m.size - 8; ++i) {
        uint8_t v = (i % 2 == 0) ? 1 : 0;
        m.set_function(i, 6, v);
        m.set_function(6, i, v);
    }
}

void place_dark_module(QRMatrix& m) {
    m.set_function(8, 4 * m.version + 9, 1);
}

void place_format_info(QRMatrix& m, int ecl, int mask) {
    uint16_t fmt = FORMAT_INFO[ecl * 8 + mask];
    int n = m.size;

    // Top-left: column x=8, rows 0-5,7,8 then row y=8, cols 7,5,4,3,2,1,0
    // Matches PHP MatrixBuilder::addFormatInfo exactly
    m.set_function(8, 0, (fmt >> 0) & 1);
    m.set_function(8, 1, (fmt >> 1) & 1);
    m.set_function(8, 2, (fmt >> 2) & 1);
    m.set_function(8, 3, (fmt >> 3) & 1);
    m.set_function(8, 4, (fmt >> 4) & 1);
    m.set_function(8, 5, (fmt >> 5) & 1);
    m.set_function(8, 7, (fmt >> 6) & 1);
    m.set_function(8, 8, (fmt >> 7) & 1);
    m.set_function(7, 8, (fmt >> 8) & 1);
    m.set_function(5, 8, (fmt >> 9) & 1);
    m.set_function(4, 8, (fmt >> 10) & 1);
    m.set_function(3, 8, (fmt >> 11) & 1);
    m.set_function(2, 8, (fmt >> 12) & 1);
    m.set_function(1, 8, (fmt >> 13) & 1);
    m.set_function(0, 8, (fmt >> 14) & 1);

    // Top-right: row y=8, cols n-1 down to n-8
    for (int i = 0; i < 8; ++i)
        m.set_function(n - 1 - i, 8, (fmt >> i) & 1);

    // Bottom-left: col x=8, rows n-7 up to n-1
    for (int i = 8; i < 15; ++i)
        m.set_function(8, n - 15 + i, (fmt >> i) & 1);
}

void place_version_info(QRMatrix& m) {
    if (m.version < 7) return;
    uint32_t ver = VERSION_INFO[m.version - 7];
    for (int i = 0; i < 18; ++i) {
        uint8_t v = (ver >> i) & 1;
        int r = i / 3, c = i % 3;
        m.set_function(c, m.size - 11 + r, v);
        m.set_function(m.size - 11 + r, c, v);
    }
}

void place_data(QRMatrix& m, const std::vector<uint8_t>& data) {
    int bit_idx = 0;
    int total_bits = static_cast<int>(data.size()) * 8;
    int n = m.size;

    // Zigzag placement — matches PHP MatrixBuilder::placeData exactly.
    // PHP only writes cells where byteIndex < count(codewords); cells beyond
    // that (remainder bit positions) are left at 0 and NOT masked.
    // We mark remainder cells as function so apply_mask skips them.
    for (int col = n - 1; col > 0; col -= 2) {
        if (col == 6) col--;  // skip timing column

        // PHP: $up = (int)(($size - 1 - $col) / 2) % 2 === 0
        bool up = ((n - 1 - col) / 2) % 2 == 0;

        for (int row_step = 0; row_step < n; ++row_step) {
            int row = up ? (n - 1 - row_step) : row_step;
            for (int c = 0; c < 2; ++c) {
                int x = col - c;
                int y = row;
                if (m.is_function(x, y)) continue;
                if (bit_idx < total_bits) {
                    uint8_t bit = (data[bit_idx / 8] >> (7 - bit_idx % 8)) & 1;
                    m.set(x, y, bit);
                    ++bit_idx;
                } else {
                    // Remainder cell: leave at 0 and mark as function so mask is not applied
                    m.set(x, y, 0);
                    m.function_[y * n + x] = 2;  // 2 = remainder (skip masking)
                }
            }
        }
    }
}

} // namespace scanme
