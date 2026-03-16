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
    int count = ALIGN_COUNT[m.version - 1];
    const auto& pos = ALIGN_POS[m.version - 1];
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
    // Around top-left finder
    for (int i = 0; i <= 5; ++i) m.set_function(8, i, (fmt >> i) & 1);
    m.set_function(8, 7, (fmt >> 6) & 1);
    m.set_function(8, 8, (fmt >> 7) & 1);
    m.set_function(7, 8, (fmt >> 8) & 1);
    for (int i = 9; i <= 14; ++i) m.set_function(14 - i, 8, (fmt >> i) & 1);
    // Top-right finder
    for (int i = 0; i <= 7; ++i) m.set_function(m.size - 1 - i, 8, (fmt >> i) & 1);
    // Bottom-left finder
    for (int i = 8; i <= 14; ++i) m.set_function(8, m.size - 15 + i, (fmt >> i) & 1);
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
    int idx = 0;
    int total_bits = static_cast<int>(data.size()) * 8;
    // Zigzag from bottom-right, skip column 6 (timing)
    for (int right = m.size - 1; right >= 1; right -= 2) {
        if (right == 6) right = 5;
        for (int vert = m.size - 1; vert >= 0; --vert) {
            for (int j = 0; j < 2; ++j) {
                int x = right - j;
                // Direction alternates per column pair
                int y = ((right + 1) / 2 % 2 == 0) ? vert : (m.size - 1 - vert);
                if (m.is_function(x, y)) continue;
                uint8_t bit = 0;
                if (idx < total_bits) {
                    bit = (data[idx / 8] >> (7 - idx % 8)) & 1;
                    ++idx;
                }
                m.set(x, y, bit);
            }
        }
    }
}

} // namespace scanme
