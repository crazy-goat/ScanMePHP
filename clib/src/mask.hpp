#pragma once
#include "matrix.hpp"

namespace scanme {

void apply_mask(QRMatrix& m, int mask_id);
int calculate_penalty(const QRMatrix& m, int mask_id);
int select_best_mask(QRMatrix& m);

inline bool mask_condition(int mask_id, int x, int y) noexcept {
    switch (mask_id) {
        case 0: return (x + y) % 2 == 0;
        case 1: return y % 2 == 0;
        case 2: return x % 3 == 0;
        case 3: return (x + y) % 3 == 0;
        case 4: return (y / 2 + x / 3) % 2 == 0;
        case 5: return (x * y) % 2 + (x * y) % 3 == 0;
        case 6: return ((x * y) % 2 + (x * y) % 3) % 2 == 0;
        case 7: return ((x + y) % 2 + (x * y) % 3) % 2 == 0;
        default: return false;
    }
}

inline uint8_t get_masked(const QRMatrix& m, int x, int y, int mask_id) noexcept {
    uint8_t v = m.get(x, y);
    // function_==1: function module (mask applied to all cells in PHP)
    // function_==2: remainder cell (value is 0, mask applied during evaluation)
    // function_==0: data cell (mask applied)
    return mask_condition(mask_id, x, y) ? (v ^ 1) : v;
}

} // namespace scanme
