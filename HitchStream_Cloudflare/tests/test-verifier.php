<?php
/**
 * Tests for HS\Webhook\Verifier
 *
 * Run: php -d memory_limit=64M tests/test-verifier.php
 */

// Autoload the Verifier class
require_once __DIR__ . '/../src/HS/Webhook/Verifier.php';
use HS\Webhook\Verifier;

$tests = 0;
$passed = 0;
$failed = 0;

function test($name, $expected, $actual) {
    global $tests, $passed, $failed;
    $tests++;
    if ($expected === $actual) {
        $passed++;
        echo "  PASS: {$name}\n";
    } else {
        $failed++;
        echo "  FAIL: {$name} — expected " . json_encode($expected) . ", got " . json_encode($actual) . "\n";
    }
}

echo "=== Verifier Tests ===\n\n";

$secret = 'test-secret-key-12345678901234567890';

// 1. Plain HMAC: secret is the signature directly (fallback mode)
$body = '{"data":{"event_type":"live_input.connected","input_id":"abc123"}}';
$plain_sig = hash_hmac('sha256', $body, $secret);
test('Plain HMAC verification succeeds', true, Verifier::verify($plain_sig, $body, $secret, 300));
test('Plain HMAC with bad sig fails', false, Verifier::verify('bad-signature', $body, $secret, 300));

// 2. Timestamped format: t=<ts>,v1=<hmac> over "<ts>.<body>"
$ts = time();
$payload = "{$ts}.{$body}";
$ts_sig = hash_hmac('sha256', $payload, $secret);
$ts_header = "t={$ts},v1={$ts_sig}";
test('Timestamped HMAC verification succeeds', true, Verifier::verify($ts_header, $body, $secret, 300));

// 3. Expired timestamp (B1.2 replay protection)
$old_ts = $ts - 600; // 10 min ago
$old_payload = "{$old_ts}.{$body}";
$old_sig = hash_hmac('sha256', $old_payload, $secret);
$old_header = "t={$old_ts},v1={$old_sig}";
test('Expired timestamp rejected', false, Verifier::verify($old_header, $body, $secret, 300));

// 4. No secret
test('No secret → false', false, Verifier::verify($ts_sig, $body, '', 300));

// 5. No signature header
test('No signature → false', false, Verifier::verify('', $body, $secret, 300));

// 6. Future timestamp (within tolerance)
$future_ts = time() + 100;
$future_payload = "{$future_ts}.{$body}";
$future_sig = hash_hmac('sha256', $future_payload, $secret);
$future_header = "t={$future_ts},v1={$future_sig}";
test('Future timestamp within tolerance → false (replay protection)', false, Verifier::verify($future_header, $body, $secret, 300));

// 7. Malformed timestamped format (should fall through to plain HMAC)
test('Malformed ts header falls through to plain HMAC', true, Verifier::verify($plain_sig, $body, $secret, 300));

echo "\n=== Results ===\n";
echo "Passed: {$passed}/{$tests}\n";
echo "Failed: {$failed}/{$tests}\n";
exit($failed > 0 ? 1 : 0);
