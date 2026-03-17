#include "mask.hpp"
#include "tables.hpp"
#include <array>
#include <climits>
#include <cmath>
#include <cstdlib>
#include <cstring>

namespace scanme {

static constexpr int MASK_Y_PERIOD = 12;

static const auto MASK_TILES = []() {
    std::array<std::array<Row3, MASK_Y_PERIOD>, 8> t{};
    for (int m = 0; m < 8; ++m) {
        for (int y = 0; y < MASK_Y_PERIOD; ++y) {
            Row3 r = Row3::zero();
            for (int x = 0; x < 192; ++x) {
                if (mask_condition(m, x, y))
                    r.w[x >> 6] |= uint64_t(1) << (x & 63);
            }
            t[m][y] = r;
        }
    }
    return t;
}();

Row3 build_mask_row(int mask_id, int y, int size, const Row3& func_row) {
    return MASK_TILES[mask_id][y % MASK_Y_PERIOD] & ~func_row & mask_low_n(size);
}

void apply_mask(QRMatrix& m, int mask_id) {
    for (int y = 0; y < m.size; ++y) {
        Row3 mask_row = build_mask_row(mask_id, y, m.size, m.func[y]);
        m.rows[y] = m.rows[y] ^ mask_row;
    }
    std::memset(m.cols, 0, sizeof(m.cols));
    for (int y = 0; y < m.size; ++y) {
        for (int word = 0; word < 3; ++word) {
            uint64_t bits = m.rows[y].w[word];
            while (bits) {
                int bit = __builtin_ctzll(bits);
                int x = word * 64 + bit;
                if (x < m.size) {
                    m.cols[x].w[y >> 6] |= uint64_t(1) << (y & 63);
                }
                bits &= bits - 1;
            }
        }
    }
}

static void build_format_bitmaps(int ecl, int mask_id, int size,
                                  Row3* format_mask, Row3* format_dark) {
    for (int y = 0; y < size; ++y) {
        format_mask[y] = Row3::zero();
        format_dark[y] = Row3::zero();
    }

    uint16_t fmt = FORMAT_INFO[ecl * 8 + mask_id];
    int n = size;

    auto set_fmt = [&](int x, int y, bool dark) {
        format_mask[y].w[x >> 6] |= uint64_t(1) << (x & 63);
        if (dark) {
            format_dark[y].w[x >> 6] |= uint64_t(1) << (x & 63);
        }
    };

    set_fmt(8, 0, (fmt >> 0) & 1);
    set_fmt(8, 1, (fmt >> 1) & 1);
    set_fmt(8, 2, (fmt >> 2) & 1);
    set_fmt(8, 3, (fmt >> 3) & 1);
    set_fmt(8, 4, (fmt >> 4) & 1);
    set_fmt(8, 5, (fmt >> 5) & 1);
    set_fmt(8, 7, (fmt >> 6) & 1);
    set_fmt(8, 8, (fmt >> 7) & 1);

    set_fmt(7, 8, (fmt >> 8) & 1);
    set_fmt(5, 8, (fmt >> 9) & 1);
    set_fmt(4, 8, (fmt >> 10) & 1);
    set_fmt(3, 8, (fmt >> 11) & 1);
    set_fmt(2, 8, (fmt >> 12) & 1);
    set_fmt(1, 8, (fmt >> 13) & 1);
    set_fmt(0, 8, (fmt >> 14) & 1);

    for (int i = 0; i < 8; ++i)
        set_fmt(n - 1 - i, 8, (fmt >> i) & 1);

    for (int i = 8; i < 15; ++i)
        set_fmt(8, n - 15 + i, (fmt >> i) & 1);
}

static void finderPenaltyAddHistory(int runLen, std::array<int,7>& hist, int size) {
    if (hist[0] == 0)
        runLen += size;
    std::copy_backward(hist.begin(), hist.end() - 1, hist.end());
    hist[0] = runLen;
}

static int finderPenaltyCountPatterns(const std::array<int,7>& hist) {
    int n = hist[1];
    if (n <= 0) return 0;
    bool core = hist[2] == n && hist[3] == n * 3 && hist[4] == n && hist[5] == n;
    return (core && hist[0] >= n * 4 && hist[6] >= n ? 1 : 0)
         + (core && hist[6] >= n * 4 && hist[0] >= n ? 1 : 0);
}

static int finderPenaltyTerminateAndCount(bool curColor, int curLen, std::array<int,7>& hist, int size) {
    if (curColor) {
        finderPenaltyAddHistory(curLen, hist, size);
        curLen = 0;
    }
    curLen += size;
    finderPenaltyAddHistory(curLen, hist, size);
    return finderPenaltyCountPatterns(hist);
}

int calculate_penalty_scalar(const Row3* masked_rows, const Row3* /*masked_cols*/, int size, int* rule_out) {
    int result = 0;
    int dark_count = 0;
    int r1_pen = 0, r2_pen = 0, r3_pen = 0, r4_pen = 0;

    auto getModule = [&](int x, int y) -> bool {
        return (masked_rows[y].w[x >> 6] >> (x & 63)) & 1;
    };

    for (int y = 0; y < size; ++y) {
        bool runColor = false;
        int runX = 0;
        std::array<int,7> runHistory = {};
        for (int x = 0; x < size; ++x) {
            if (getModule(x, y) == runColor) {
                runX++;
                if (runX == 5)
                    r1_pen += 3;
                else if (runX > 5)
                    r1_pen++;
            } else {
                finderPenaltyAddHistory(runX, runHistory, size);
                if (!runColor)
                    r3_pen += finderPenaltyCountPatterns(runHistory) * 40;
                runColor = getModule(x, y);
                runX = 1;
            }
        }
        r3_pen += finderPenaltyTerminateAndCount(runColor, runX, runHistory, size) * 40;
    }

    for (int x = 0; x < size; ++x) {
        bool runColor = false;
        int runY = 0;
        std::array<int,7> runHistory = {};
        for (int y = 0; y < size; ++y) {
            if (getModule(x, y) == runColor) {
                runY++;
                if (runY == 5)
                    r1_pen += 3;
                else if (runY > 5)
                    r1_pen++;
            } else {
                finderPenaltyAddHistory(runY, runHistory, size);
                if (!runColor)
                    r3_pen += finderPenaltyCountPatterns(runHistory) * 40;
                runColor = getModule(x, y);
                runY = 1;
            }
        }
        r3_pen += finderPenaltyTerminateAndCount(runColor, runY, runHistory, size) * 40;
    }
    result += r1_pen;
    result += r3_pen;

    {
        Row3 valid_r2 = mask_low_n(size - 1);
        for (int y = 0; y < size - 1; ++y) {
            Row3 cur  = masked_rows[y];
            Row3 next = masked_rows[y + 1];
            Row3 cur_s  = shr1(cur);
            Row3 next_s = shr1(next);
            Row3 all_dark  = cur & cur_s & next & next_s & valid_r2;
            Row3 all_light = ~cur & ~cur_s & ~next & ~next_s & valid_r2;
            r2_pen += (popcnt(all_dark) + popcnt(all_light)) * 3;
        }
    }
    result += r2_pen;

    for (int y = 0; y < size; ++y) {
        Row3 valid = mask_low_n(size);
        Row3 row = masked_rows[y] & valid;
        dark_count += popcnt(row);
    }
    int total = size * size;
    int k = static_cast<int>((std::abs(dark_count * 20L - total * 10L) + total - 1) / total) - 1;
    r4_pen = k * 10;
    result += r4_pen;

    if (rule_out) {
        rule_out[0] = r1_pen;
        rule_out[1] = r2_pen;
        rule_out[2] = r3_pen;
        rule_out[3] = r4_pen;
        rule_out[4] = dark_count;
    }

    return result;
}

static void rows_to_cols(const Row3* rows, Row3* cols, int size) {
    std::memset(cols, 0, sizeof(Row3) * MAX_QR_SIZE);
    for (int y = 0; y < size; ++y) {
        for (int word = 0; word < 3; ++word) {
            uint64_t bits = rows[y].w[word];
            while (bits) {
                int bit = __builtin_ctzll(bits);
                int x = word * 64 + bit;
                if (x < size) {
                    cols[x].w[y >> 6] |= uint64_t(1) << (y & 63);
                }
                bits &= bits - 1;
            }
        }
    }
}

int select_best_mask(QRMatrix& m, int ecl, int* penalties_out) {
    int best_mask = 0;
    int best_penalty = INT_MAX;

    static thread_local Row3 masked_rows[MAX_QR_SIZE];
    static thread_local Row3 masked_cols[MAX_QR_SIZE];
    static thread_local Row3 cur_fmt_mask[MAX_QR_SIZE];
    static thread_local Row3 cur_fmt_dark[MAX_QR_SIZE];

    Row3 valid = mask_low_n(m.size);

    for (int mask_id = 0; mask_id < 8; ++mask_id) {
        build_format_bitmaps(ecl, mask_id, m.size, cur_fmt_mask, cur_fmt_dark);

        for (int y = 0; y < m.size; ++y) {
            Row3 pattern = MASK_TILES[mask_id][y % MASK_Y_PERIOD] & ~m.func[y] & valid;
            Row3 row = m.rows[y] ^ pattern;
            masked_rows[y] = andnot(cur_fmt_mask[y], row) | cur_fmt_dark[y];
        }

        rows_to_cols(masked_rows, masked_cols, m.size);

        int rule_vals[5] = {};
        int p = calculate_penalty_scalar(masked_rows, masked_cols, m.size, rule_vals);
        if (penalties_out) penalties_out[mask_id] = p;
        if (p < best_penalty) {
            best_penalty = p;
            best_mask = mask_id;
        }
    }

    return best_mask;
}

} // namespace scanme
