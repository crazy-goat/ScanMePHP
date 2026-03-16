#pragma once
#include "matrix.hpp"

namespace scanme {

void apply_mask(QRMatrix& m, int mask_id);
int calculate_penalty(const QRMatrix& m);
int select_best_mask(QRMatrix& m);

} // namespace scanme
