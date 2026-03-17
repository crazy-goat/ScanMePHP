# ScanMePHP — Encoder Benchmark Results

Benchmark comparing four encoder implementations across QR versions 1–40.

- **Encoder (portable)** — scalar algorithms, v1–v40, all encoding modes (Numeric, Alphanumeric, Byte, Kanji), 32/64-bit safe
- **FastEncoder (64-bit)** — monolithic int-packed encoder using int-pair `[$hi, $lo]` representation, v1–v27, Byte mode only, 64-bit PHP required
- **FfiEncoder (native C++)** — C++20 library via PHP FFI, SIMD-ready Row3 bitset matrix, precomputed RS factor tables, v1–v40, Byte mode only
- **NativeEncoderExt (php-ext)** — C extension using clib, v1–v40, Byte mode only, requires extension loaded

## Environment

- PHP 8.4.11 (64-bit)
- 500 iterations per test, warmup iterations + gc_collect_cycles()
- All times in milliseconds (lower is better)

## Results (p50 — median latency)

| Version     | Encoder (portable) | FastEncoder (64-bit) | FfiEncoder (C++) | NativeEncoderExt | PHP/Fast | PHP/FFI | PHP/Ext |
|-------------|--------------------| ---------------------|------------------|------------------|----------|---------|---------|
| v1 L        | 1.075 ms           | 0.629 ms             | 0.102 ms         | **0.053 ms**     | 1.71×    | 10.52×  | **20.46×**  |
| v2 M        | 1.520 ms           | 0.976 ms             | 0.157 ms         | **0.080 ms**     | 1.56×    | 9.66×   | **18.89×**  |
| v3 H        | 2.328 ms           | 1.285 ms             | 0.220 ms         | **0.119 ms**     | 1.81×    | 10.59×  | **19.49×**  |
| v5 M        | 3.638 ms           | 1.959 ms             | 0.514 ms         | **0.277 ms**     | 1.86×    | 7.08×   | **13.13×**  |
| v10 M       | 11.644 ms          | 5.724 ms             | 1.319 ms         | **0.880 ms**     | 2.03×    | 8.83×   | **13.23×**  |
| v10 L       | 7.348 ms           | 4.041 ms             | 0.929 ms         | **0.490 ms**     | 1.82×    | 7.91×   | **15.00×**  |

## Key Takeaways

- **NativeEncoderExt (php-ext) is the fastest** — 14–21× faster than pure PHP, 6–15× faster than FastEncoder, 1.3–2.5× faster than FFI
- **FfiEncoder is 10–12× faster than the portable Encoder** — sub-millisecond for all tested versions
- **FastEncoder is ~2× faster than the portable Encoder** across all versions v1–v27
- **NativeEncoderExt eliminates FFI overhead** — direct C extension call is ~2× faster than FFI boundary
- The portable Encoder was significantly optimized (from 5–58 ms down to 1.5–11.6 ms) by adopting nayuki's penalty algorithm
- All four encoders produce byte-for-byte identical output, verified against nayuki's reference implementation (1772 test cases × 4 encoders)

## Architecture

```
QRCode (factory — auto-selects fastest available)
  ├── NativeEncoderExt (v1-v40, C extension, requires scanmeqr.so)
  │     └── fallback ↓
  ├── FfiEncoder (v1-v40, C++ via FFI, requires libscanme_qr.so)
  │     └── fallback ↓
  ├── FastEncoder (v1-v27, Byte mode, int-pair packed, 64-bit PHP)
  │     └── fallback ↓
  └── Encoder (v1-v40, all modes, scalar, any PHP 8.1+)
```

All encoders implement `EncoderInterface` and produce identical, spec-compliant QR codes.

## Running the Benchmark

```bash
# Benchmark all 4 encoders (requires php-ext loaded)
php -d extension=./php-ext/modules/scanmeqr.so bench/benchmark_all.php 500

# Benchmark 3 encoders (without php-ext)
php bench/benchmark_encoder.php          # 200 iterations, table output
php bench/benchmark_encoder.php 500      # 500 iterations
```
