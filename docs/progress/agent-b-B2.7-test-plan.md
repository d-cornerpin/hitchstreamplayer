# B2.7 Load-Test Plan

**Prerequisites:** End-of-project staging validation with full v2 deployed (webhook receiver + REST live-state endpoint).
**Load tool:** k6 (recommended) or Apache Bench (ab). k6 preferred for accurate concurrent simulation.

---

## Test 1: 50 concurrent viewers, 10s polling, 1 hour duration

### Setup
```bash
# Configure: 50 concurrent viewers, each polling every 10s for 1 hour.
# Simulates one busy wedding event.
export URL='https://STAGING_URL/wp-json/hitchstream/v1/live-state'
export INPUT='test-input-id'
```

### k6 script (`live-state-load.js`)
```js
import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedItems, Counter } from 'k6/data';

export const options = {
    duration: '3600s', // 1 hour
    vus: 50,           // 50 concurrent viewers
    maxVus: 50,
};

let pollCounter = new Counter('total_polls');

export default function () {
    const res = http.get(`${URL}?inputId=${INPUT}`, {
        tags: { name: 'live-state-poll' },
    });

    pollCounter.add(1);

    check(res, {
        'status is 200 or 304': (r) => r.status === 200 || r.status === 304,
        'content-type is json': (r) => r.headers['Content-Type'] && r.headers['Content-Type'].includes('application/json'),
        'has ETag header': (r) => r.headers['ETag'] !== undefined,
        'has X-HS-Correlation-Id': (r) => r.headers['X-HS-Correlation-Id'] !== undefined,
        'response time < 100ms': (r) => r.timings.duration < 100,
    });

    sleep(10); // poll every 10s
}
```

### Run
```bash
k6 run live-state-load.js
```

### Expected results

| Metric | Target | Notes |
|--------|--------|-------|
| p50 response time | < 10ms | Flat-file path should be near-instant |
| p95 response time | < 50ms | Per §4 requirements |
| p99 response time | < 100ms | Worst case: transient miss + /lifecycle probe |
| CPU usage | < 5% of one core (4GB droplet) | Monitor via `top` or `htop` during test |
| Memory usage | < 200MB RSS | No memory leaks over 1 hour |
| Error rate | 0% | No 5xx or connection errors |
| Disk I/O | Negligible | Flat files are small JSON, infrequent writes |

### CPU monitoring (run alongside k6)
```bash
# Terminal 2: monitor CPU on the droplet
watch -n 1 'top -l 1 | head -10'
```

---

## Test 2: Cache-miss spike (B2.4 single-flight lock)

### Scenario
50 concurrent viewers hit the endpoint simultaneously after a transient/flat-file expiry. Verify only ONE /lifecycle probe fires.

### Setup
```bash
# Clear all state for the test input to force a cache miss.
wp transient delete hs_live_state_test-input-id --allow-root
wp transient delete hs_webhook_update_ts_test-input-id --allow-root
rm -f /var/www/html/wp-content/hs-state/test-input-id.json
```

### k6 script (`spike.js`)
```js
import http from 'k6/http';
import { check } from 'k6';

export const options = {
    stages: [
        { duration: '10s', target: 50 },  // ramp up to 50 VUs in 10s
        { duration: '30s', target: 50 },  // hold
        { duration: '10s', target: 0 },   // ramp down
    ],
};

export default function () {
    const res = http.get('https://STAGING_URL/wp-json/hitchstream/v1/live-state?inputId=test-input-id');
    check(res, {
        'status 200 or 304': (r) => r.status === 200 || r.status === 304,
        'response < 200ms': (r) => r.timings.duration < 200,
    });
}
```

### Run
```bash
k6 run spike.js
```

### Expected
- Only ONE /lifecycle API call to Cloudflare (verify via WordPress admin or activity log)
- All 50 VUs get HTTP 200
- p95 response < 200ms (one VU may hit the probe latency, others served coalesced)
- No 5xx errors

---

## Test 3: ETag/304 correctness under load

### Scenario
Verify that ETag remains stable across repeated polls while state is unchanged.

### curl-based verification
```bash
# Poll 1: get the ETag
ETAG=$(curl -s -D - -o /dev/null "https://STAGING_URL/wp-json/hitchstream/v1/live-state?inputId=test-input-id" | grep -i ETag | tr -d '\r' | awk '{print $2}')
echo "ETag: $ETAG"

# Poll 2: send If-None-Match → expect 304
curl -s -o /dev/null -w "%{http_code}" \
  -H "If-None-Match: $ETAG" \
  "https://STAGING_URL/wp-json/hitchstream/v1/live-state?inputId=test-input-id"
# Expected: 304
```

### Run under load (k6)
```js
// In the main load test, add:
let etag = '';
export default function () {
    const res = http.get(`${URL}?inputId=${INPUT}`, {
        headers: etag ? { 'If-None-Match': etag } : {},
    });

    if (res.headers['ETag']) {
        etag = res.headers['ETag'];
    }

    check(res, {
        '304 when ETag matches': (r) => etag && r.headers['ETag'] === etag ? r.status === 304 : true,
    });

    sleep(10);
}
```

### Expected
- While state is unchanged: all polls return 304 with the same ETag
- When state changes: ETag changes, client receives 200 with new state
- No inconsistent ETags under concurrent load

---

## Test 4: Flat-file vs transient comparison

### Scenario
Verify the flat-file read path works correctly and is faster than transient.

### Manual test
```bash
# Ensure flat-file exists
ls -la /var/www/html/wp-content/hs-state/test-input-id.json

# Time flat-file read (via HTTP, which includes the full handler)
time curl -s "https://STAGING_URL/wp-json/hitchstream/v1/live-state?inputId=test-input-id" > /dev/null

# Expected: < 5ms for flat-file path (if transient also fresh, the handler reads flat file first)
```

### Expected
- Flat file exists and contains valid JSON
- Read path returns same data as transient path
- Response time < 5ms for flat-file (no DB queries)

---

## Test 5: Error handling under load

### Scenario
Simulate Cloudflare /lifecycle endpoint returning errors while 50 viewers are polling.

### Setup
```bash
# Temporarily set an invalid customer code to force /lifecycle to 404.
wp option update HSCF_customer_id "invalid-code-99999" --allow-root
```

### Run
```bash
k6 run spike.js  # from Test 2
```

### Expected
- All 50 VUs get HTTP 502 with `{"error": "Upstream unavailable", "code": "upstream_unavailable"}`
- No PHP errors or crashes
- CPU < 10% during error period
- After restoring customer code: polls resume normally

### Cleanup
```bash
wp option update HSCF_customer_id "juu1r5es4cbffqjf" --allow-root
```

---

## Results documentation

After running all tests, document results in `docs/load-test-results.md`:

| Test | p50 (ms) | p95 (ms) | p99 (ms) | CPU% | Memory (MB) | Errors |
|------|----------|----------|----------|------|-------------|--------|
| 50-viewer 1h | TBD | TBD | TBD | TBD | TBD | TBD |
| Cache-miss spike | TBD | TBD | TBD | TBD | TBD | TBD |
| ETag 304 | TBD | TBD | TBD | TBD | TBD | TBD |
| Flat-file | TBD | - | - | TBD | TBD | TBD |
| Error handling | TBD | TBD | TBD | TBD | TBD | TBD |
