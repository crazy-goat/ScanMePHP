#include <cstdio>
#include <cstring>
#include <fstream>
#include <sstream>
#include <string>
#include "../src/encoder.hpp"
#include "../src/matrix.hpp"

static bool parse_csv_line(const std::string& line, std::string& url, int& ecl, int& version, int& size, std::string& bits) {
    size_t pos = 0;
    if (line.empty()) return false;

    if (line[0] == '"') {
        pos = 1;
        url.clear();
        while (pos < line.size()) {
            if (line[pos] == '"') {
                if (pos + 1 < line.size() && line[pos + 1] == '"') {
                    url += '"';
                    pos += 2;
                } else {
                    pos++;
                    break;
                }
            } else {
                url += line[pos++];
            }
        }
        if (pos < line.size() && line[pos] == ',') pos++;
    } else {
        auto comma = line.find(',');
        if (comma == std::string::npos) return false;
        url = line.substr(0, comma);
        pos = comma + 1;
    }

    char ecl_ch = line[pos];
    if (ecl_ch == 'L') ecl = 0;
    else if (ecl_ch == 'M') ecl = 1;
    else if (ecl_ch == 'Q') ecl = 2;
    else if (ecl_ch == 'H') ecl = 3;
    else return false;
    pos += 2;

    auto comma1 = line.find(',', pos);
    version = std::stoi(line.substr(pos, comma1 - pos));
    pos = comma1 + 1;

    auto comma2 = line.find(',', pos);
    size = std::stoi(line.substr(pos, comma2 - pos));
    pos = comma2 + 1;

    bits = line.substr(pos);
    return true;
}

// The C++ core against the PHP encoder's output, frozen.
//
// Not a reference fixture: tests/fixtures/qr_reference.csv is that one, and it
// comes from Nayuki's qrcodegen with the mask pinned, which is what says the
// PHP encoder is right. This test compares against tests/fixtures/
// qr_agreement.csv, which holds that encoder's own output — because the thing
// worth checking about this core is not that it independently arrives at some
// mask, but that it reaches the same symbol, mask included. The mask is not
// something either this core or the bitset fast path can be told, so it cannot
// be compared against the reference directly.
//
// QrAgreementFixtureTest keeps the file honest; the PHP backends skip it and
// compare against the encoder in memory.
int main(int argc, char* argv[]) {
    const char* csv_path = argc > 1 ? argv[1] : "../../tests/fixtures/qr_agreement.csv";
    std::ifstream fin(csv_path);
    if (!fin) {
        std::fprintf(stderr, "Cannot open %s\n", csv_path);
        return 1;
    }

    std::string header;
    std::getline(fin, header);

    const scanme::ECL ecls[] = {scanme::ECL::L, scanme::ECL::M, scanme::ECL::Q, scanme::ECL::H};
    const char* ecl_names[] = {"L", "M", "Q", "H"};

    int total = 0, failures = 0;
    std::string line;
    while (std::getline(fin, line)) {
        std::string url, bits;
        int ecl, version, size;
        if (!parse_csv_line(line, url, ecl, version, size, bits)) continue;

        auto result = scanme::encode(url.c_str(), url.size(), ecls[ecl]);

        if (result.version != version) {
            std::printf("FAIL version: url=%.40s ecl=%s expected=%d got=%d\n",
                        url.c_str(), ecl_names[ecl], version, result.version);
            failures++;
            total++;
            continue;
        }

        int diffs = 0;
        for (int y = 0; y < size; y++) {
            for (int x = 0; x < size; x++) {
                bool ours = (result.matrix.rows[y].w[x >> 6] >> (x & 63)) & 1;
                bool expected = bits[static_cast<size_t>(y * size + x)] == '1';
                if (ours != expected) diffs++;
            }
        }

        if (diffs > 0) {
            std::printf("FAIL url=%.60s ecl=%s v%d: %d diffs\n",
                        url.c_str(), ecl_names[ecl], version, diffs);
            failures++;
        }
        total++;
    }

    std::printf("\n%d tests, %d failures\n", total, failures);
    std::printf("%s\n", failures == 0 ? "ALL PASSED" : "SOME FAILED");
    return failures > 0 ? 1 : 0;
}
