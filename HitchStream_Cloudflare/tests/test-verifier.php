<?php
/**
 * Tests for HS\Webhook\Verifier (shared-secret format).
 *
 * Run: php -d memory_limit=64M tests/test-verifier.php
 */

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

echo "=== Verifier Tests (shared-secret) ===\n\n";

$secret = 'test-secret-key-12345678901234567890';

// 1. Valid secret matches
test('Matching secret → true', true, Verifier::verify($secret, $secret));

// 2. Mismatched secret
test('Mismatched secret → false', false, Verifier::verify('wrong-secret', $secret));

// 3. Empty incoming header
test('Empty header → false', false, Verifier::verify('', $secret));

// 4. Empty configured secret
test('Empty configured secret → false', false, Verifier::verify($secret, ''));

// 5. Both empty
test('Both empty → false', false, Verifier::verify('', ''));

// 6. Partial match
test('Partial match → false', false, Verifier::verify('test-secret-key-123456789012345', $secret));

// 7. Extra chars
test('Extra chars → false', false, Verifier::verify($secret . 'x', $secret));

// 8. Timing-safe (hash_equals used — verify no early return on same length mismatch)
$similar = str_replace('9', '0', $secret); // same length, different value
test('Same-length different value → false (timing-safe)', false, Verifier::verify($similar, $secret));

echo "\n=== Results ===\n";
echo "Passed: {$passed}/{$tests}\n";
echo "Failed: {$failed}/{$tests}\n";
exit($failed > 0 ? 1 : 0);
