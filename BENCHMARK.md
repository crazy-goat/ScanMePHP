# ScanMePHP — Encoder Benchmark Results

Benchmark comparing three encoder implementations across QR versions 1–27.

- **Encoder (portable)** — scalar algorithms, v1–v40, all encoding modes (Numeric, Alphanumeric, Byte, Kanji), 32/64-bit safe
- **FastEncoder (64-bit)** — monolithic int-packed encoder using int-pair `[$hi, $lo]` representation, v1–v27, Byte mode only, 64-bit PHP required
- **FfiEncoder (native C++)** — C++20 library via PHP FFI, SIMD-ready Row3 bitset matrix, precomputed RS factor tables, v1–v27, Byte mode only

## Environment

- PHP 8.4.11 (64-bit)
- 200 iterations per test, warmup iterations
- All times in milliseconds (lower is better)

## Results (p50 — median latency)

| Version     | Encoder (portable) | FastEncoder (64-bit) | FfiEncoder (C++) | Fast/Encoder | FFI/Encoder |
|-------------|--------------------| ---------------------|------------------|--------------|-------------|
| v1 L        | 5.0 ms             | 0.39 ms              | 0.06 ms          | **13.0×**    | **79×**     |
| v2 M        | 7.5 ms             | 0.57 ms              | 0.10 ms          | **13.0×**    | **75×**     |
| v3 H        | 10.5 ms            | 0.69 ms              | 0.16 ms          | **15.2×**    | **67×**     |
| v5 M        | 18.3 ms            | 1.12 ms              | 0.22 ms          | **16.3×**    | **82×**     |
| v10 M       | 58.4 ms            | 3.27 ms              | 0.70 ms          | **17.9×**    | **84×**     |
| v10 L       | 43.4 ms            | 2.58 ms              | 0.54 ms          | **16.9×**    | **80×**     |

## Key Takeaways

- **FfiEncoder is 67–84× faster than the portable Encoder** — sub-millisecond for all tested versions
- **FastEncoder is 13–18× faster than the portable Encoder** across all versions v1–v27
- **FfiEncoder is 3–6× faster than FastEncoder** — native C++ eliminates PHP interpreter overhead entirely
- All three encoders produce byte-for-byte identical output, verified against nayuki's reference implementation (1772 test cases)

## Architecture

```
QRCode (factory — auto-selects fastest available)
  ├── FfiEncoder (v1-v27, C++ via FFI, requires libscanme_qr.so)
  │     └── fallback ↓
  ├── FastEncoder (v1-v27, Byte mode, int-pair packed, 64-bit PHP)
  │     └── fallback ↓
  └── Encoder (v1-v40, all modes, scalar, any PHP 8.1+)
```

All encoders implement `EncoderInterface` and produce identical, spec-compliant QR codes.

## Running the Benchmark

```bash
php examples/benchmark_encoder.php          # 200 iterations, table output
php examples/benchmark_encoder.php 500      # 500 iterations
```
