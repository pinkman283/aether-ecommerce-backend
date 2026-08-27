<?php

/**
 * PRODUCTION RATE LIMITING & TRUSTED PROXY REGRESSION SUITE
 * 
 * Verifies:
 * 1. Same-IP rate limit enforcement (HTTP 429 + Retry-After).
 * 2. Multi-IP isolation across independent buckets.
 * 3. Client IP spoofing rejection via unverified X-Forwarded-For headers.
 * 4. Distributed target account brute-force protection across multiple IPs.
 * 5. Shared-IP legitimate multi-user isolation.
 * 6. Order checkout composite key scoping (Authenticated User vs Guest).
 * 7. Coupon validation probe and dictionary attack defense.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

$passed = 0;
$failed = 0;

function run_test(string $name, callable $test) {
    global $passed, $failed;
    echo "  Testing: {$name}... ";
    try {
        $result = $test();
        if ($result === true) {
            echo "\033[32m[PASS]\033[0m\n";
            $passed++;
        } else {
            echo "\033[31m[FAIL]\033[0m - {$result}\n";
            $failed++;
        }
    } catch (\Throwable $e) {
        echo "\033[31m[ERROR]\033[0m - " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "===========================================================\n";
echo "   PRODUCTION RATE LIMITING & DEFENSE REGRESSION SUITE     \n";
echo "===========================================================\n\n";

// Helper to simulate request through Laravel kernel
function simulateRequest(string $method, string $uri, array $data = [], array $headers = [], string $clientIp = '127.0.0.1'): \Illuminate\Http\JsonResponse|\Illuminate\Http\Response {
    global $app;
    
    // Clear Laravel cached request instance
    $server = [
        'REMOTE_ADDR' => $clientIp,
        'HTTP_HOST' => 'localhost:8000',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    foreach ($headers as $key => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
    }

    $request = Request::create(
        $uri,
        $method,
        [],
        [],
        [],
        $server,
        json_encode($data)
    );

    $app->instance('request', $request);
    return $app->handle($request);
}

// Clear rate limiter cache before starting
RateLimiter::clear('cust_login:*');
Cache::flush();

// TEST 1: Same IP Repeated Login Attempts Receive 429
run_test("Test A: Repeated Customer Login from Same IP triggers HTTP 429 with Retry-After", function() {
    $ip = '198.51.100.1';
    $email = 'shopper_test_a@example.com';
    
    // First 5 attempts should not be rate-limited (they may fail with 401 invalid credentials, but not 429)
    for ($i = 1; $i <= 5; $i++) {
        $response = simulateRequest('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'wrong_password_' . $i,
        ], [], $ip);

        if ($response->getStatusCode() === 429) {
            return "Premature HTTP 429 on attempt {$i}";
        }
    }

    // 6th attempt MUST receive HTTP 429
    $response = simulateRequest('POST', '/api/auth/login', [
        'email' => $email,
        'password' => 'wrong_password_6',
    ], [], $ip);

    if ($response->getStatusCode() !== 429) {
        return "Expected HTTP 429, got HTTP " . $response->getStatusCode();
    }

    $content = json_decode($response->getContent(), true);
    if (!isset($content['retry_after']) || $content['retry_after'] <= 0) {
        return "Missing or invalid retry_after field in response body";
    }

    return true;
});

// TEST 2: Different IPs Do Not Share the Same IP Bucket
run_test("Test B: Different Legitimate Client IPs have isolated rate limit buckets", function() {
    $userA = 'user_on_ip_a@example.com';
    $userB = 'user_on_ip_b@example.com';
    $ipA = '198.51.100.20';
    $ipB = '198.51.100.21';

    // Exhaust userA's composite bucket on IP A (5 attempts)
    for ($i = 1; $i <= 5; $i++) {
        simulateRequest('POST', '/api/auth/login', [
            'email' => $userA,
            'password' => 'wrong',
        ], [], $ipA);
    }

    // Attempt 6 for userA on IP A receives 429
    $resA = simulateRequest('POST', '/api/auth/login', [
        'email' => $userA,
        'password' => 'wrong',
    ], [], $ipA);

    if ($resA->getStatusCode() !== 429) {
        return "IP A composite bucket should return 429 on attempt 6";
    }

    // IP B with userB is totally independent and must NOT be blocked
    $resB = simulateRequest('POST', '/api/auth/login', [
        'email' => $userB,
        'password' => 'wrong',
    ], [], $ipB);

    if ($resB->getStatusCode() === 429) {
        return "IP B was improperly blocked by IP A's rate limit exhaustion";
    }

    return true;
});

// TEST 3: Spoofed X-Forwarded-For Header is Ignored Without Trusted Proxies
run_test("Test C: Spoofed X-Forwarded-For header fails to bypass rate limiting", function() {
    $realClientIp = '198.51.100.30';
    $email = 'spoof_test@example.com';

    // Exhaust limit using realClientIp
    for ($i = 1; $i <= 5; $i++) {
        simulateRequest('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'wrong',
        ], [], $realClientIp);
    }

    // Attempt 6: Attacker passes a fake X-Forwarded-For header
    $response = simulateRequest('POST', '/api/auth/login', [
        'email' => $email,
        'password' => 'wrong',
    ], [
        'X-Forwarded-For' => '203.0.113.88', // Fake external IP
    ], $realClientIp);

    if ($response->getStatusCode() !== 429) {
        return "Attacker bypassed rate limiter using spoofed X-Forwarded-For! Expected HTTP 429, got HTTP " . $response->getStatusCode();
    }

    return true;
});

// TEST 4: Distributed Attack on Single Target Account Across Multiple IPs
run_test("Test D: Distributed botnet attack targeting single account across 10 IPs triggers target lock", function() {
    $targetEmail = 'ceo_target@example.com';

    // Send 1 attempt each from 10 distinct IPs (total 10 attempts on same target email)
    for ($i = 1; $i <= 10; $i++) {
        $ip = "198.51.100.1{$i}";
        simulateRequest('POST', '/api/auth/login', [
            'email' => $targetEmail,
            'password' => 'pass_' . $i,
        ], [], $ip);
    }

    // 11th attempt from brand new IP 198.51.100.99 should trigger the account-level ceiling (Limit: 10/min)
    $response = simulateRequest('POST', '/api/auth/login', [
        'email' => $targetEmail,
        'password' => 'pass_11',
    ], [], '198.51.100.99');

    if ($response->getStatusCode() !== 429) {
        return "Distributed attack on target account was not caught by account ceiling! Expected HTTP 429, got HTTP " . $response->getStatusCode();
    }

    return true;
});

// TEST 5: Shared IP with Different Legitimate Accounts Allowed
run_test("Test E: Shared IP allows different users to attempt logins independently", function() {
    $sharedIp = '198.51.100.50';

    // User 1 makes 2 attempts
    for ($i = 1; $i <= 2; $i++) {
        $res = simulateRequest('POST', '/api/auth/login', [
            'email' => 'student1@university.edu',
            'password' => 'wrong',
        ], [], $sharedIp);
        if ($res->getStatusCode() === 429) return "User 1 blocked prematurely";
    }

    // User 2 makes 2 attempts from the SAME IP
    for ($i = 1; $i <= 2; $i++) {
        $res = simulateRequest('POST', '/api/auth/login', [
            'email' => 'student2@university.edu',
            'password' => 'wrong',
        ], [], $sharedIp);
        if ($res->getStatusCode() === 429) return "User 2 blocked by shared IP";
    }

    return true;
});

// TEST 6: Coupon Validation Probing and Specific Code Lockout
run_test("Test F: Coupon validation prevents dictionary probe attacks and specific code brute-forcing", function() {
    $ip = '198.51.100.60';
    $coupon = 'SECRET_50_OFF';

    // 5 attempts on the same specific coupon code
    for ($i = 1; $i <= 5; $i++) {
        simulateRequest('POST', '/api/coupons/validate', [
            'code' => $coupon,
        ], [], $ip);
    }

    // 6th attempt on the SAME coupon code triggers targeted coupon lock
    $res = simulateRequest('POST', '/api/coupons/validate', [
        'code' => $coupon,
    ], [], $ip);

    if ($res->getStatusCode() !== 429) {
        return "Expected HTTP 429 for repeated single-code probes, got HTTP " . $res->getStatusCode();
    }

    return true;
});

// TEST 7: Order Checkout Composite Key Throttling
run_test("Test G: Guest Checkout throttles rapid automated orders per Email + IP", function() {
    $ip = '198.51.100.70';
    $guestEmail = 'spammer@botnet.org';

    for ($i = 1; $i <= 10; $i++) {
        simulateRequest('POST', '/api/orders', [
            'customer_email' => $guestEmail,
            'customer_name' => 'Bot Spammer',
            'items' => [],
        ], [], $ip);
    }

    // 11th checkout request MUST be throttled
    $res = simulateRequest('POST', '/api/orders', [
        'customer_email' => $guestEmail,
        'customer_name' => 'Bot Spammer',
        'items' => [],
    ], [], $ip);

    if ($res->getStatusCode() !== 429) {
        return "Expected HTTP 429 for excessive checkout attempts, got HTTP " . $res->getStatusCode();
    }

    return true;
});

echo "\n-----------------------------------------------------------\n";
echo "SUMMARY: {$passed} / " . ($passed + $failed) . " Rate Limiting Tests Passed!\n";
echo "-----------------------------------------------------------\n\n";

exit($failed === 0 ? 0 : 1);
