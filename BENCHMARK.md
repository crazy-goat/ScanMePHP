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
| v1 L        | 5.0 ms             | 0.69 ms              | 0.06 ms          | **7.3×**     | **78×**     |
| v2 M        | 7.3 ms             | 1.00 ms              | 0.09 ms          | **7.3×**     | **78×**     |
| v3 H        | 10.2 ms            | 1.31 ms              | 0.13 ms          | **7.8×**     | **79×**     |
| v5 M        | 17.4 ms            | 2.32 ms              | 0.24 ms          | **7.5×**     | **72×**     |
| v10 M       | 57.5 ms            | 6.61 ms              | 0.75 ms          | **8.7×**     | **77×**     |
| v10 L       | 43.5 ms            | 4.97 ms              | 0.54 ms          | **8.8×**     | **81×**     |

## Key Takeaways

- **FfiEncoder is 70–80× faster than the portable Encoder** — sub-millisecond for all tested versions
- **FastEncoder is 7–9× faster than the portable Encoder** across all versions v1–v27
- **FfiEncoder is 7–10× faster than FastEncoder** — native C++ eliminates PHP interpreter overhead entirely
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
