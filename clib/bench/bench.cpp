// scanme_bench — micro-benchmark for the C++ QR encoder.
//
//   ./scanme_bench            # full run (per-version latency + stage breakdown)
//   ./scanme_bench 2000       # override iteration count
//   ./scanme_bench 2000 csv   # machine-readable output (label,ecl,version,p50_us,mean_us)
//
// Reports p50 and mean latency of the public scanme_qr_encode_matrix() call for
// a spread of versions, then a per-stage breakdown (codewords, RS+interleave,
// matrix placement, mask selection, final mask) so regressions are attributable.

#include "encoder.hpp"
#include "mask.hpp"
#include "matrix.hpp"
#include "tables.hpp"
#include "scanme_qr.h"

#include <algorithm>
#include <chrono>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>

using clk = std::chrono::steady_clock;

static double us_between(clk::time_point a, clk::time_point b) {
    return std::chrono::duration<double, std::micro>(b - a).count();
}

struct Case {
    const char* label;
    std::string data;
    int ecl; // 0=L 1=M 2=Q 3=H
};

// Pick a payload that lands exactly on `version` for the given ECL (byte mode).
static std::string payload_for(int version, int ecl) {
    int cap = scanme::BYTE_CAPACITY[version - 1][ecl];
    std::string s;
    s.reserve(static_cast<size_t>(cap));
    const char* alphabet = "https://example.com/qr?id=0123456789abcdef";
    size_t alen = std::strlen(alphabet);
    for (int i = 0; i < cap; ++i) s.push_back(alphabet[static_cast<size_t>(i) % alen]);
    return s;
}

static const char* ecl_name(int ecl) {
    static const char* names[] = {"L", "M", "Q", "H"};
    return names[ecl];
}

struct Stats { double p50, mean; };

// Each sample times a batch of BATCH calls to amortise the clock overhead.
static constexpr int BATCH = 8;

template <typename F>
static Stats measure(F&& fn, int iterations) {
    std::vector<double> samples;
    samples.reserve(static_cast<size_t>(iterations));
    for (int i = 0; i < 20; ++i) fn(); // warm-up
    for (int i = 0; i < iterations; ++i) {
        auto t0 = clk::now();
        for (int b = 0; b < BATCH; ++b) fn();
        samples.push_back(us_between(t0, clk::now()) / BATCH);
    }
    std::sort(samples.begin(), samples.end());
    double sum = 0;
    for (double s : samples) sum += s;
    return {samples[samples.size() / 2], sum / static_cast<double>(samples.size())};
}

int main(int argc, char** argv) {
    int iterations = argc > 1 ? std::atoi(argv[1]) : 1000;
    bool csv = argc > 2 && std::strcmp(argv[2], "csv") == 0;

    // SCANME_MASK_KERNEL forces a kernel with no CPU check; report and skip
    // rather than SIGILL when this machine cannot run the requested one.
    if (const char* forced = std::getenv("SCANME_MASK_KERNEL")) {
        if (!scanme::mask_kernel_supported(forced)) {
            if (std::strcmp(forced, "generic") != 0 && std::strcmp(forced, "avx2") != 0 &&
                std::strcmp(forced, "avx512") != 0) {
                std::printf("unknown mask kernel '%s' (expected generic|avx2|avx512)\n", forced);
                return 1;
            }
            std::printf("SKIP: mask kernel '%s' is not available in this build or on this CPU\n", forced);
            return 0;
        }
    }

    std::vector<Case> cases = {
        {"v1",  "https://ex.io",                    0},
        {"v2",  "https://example.com",              1},
        {"v3",  "https://example.com",              3},
        {"v5",  payload_for(5, 1),                  1},
        {"v10", payload_for(10, 1),                 1},
        {"v10", payload_for(10, 0),                 0},
        {"v15", payload_for(15, 2),                 2},
        {"v20", payload_for(20, 1),                 1},
        {"v25", payload_for(25, 0),                 0},
        {"v30", payload_for(30, 3),                 3},
        {"v40", payload_for(40, 0),                 0},
        {"v40", payload_for(40, 3),                 3},
    };

    // Global warm-up so the first case isn't measured while the CPU clocks up.
    {
        auto t0 = clk::now();
        while (us_between(t0, clk::now()) < 100000.0) {
            scanme_qr_matrix_t* m = scanme_qr_encode_matrix(cases[4].data.c_str(), cases[4].data.size(), 1);
            scanme_qr_matrix_free(m);
        }
    }

    if (csv) {
        std::printf("label,ecl,version,p50_us,mean_us\n");
    } else {
        std::printf("scanme_qr C++ encoder benchmark — %d iterations per case (lib %s, mask kernel: %s)\n\n",
                    iterations, scanme_qr_version(), scanme::active_mask_kernel());
        std::printf("%-6s %-4s %-8s %12s %12s\n", "case", "ecl", "version", "p50 (us)", "mean (us)");
        std::printf("%s\n", std::string(46, '-').c_str());
    }

    for (const auto& c : cases) {
        int iters = c.data.size() > 800 ? std::max(50, iterations / 5) : iterations;
        int version = 0;
        Stats st = measure([&] {
            scanme_qr_matrix_t* m = scanme_qr_encode_matrix(c.data.c_str(), c.data.size(), c.ecl);
            version = m->version;
            scanme_qr_matrix_free(m);
        }, iters);
        if (csv)
            std::printf("%s,%s,%d,%.3f,%.3f\n", c.label, ecl_name(c.ecl), version, st.p50, st.mean);
        else
            std::printf("%-6s %-4s %-8d %12.2f %12.2f\n", c.label, ecl_name(c.ecl), version, st.p50, st.mean);
    }

    if (csv) return 0;

    // ---- Stage breakdown -------------------------------------------------
    std::printf("\nStage breakdown (mean us per call)\n");
    std::printf("%-8s %10s %10s %10s %10s %10s\n",
                "case", "codewords", "rs+ilv", "place", "sel_mask", "apply");
    std::printf("%s\n", std::string(64, '-').c_str());

    for (const auto& c : cases) {
        if (std::strcmp(c.label, "v1") && std::strcmp(c.label, "v10") &&
            std::strcmp(c.label, "v25") && std::strcmp(c.label, "v40")) continue;
        int iters = c.data.size() > 800 ? std::max(50, iterations / 5) : iterations;
        double t_cw = 0, t_rs = 0, t_place = 0, t_mask = 0, t_apply = 0;
        for (int it = 0; it < iters; ++it) {
            scanme::EncodeStages s;
            scanme::encode_timed(c.data.c_str(), c.data.size(), static_cast<scanme::ECL>(c.ecl), s);
            t_cw += s.codewords_us; t_rs += s.rs_us; t_place += s.place_us;
            t_mask += s.select_mask_us; t_apply += s.apply_us;
        }
        std::printf("%-5s %-2s %10.2f %10.2f %10.2f %10.2f %10.2f\n",
                    c.label, ecl_name(c.ecl), t_cw / iters, t_rs / iters, t_place / iters,
                    t_mask / iters, t_apply / iters);
    }
    return 0;
}
