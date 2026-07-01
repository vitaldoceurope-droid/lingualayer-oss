# Real load tests (outside Pest)

These tests cannot run inside the Pest suite because they require multiple OS
processes, a real HTTP server, and a real cache backend (Redis or Memcached).
They're meant to run against a deployed instance — a staging server, a VPS, or
`php artisan serve` locally with `LINGUA_CACHE_DRIVER=redis`.

## What to measure

| Concern              | Tool      | What "good" looks like                                       |
|----------------------|-----------|--------------------------------------------------------------|
| Throughput cache hit | wrk / k6  | p99 < 50 ms for cached pages on a warm instance              |
| Concurrent warms     | xargs -P  | 10 parallel `lingua:warm` workers complete without errors    |
| Rate-limit accuracy  | hey / ab  | 31st req/min returns 429; 30 succeed                         |
| Translation latency  | curl + jq | Cold first hit ≤ 5 s; subsequent hits ≤ 100 ms (cache served) |

## Suggested commands

### Cached page throughput (wrk, 60s, 50 concurrent)
```bash
wrk -t4 -c50 -d60s -H "Cookie: lingua_lang=fr" https://your.host/dashboard
```

### Rate-limit verification
```bash
for i in $(seq 1 35); do
  curl -s -o /dev/null -w "%{http_code}\n" \
    -X POST -H "Content-Type: application/json" \
    -d '{"fields":{"msg":"hola"},"source_lang":"fr"}' \
    https://your.host/lingua/translate-input
done | sort | uniq -c
# Expect: 30x 200 + 5x 429
```

### Parallel warm workers
```bash
seq 1 10 | xargs -n1 -P10 -I{} php artisan lingua:warm --langs=fr
```

## What NOT to chase here

- "Calidad de traducción" — that's `php artisan lingua:bench-quality`,
  offline, with reference dataset. Not a load test.
- "Cobertura de código" — that's `vendor/bin/pest --coverage`. Not a load
  test either.
- Multi-host distributed load — that's a benchmarking environment, not the
  package's responsibility.
