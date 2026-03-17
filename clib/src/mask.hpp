#pragma once
#include "matrix.hpp"

namespace scanme {

void apply_mask(QRMatrix& m, int mask_id);
int select_best_mask(QRMatrix& m, int ecl, int* penalties_out = nullptr);

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

Row3 build_mask_row(int mask_id, int y, int size, const Row3& func_row);

int calculate_penalty_scalar(const Row3* masked_rows, const Row3* masked_cols, int size, int* rule_out = nullptr);

} // namespace scanme
