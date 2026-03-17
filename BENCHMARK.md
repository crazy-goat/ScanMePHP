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
| v1 L        | 0.72 ms            | 0.38 ms              | 0.07 ms          | **1.9×**     | **10×**     |
| v2 M        | 1.03 ms            | 0.52 ms              | 0.10 ms          | **2.0×**     | **10×**     |
| v3 H        | 1.50 ms            | 0.74 ms              | 0.13 ms          | **2.0×**     | **12×**     |
| v5 M        | 2.48 ms            | 1.18 ms              | 0.25 ms          | **2.1×**     | **10×**     |
| v10 M       | 7.71 ms            | 3.35 ms              | 0.75 ms          | **2.3×**     | **10×**     |
| v10 L       | 5.77 ms            | 2.51 ms              | 0.57 ms          | **2.3×**     | **10×**     |

## Key Takeaways

- **FfiEncoder is 10–12× faster than the portable Encoder** — sub-millisecond for all tested versions
- **FastEncoder is ~2× faster than the portable Encoder** across all versions v1–v27
- **FfiEncoder is 4–5× faster than FastEncoder** — native C++ eliminates PHP interpreter overhead entirely
- The portable Encoder was significantly optimized (from 5–58 ms down to 0.7–7.7 ms) by adopting nayuki's penalty algorithm
- All three encoders produce byte-for-byte identical output, verified against nayuki's reference implementation (1772 test cases × 3 encoders)

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
php bench/benchmark_encoder.php          # 200 iterations, table output
php bench/benchmark_encoder.php 500      # 500 iterations
```
