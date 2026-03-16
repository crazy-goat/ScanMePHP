#pragma once
#include <cstdint>
#include <vector>

namespace scanme {

struct QRMatrix {
    int size;
    int version;
    std::vector<uint8_t> modules;
    std::vector<uint8_t> function_;

    explicit QRMatrix(int version_);

    uint8_t get(int x, int y) const noexcept { return modules[y * size + x]; }
    void set(int x, int y, uint8_t v) noexcept { modules[y * size + x] = v; }
    void set_function(int x, int y, uint8_t v) noexcept {
        modules[y * size + x] = v;
        function_[y * size + x] = 1;
    }
    bool is_function(int x, int y) const noexcept { return function_[y * size + x] != 0; }
};

void place_finder_patterns(QRMatrix& m);
void place_alignment_patterns(QRMatrix& m);
void place_timing_patterns(QRMatrix& m);
void place_dark_module(QRMatrix& m);
void place_format_info(QRMatrix& m, int ecl, int mask);
void place_version_info(QRMatrix& m);
void place_data(QRMatrix& m, const std::vector<uint8_t>& data);

} // namespace scanme
