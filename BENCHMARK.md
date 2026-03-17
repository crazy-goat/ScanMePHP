# ScanMePHP — Encoder Benchmark Results

Benchmark comparing three encoder implementations across QR versions 1–27.

- **Encoder (portable)** — scalar algorithms, v1–v40, all encoding modes (Numeric, Alphanumeric, Byte, Kanji), 32/64-bit safe
- **FastEncoder (64-bit)** — monolithic int-packed encoder using int-pair `[$hi, $lo]` representation, v1–v27, Byte mode only, 64-bit PHP required
- **FfiEncoder (native C++)** — C++20 library via PHP FFI, SIMD-ready Row3 bitset matrix, precomputed RS factor tables, v1–v27, Byte mode only

## Environment

- PHP 8.4.11 (64-bit)
- 200 iterations per test (scaled down for v17+ due to Encoder latency), warmup iterations
- Error Correction Level: Medium (unless noted)
- All times in milliseconds (lower is better)

## Results (p50 — median latency)

| Version     | URL bytes | Encoder (portable) | FastEncoder (64-bit) | FfiEncoder (C++) | Fast/Encoder | FFI/Encoder |
|-------------|-----------|--------------------| ---------------------|------------------|--------------|-------------|
| v1 L        | 12        | 5.1 ms             | 0.29 ms              | 0.07 ms          | **17.7×**    | **78×**     |
| v2 M        | 19        | 7.3 ms             | 0.35 ms              | 0.14 ms          | **21.5×**    | **50×**     |
| v5 M        | 70        | 16.7 ms            | 0.49 ms              | 0.22 ms          | **34×**      | **74×**     |
| v10 M       | 260       | 56.5 ms            | 1.88 ms              | 0.74 ms          | **30×**      | **76×**     |

## Full Results (all percentiles)

### Encoder (portable)

| Version      | p50        | p95        | mean       |
|--------------|------------|------------|------------|
| v1 (12B)     | 5.071 ms   | 7.360 ms   | 5.256 ms   |
| v2 (19B)     | 7.477 ms   | 10.322 ms  | 7.784 ms   |
| v4 (48B)     | 13.780 ms  | 17.176 ms  | 14.159 ms  |
| v6 (130B)    | 36.372 ms  | 42.776 ms  | 36.492 ms  |
| v8 (168B)    | 43.856 ms  | 53.327 ms  | 45.557 ms  |
| v11 (250B)   | 59.403 ms  | 73.534 ms  | 61.722 ms  |
| v14 (360B)   | 87.077 ms  | 109.344 ms | 89.438 ms  |
| v17 (500B)   | 110.658 ms | 123.665 ms | 112.298 ms |
| v20 (660B)   | 149.467 ms | 163.132 ms | 150.319 ms |
| v24 (910B)   | 200.484 ms | 222.745 ms | 203.515 ms |
| v27 (1120B)  | 243.557 ms | 260.205 ms | 244.786 ms |

### FastEncoder (64-bit, int-pair packed)

| Version      | p50       | p95       | mean      |
|--------------|-----------|-----------|-----------|
| v1 (12B)     | 0.286 ms  | 0.337 ms  | 0.287 ms  |
| v2 (19B)     | 0.348 ms  | 0.570 ms  | 0.375 ms  |
| v4 (48B)     | 0.494 ms  | 0.801 ms  | 0.523 ms  |
| v6 (130B)    | 1.040 ms  | 1.649 ms  | 1.128 ms  |
| v8 (168B)    | 1.073 ms  | 1.206 ms  | 1.094 ms  |
| v11 (250B)   | 1.884 ms  | 2.446 ms  | 1.920 ms  |
| v14 (360B)   | 2.148 ms  | 2.596 ms  | 2.218 ms  |
| v17 (500B)   | 3.514 ms  | 3.869 ms  | 3.554 ms  |
| v20 (660B)   | 5.236 ms  | 5.954 ms  | 5.301 ms  |
| v24 (910B)   | 5.943 ms  | 7.569 ms  | 6.188 ms  |
| v27 (1120B)  | 7.380 ms  | 18.937 ms | 8.145 ms  |

### FfiEncoder (native C++20)

| Version      | p50       |
|--------------|-----------|
| v1 (12B)     | 0.07 ms   |
| v2 (19B)     | 0.14 ms   |
| v5 (70B)     | 0.22 ms   |
| v10 (260B)   | 0.74 ms   |

## Key Takeaways

- **FfiEncoder is 50–78× faster than the portable Encoder** — sub-millisecond for all tested versions
- **FastEncoder is 18–41× faster than the portable Encoder** (p50 median) across all versions v1–v27
- **FfiEncoder is 2–4× faster than FastEncoder** — native C++ eliminates PHP interpreter overhead entirely
- All three encoders produce byte-for-byte identical output, verified against nayuki's reference implementation (1772 test cases)

## Architecture

```
QRCode (factory — auto-selects fastest available)
  ├── FfiEncoder (v1-v27, C++ via FFI, requires libscanme_qr.so)
  │     ��── fallback ↓
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
