<?php

/**
 * PRODUCTION SLIDING WINDOW RATE LIMITING & SECURITY SUITE
 * 
 * Verifies:
 * 1. Sliding Window Log mathematical precision (zero boundary burst vulnerability).
 * 2. Exact sub-second and second-level Retry-After calculation.
 * 3. Rolling window slot release (old timestamps drop off independently).
 * 4. Multi-tier composite rate limiting via SlidingWindowThrottle middleware.
 * 5. Route integration across Auth, Checkout, Coupons, and Admin actions.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Ensure test uses file cache store (independent of MySQL daemon)
config(['cache.default' => 'file']);

use App\Services\Security\SlidingWindowRateLimiter;
use App\Http\Middleware\SlidingWindowThrottle;
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
echo "   SLIDING WINDOW RATE LIMITING REGRESSION SUITE           \n";
echo "===========================================================\n\n";

$limiter = new SlidingWindowRateLimiter();
$throttleMiddleware = new SlidingWindowThrottle($limiter);

// -------------------------------------------------------------
// PART 1: SLIDING WINDOW LOG ALGORITHM UNIT VERIFICATION
// -------------------------------------------------------------
echo "1. ALGORITHM & MATHEMATICAL PRECISION TESTS:\n";

run_test("Sliding Window allows attempts within limit and blocks excess", function () use ($limiter) {
    $key = 'test_basic_' . uniqid();
    $limiter->clear($key);

    for ($i = 1; $i <= 5; $i++) {
        $res = $limiter->attempt($key, 5, 60);
        if (!$res['allowed'] || $res['remaining'] !== (5 - $i)) {
            return "Expected attempt {$i} to be allowed with remaining " . (5 - $i) . ", got: " . json_encode($res);
        }
    }

    // 6th attempt should be blocked
    $res6 = $limiter->attempt($key, 5, 60);
    if ($res6['allowed'] !== false || $res6['remaining'] !== 0 || $res6['retry_after'] <= 0) {
        return "Expected attempt 6 to be blocked with retry_after > 0, got: " . json_encode($res6);
    }

    return true;
});

run_test("Sliding Window eliminates fixed-window boundary burst attack", function () use ($limiter) {
    $key = 'test_boundary_' . uniqid();
    $limiter->clear($key);

    $baseTime = 1700000000.0; // t = 0

    // Simulate 5 requests at t = 50s (near end of a 60s minute)
    for ($i = 0; $i < 5; $i++) {
        $res = $limiter->attempt($key, 5, 60, $baseTime + 50.0 + ($i * 0.1));
        if (!$res['allowed']) {
            return "Attempt at t=50s failed unexpectedly";
        }
    }

    // Fixed-window would reset at t = 60s and allow 5 more requests at t = 62s (bursting 10 requests in 12s).
    // Sliding Window MUST correctly track the last 60 seconds (t = 2s to t = 62s) and BLOCK requests!
    $burstRes = $limiter->attempt($key, 5, 60, $baseTime + 62.0);
    if ($burstRes['allowed'] !== false) {
        return "Vulnerability detected: Sliding window allowed burst across 60s boundary!";
    }

    // Retry after should be exactly (50.0 + 60) - 62.0 = 48 seconds
    if ($burstRes['retry_after'] !== 48) {
        return "Expected retry_after to be 48s, got: " . $burstRes['retry_after'];
    }

    return true;
});

run_test("Sliding Window releases slots smoothly as individual timestamps expire", function () use ($limiter) {
    $key = 'test_rolling_' . uniqid();
    $limiter->clear($key);

    $baseTime = 1700000000.0;

    // Send 3 requests at t = 10s, and 2 requests at t = 40s (Total = 5 limit reached)
    $limiter->attempt($key, 5, 60, $baseTime + 10.0);
    $limiter->attempt($key, 5, 60, $baseTime + 11.0);
    $limiter->attempt($key, 5, 60, $baseTime + 12.0);
    $limiter->attempt($key, 5, 60, $baseTime + 40.0);
    $limiter->attempt($key, 5, 60, $baseTime + 41.0);

    // At t = 50s, limit is full
    $resAt50 = $limiter->attempt($key, 5, 60, $baseTime + 50.0);
    if ($resAt50['allowed'] !== false) {
        return "Expected limit reached at t=50s";
    }

    // Advance time to t = 71.0s (first request at t=10 is older than 60s window: 71 - 60 = 11s)
    // 1 slot (from t=10) has expired. New request at t=71 should be ALLOWED!
    $resAt71 = $limiter->attempt($key, 5, 60, $baseTime + 71.0);
    if (!$resAt71['allowed']) {
        return "Expected slot to be open at t=71s, but got blocked";
    }

    return true;
});

run_test("Sliding Window clear and reset functions", function () use ($limiter) {
    $key = 'test_clear_' . uniqid();
    for ($i = 0; $i < 5; $i++) {
        $limiter->attempt($key, 5, 60);
    }
    if (!$limiter->tooManyAttempts($key, 5, 60)) {
        return "Key should be full";
    }
    $limiter->clear($key);
    if ($limiter->tooManyAttempts($key, 5, 60)) {
        return "Key should be clear after clear()";
    }
    return true;
});

echo "\n2. HTTP SLIDING WINDOW THROTTLE MIDDLEWARE TESTS:\n";

// Helper to simulate request through SlidingWindowThrottle middleware
function processThrottle(SlidingWindowThrottle $middleware, string $method, string $uri, array $data = [], string $clientIp = '127.0.0.1', string $limiterName = 'default') {
    $server = [
        'REMOTE_ADDR' => $clientIp,
        'HTTP_HOST' => 'localhost:8000',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    $request = Request::create(
        $uri,
        $method,
        [],
        [],
        [],
        $server,
        !empty($data) ? json_encode($data) : null
    );

    $next = function (Request $req) {
        return response()->json(['status' => 'success', 'message' => 'ok']);
    };

    return $middleware->handle($request, $next, $limiterName);
}

run_test("Customer Login enforces sliding window 5 req/min with HTTP 429 & Retry-After", function () use ($throttleMiddleware) {
    $ip = '10.200.1.1';
    $email = 'sliding_cust_' . uniqid() . '@example.com';
    
    // Clear cache
    Cache::forget('sw_rate_limit:cust_login:' . strtolower($email) . '|' . $ip);
    Cache::forget('sw_rate_limit:cust_login_account:' . strtolower($email));
    Cache::forget('sw_rate_limit:cust_login_ip:' . $ip);

    // 5 attempts allowed through middleware
    for ($i = 1; $i <= 5; $i++) {
        $resp = processThrottle($throttleMiddleware, 'POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'wrongpass'
        ], $ip, 'auth-customer-login');

        if ($resp->getStatusCode() === 429) {
            return "Attempt {$i} prematurely blocked with 429";
        }
        $remainingHeader = $resp->headers->get('X-RateLimit-Remaining');
        if ($remainingHeader === null || (int)$remainingHeader !== (5 - $i)) {
            return "Attempt {$i} invalid X-RateLimit-Remaining: " . var_export($remainingHeader, true);
        }
    }

    // 6th attempt MUST return HTTP 429 with Retry-After header
    $blockedResp = processThrottle($throttleMiddleware, 'POST', '/api/auth/login', [
        'email' => $email,
        'password' => 'wrongpass'
    ], $ip, 'auth-customer-login');

    if ($blockedResp->getStatusCode() !== 429) {
        return "Expected 429 on 6th attempt, got: " . $blockedResp->getStatusCode();
    }

    $retryAfter = (int) $blockedResp->headers->get('Retry-After');
    if ($retryAfter <= 0 || $retryAfter > 60) {
        return "Expected Retry-After between 1 and 60, got: " . $retryAfter;
    }

    $data = json_decode($blockedResp->getContent(), true);
    if (!isset($data['retry_after']) || $data['retry_after'] !== $retryAfter) {
        return "JSON payload missing matching retry_after: " . $blockedResp->getContent();
    }

    return true;
});

run_test("Customer Login Tier 2 (Target Account) blocks distributed attack across 10 IPs", function () use ($throttleMiddleware) {
    $email = 'distributed_target_' . uniqid() . '@example.com';
    Cache::forget('sw_rate_limit:cust_login_account:' . strtolower($email));

    // Send 1 request each from 10 distinct IPs (Limit for account tier is 10/min)
    for ($i = 1; $i <= 10; $i++) {
        $ip = "192.168.100.{$i}";
        Cache::forget('sw_rate_limit:cust_login:' . strtolower($email) . '|' . $ip);
        Cache::forget('sw_rate_limit:cust_login_ip:' . $ip);

        $resp = processThrottle($throttleMiddleware, 'POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'wrongpass'
        ], $ip, 'auth-customer-login');

        if ($resp->getStatusCode() === 429) {
            return "Distributed attempt {$i} prematurely blocked";
        }
    }

    // 11th request from a completely new IP (192.168.100.99) MUST be blocked by Tier 2 (Account limit)
    $blocked = processThrottle($throttleMiddleware, 'POST', '/api/auth/login', [
        'email' => $email,
        'password' => 'wrongpass'
    ], '192.168.100.99', 'auth-customer-login');

    if ($blocked->getStatusCode() !== 429) {
        return "Expected 429 on distributed attack 11th request, got: " . $blocked->getStatusCode();
    }

    $data = json_decode($blocked->getContent(), true);
    if (!str_contains($data['message'] ?? '', 'Too many login attempts for this account')) {
        return "Expected Tier 2 message, got: " . ($data['message'] ?? '');
    }

    return true;
});

run_test("Admin Login enforces strict sliding window 5 req/min", function () use ($throttleMiddleware) {
    $ip = '10.200.2.1';
    $email = 'sliding_admin_' . uniqid() . '@example.com';

    Cache::forget('sw_rate_limit:admin_login:' . strtolower($email) . '|' . $ip);
    Cache::forget('sw_rate_limit:admin_login_account:' . strtolower($email));
    Cache::forget('sw_rate_limit:admin_login_ip:' . $ip);

    for ($i = 1; $i <= 5; $i++) {
        $resp = processThrottle($throttleMiddleware, 'POST', '/api/admin/auth/login', [
            'email' => $email,
            'password' => 'wrongpass'
        ], $ip, 'auth-admin-login');

        if ($resp->getStatusCode() === 429) {
            return "Admin attempt {$i} prematurely blocked with 429";
        }
    }

    $blocked = processThrottle($throttleMiddleware, 'POST', '/api/admin/auth/login', [
        'email' => $email,
        'password' => 'wrongpass'
    ], $ip, 'auth-admin-login');

    if ($blocked->getStatusCode() !== 429) {
        return "Admin login 6th attempt was not blocked with 429, got: " . $blocked->getStatusCode();
    }

    return true;
});

run_test("Coupon validation probe limit enforces 10 req/min sliding window", function () use ($throttleMiddleware) {
    $ip = '10.200.3.1';
    Cache::forget('sw_rate_limit:coupon_probe:ip:' . $ip);

    for ($i = 1; $i <= 10; $i++) {
        $code = 'PROBE_' . $i . '_' . uniqid();
        Cache::forget('sw_rate_limit:coupon_target:ip:' . $ip . '|' . strtoupper($code));
        $resp = processThrottle($throttleMiddleware, 'POST', '/api/coupons/validate', [
            'code' => $code,
            'cart_total' => 100
        ], $ip, 'coupon-validation');

        if ($resp->getStatusCode() === 429) {
            return "Coupon validation {$i} prematurely blocked";
        }
    }

    // 11th request across different coupon codes from same IP should hit probe limit
    $blocked = processThrottle($throttleMiddleware, 'POST', '/api/coupons/validate', [
        'code' => 'ANOTHER_CODE',
        'cart_total' => 100
    ], $ip, 'coupon-validation');

    if ($blocked->getStatusCode() !== 429) {
        return "Expected 429 on 11th coupon probe attempt, got: " . $blocked->getStatusCode();
    }

    return true;
});

run_test("Order Checkout enforces 10 req/min sliding window", function () use ($throttleMiddleware) {
    $ip = '10.200.4.1';
    $email = 'buyer_' . uniqid() . '@example.com';
    Cache::forget('sw_rate_limit:order_guest:' . strtolower($email) . '|' . $ip);

    for ($i = 1; $i <= 10; $i++) {
        $resp = processThrottle($throttleMiddleware, 'POST', '/api/orders', [
            'customer_email' => $email,
        ], $ip, 'order-checkout');

        if ($resp->getStatusCode() === 429) {
            return "Order checkout attempt {$i} prematurely blocked";
        }
    }

    $blocked = processThrottle($throttleMiddleware, 'POST', '/api/orders', [
        'customer_email' => $email,
    ], $ip, 'order-checkout');

    if ($blocked->getStatusCode() !== 429) {
        return "Expected 429 on 11th order checkout attempt, got: " . $blocked->getStatusCode();
    }

    return true;
});

run_test("Sensitive Admin Action enforces 30 req/min limit", function () use ($throttleMiddleware) {
    $ip = '10.200.5.1';
    Cache::forget('sw_rate_limit:admin_action:anon|' . $ip);

    for ($i = 1; $i <= 30; $i++) {
        $resp = processThrottle($throttleMiddleware, 'POST', '/api/admin/staff/1/promote', [], $ip, 'sensitive-admin-action');
        if ($resp->getStatusCode() === 429) {
            return "Sensitive action {$i} prematurely blocked";
        }
    }

    $blocked = processThrottle($throttleMiddleware, 'POST', '/api/admin/staff/1/promote', [], $ip, 'sensitive-admin-action');
    if ($blocked->getStatusCode() !== 429) {
        return "Expected 429 on 31st sensitive admin action, got: " . $blocked->getStatusCode();
    }

    return true;
});

echo "\n===========================================================\n";
echo "                      SUMMARY                              \n";
echo "===========================================================\n";
echo "Total Passed: {$passed}\n";
echo "Total Failed: {$failed}\n";

if ($failed === 0) {
    echo "\033[32mALL SLIDING WINDOW TESTS PASSED PERFECTLY!\033[0m\n";
    exit(0);
} else {
    echo "\033[31mSOME TESTS FAILED!\033[0m\n";
    exit(1);
}
