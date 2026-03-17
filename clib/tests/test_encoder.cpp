#include <cassert>
#include <cstring>
#include <cstdio>
#include <cstdlib>
#include "../include/scanme_qr.h"

static int failures = 0;

#define ASSERT(cond, msg) do { \
    if (!(cond)) { \
        fprintf(stderr, "FAIL: %s (line %d): %s\n", __func__, __LINE__, msg); \
        ++failures; \
    } else { \
        printf("  OK: %s\n", msg); \
    } \
} while(0)

void test_version_string() {
    printf("[test_version_string]\n");
    const char* v = scanme_qr_version();
    ASSERT(v != nullptr, "version not null");
    ASSERT(strlen(v) > 0, "version not empty");
    printf("  version = %s\n", v);
}

void test_encode_basic() {
    printf("[test_encode_basic]\n");
    scanme_qr_result_t out{};
    int ret = scanme_qr_encode("https://example.com", 19, 1 /*M*/, &out);
    ASSERT(ret == 0, "encode returns 0");
    ASSERT(out.modules != nullptr, "modules not null");
    ASSERT(out.size > 0, "size > 0");
    ASSERT(out.size % 4 == 1, "size = 17+4*version");
    ASSERT(out.version >= 1 && out.version <= 40, "version in range");
    printf("  size=%d version=%d\n", out.size, out.version);
    scanme_qr_result_free(&out);
    ASSERT(out.modules == nullptr, "modules null after free");
}

void test_encode_empty_fails() {
    printf("[test_encode_empty_fails]\n");
    scanme_qr_result_t out{};
    int ret = scanme_qr_encode("", 0, 1, &out);
    ASSERT(ret == -1, "empty data returns -1");
    ASSERT(out.modules == nullptr, "modules null on error");
}

void test_encode_all_ecl() {
    printf("[test_encode_all_ecl]\n");
    const char* data = "https://scanmephp.example.com/test";
    for (int ecl = 0; ecl <= 3; ++ecl) {
        scanme_qr_result_t out{};
        int ret = scanme_qr_encode(data, strlen(data), ecl, &out);
        ASSERT(ret == 0, "encode succeeds for each ECL");
        ASSERT(out.modules != nullptr, "modules not null");
        // Higher ECL = larger QR (same data)
        printf("  ECL=%d size=%d version=%d\n", ecl, out.size, out.version);
        scanme_qr_result_free(&out);
    }
}

void test_encode_max_v40() {
    printf("[test_encode_max_v40]\n");
    // Max payload for ECL=L byte mode = 2953 bytes
    char* big = static_cast<char*>(malloc(2953));
    memset(big, 'A', 2953);
    scanme_qr_result_t out{};
    int ret = scanme_qr_encode(big, 2953, 0 /*L*/, &out);
    ASSERT(ret == 0, "max v40 L encodes");
    ASSERT(out.version == 40, "version is 40");
    ASSERT(out.size == 177, "size is 177");
    printf("  v40 size=%d modules=%d\n", out.size, out.size * out.size);
    scanme_qr_result_free(&out);
    free(big);
}

void test_encode_too_large_fails() {
    printf("[test_encode_too_large_fails]\n");
    char* big = static_cast<char*>(malloc(2954));
    memset(big, 'A', 2954);
    scanme_qr_result_t out{};
    int ret = scanme_qr_encode(big, 2954, 0 /*L*/, &out);
    ASSERT(ret == -1, "too large returns -1");
    ASSERT(out.modules == nullptr, "modules null on error");
    free(big);
}

void test_modules_values() {
    printf("[test_modules_values]\n");
    scanme_qr_result_t out{};
    scanme_qr_encode("https://example.com", 19, 1, &out);
    int total = out.size * out.size;
    bool all_01 = true;
    for (int i = 0; i < total; ++i) {
        if (out.modules[i] != 0 && out.modules[i] != 1) { all_01 = false; break; }
    }
    ASSERT(all_01, "all modules are 0 or 1");
    scanme_qr_result_free(&out);
}

void test_free_null_safe() {
    printf("[test_free_null_safe]\n");
    scanme_qr_result_t out{};
    out.modules = nullptr;
    scanme_qr_result_free(&out);
    ASSERT(true, "free null is safe");
    scanme_qr_result_free(nullptr);
    ASSERT(true, "free nullptr is safe");
}

void test_deterministic() {
    printf("[test_deterministic]\n");
    const char* data = "https://example.com";
    size_t len = strlen(data);
    scanme_qr_result_t a{}, b{};
    scanme_qr_encode(data, len, 1, &a);
    scanme_qr_encode(data, len, 1, &b);
    ASSERT(a.size == b.size, "same size");
    ASSERT(a.version == b.version, "same version");
    bool same = (memcmp(a.modules, b.modules, static_cast<size_t>(a.size * a.size)) == 0);
    ASSERT(same, "deterministic output");
    scanme_qr_result_free(&a);
    scanme_qr_result_free(&b);
}

int main() {
    printf("=== scanme_qr C++ tests ===\n");
    test_version_string();
    test_encode_basic();
    test_encode_empty_fails();
    test_encode_all_ecl();
    test_encode_max_v40();
    test_encode_too_large_fails();
    test_modules_values();
    test_free_null_safe();
    test_deterministic();
    printf("\n=== %s (%d failures) ===\n",
           failures == 0 ? "ALL PASSED" : "FAILURES", failures);
    return failures > 0 ? 1 : 0;
}
