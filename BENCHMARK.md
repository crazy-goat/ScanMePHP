# ScanMePHP — Encoder Benchmark Results

Benchmark comparing two encoder implementations across QR versions 1–27.

- **Encoder (portable)** — scalar algorithms, v1–v40, all encoding modes (Numeric, Alphanumeric, Byte, Kanji), 32/64-bit safe
- **FastEncoder (64-bit)** — monolithic int-packed encoder using int-pair `[$hi, $lo]` representation, v1–v27, Byte mode only, 64-bit PHP required

## Environment

- PHP 8.4.11 (64-bit)
- 200 iterations per test (scaled down for v17+ due to Encoder latency), warmup iterations
- Error Correction Level: Medium
- All times in milliseconds (lower is better)

## Results (p50 — median latency)

| Version     | URL bytes | Encoder (portable) | FastEncoder (64-bit) | Speedup |
|-------------|-----------|--------------------| ---------------------|---------|
| v1          | 12        | 5.071 ms           | 0.286 ms             | **17.7×** |
| v2          | 19        | 7.477 ms           | 0.348 ms             | **21.5×** |
| v4          | 48        | 13.780 ms          | 0.494 ms             | **27.9×** |
| v6          | 130       | 36.372 ms          | 1.040 ms             | **35.0×** |
| v8          | 168       | 43.856 ms          | 1.073 ms             | **40.9×** |
| v11         | 250       | 59.403 ms          | 1.884 ms             | **31.5×** |
| v14         | 360       | 87.077 ms          | 2.148 ms             | **40.5×** |
| v17         | 500       | 110.658 ms         | 3.514 ms             | **31.5×** |
| v20         | 660       | 149.467 ms         | 5.236 ms             | **28.5×** |
| v24         | 910       | 200.484 ms         | 5.943 ms             | **33.7×** |
| v27         | 1120      | 243.557 ms         | 7.380 ms             | **33.0×** |

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

## Key Takeaways

- **FastEncoder is 18–41× faster than the portable Encoder** (p50 median) across all versions v1–v27
- **FastEncoder achieves sub-10ms encoding** for all versions v1–v27, compared to 5–244ms for the Encoder
- The speedup is consistent across all QR sizes, with the largest gains in the v4–v14 range (28–41×)
- For v1–v11 (size ≤ 61), the int-pair `$hi` component is always 0, adding zero overhead
- For v12–v27 (size 65–125), both `$hi` and `$lo` are used, with the int-pair operations adding minimal overhead

## Architecture

```
QRCode (factory)
  ├── 64-bit PHP → FastEncoder (v1-v27, Byte mode, int-pair packed)
  │     └── fallback → Encoder (if URL exceeds v27 capacity)
  └── 32-bit PHP → Encoder (v1-v40, all modes, scalar)
```

Both encoders implement `EncoderInterface` and produce valid, scannable QR codes.
Different mask selections between encoders are expected (both are valid per ISO/IEC 18004).

## Running the Benchmark

```bash
php examples/benchmark.php              # 200 iterations, table output
php examples/benchmark.php 500          # 500 iterations
php examples/benchmark.php 200 json     # JSON output
```
