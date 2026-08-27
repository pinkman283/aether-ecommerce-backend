<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\BlockedIp;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerRiskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

echo "===========================================================\n";
echo "      CUSTOMER ABUSE & RISK MANAGEMENT TEST SUITE          \n";
echo "===========================================================\n\n";

$passCount = 0;
$totalTests = 14;

function runTest($testNumber, $description, $callback) {
    global $passCount, $app;
    echo "  Testing: Test {$testNumber}: {$description}... ";
    try {
        if (app()->bound('auth')) {
            auth()->forgetGuards();
        }
        $result = $callback();
        if ($result === true) {
            echo "\033[32m[PASS]\033[0m\n";
            $passCount++;
        } else {
            echo "\033[31m[FAIL]\033[0m: {$result}\n";
        }
    } catch (\Throwable $e) {
        echo "\033[31m[ERROR]\033[0m: " . $e->getMessage() . " on line " . $e->getLine() . "\n";
    }
}

$testProduct = Product::where('stock_quantity', '>', 50)->first() ?? Product::first();
if (!$testProduct) {
    echo "No product available for testing.\n";
    exit(1);
}

// --------------------------------------------------------------------------
// TEST 1: Customer places order -> Stores backend-derived IP
// --------------------------------------------------------------------------
runTest(1, "Customer places order stores backend-derived IP", function () use ($app, $testProduct) {
    $clientIp = '198.51.100.11';
    $server = ['REMOTE_ADDR' => $clientIp, 'HTTP_HOST' => 'localhost:8000', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    
    $payload = [
        'customer_name' => 'IP Telemetry Tester',
        'customer_email' => 'ip_tester_' . uniqid() . '@example.com',
        'shipping_address' => ['address_line1' => '101 Cyber Way', 'city' => 'Metro', 'postal_code' => '10001', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ];

    $req = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($payload));
    $app->instance('request', $req);
    $res = $app->handle($req);

    if ($res->getStatusCode() !== 201) return "Expected 201, got " . $res->getStatusCode();
    $data = json_decode($res->getContent(), true);
    $order = Order::find($data['order']['id']);
    
    return ($order && $order->ip_address === $clientIp) ? true : "IP mismatch: found " . ($order->ip_address ?? 'null');
});

// --------------------------------------------------------------------------
// TEST 2: Guest places order -> Guest appears in admin customer management
// --------------------------------------------------------------------------
runTest(2, "Guest places order -> appears in customer management with customer_type = guest", function () use ($app, $testProduct) {
    $guestEmail = 'guest_buyer_' . uniqid() . '@example.com';
    $server = ['REMOTE_ADDR' => '198.51.100.22', 'HTTP_HOST' => 'localhost:8000', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    
    $payload = [
        'customer_name' => 'Guest Jane Doe',
        'customer_email' => $guestEmail,
        'shipping_address' => ['address_line1' => '500 Market St', 'city' => 'SF', 'postal_code' => '94105', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ];

    $req = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($payload));
    $app->instance('request', $req);
    $res = $app->handle($req);

    if ($res->getStatusCode() !== 201) return "Order failed with " . $res->getStatusCode();
    
    $guestUser = User::where('email', $guestEmail)->first();
    if (!$guestUser) return "Guest user record was not created in users table";
    if ($guestUser->customer_type !== 'guest') return "Expected customer_type = 'guest', got '{$guestUser->customer_type}'";
    if ($guestUser->role !== 'customer') return "Expected role = 'customer', got '{$guestUser->role}'";

    return true;
});

// --------------------------------------------------------------------------
// TEST 3: Guest places multiple orders -> Associated with single unified record
// --------------------------------------------------------------------------
runTest(3, "Guest multiple orders correctly link to existing guest record without duplicate accounts", function () use ($app, $testProduct) {
    $repeatEmail = 'repeat_guest_' . uniqid() . '@example.com';
    $server = ['REMOTE_ADDR' => '198.51.100.33', 'HTTP_HOST' => 'localhost:8000', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    
    $payload = [
        'customer_name' => 'Repeat Guest',
        'customer_email' => $repeatEmail,
        'shipping_address' => ['address_line1' => '200 Oak St', 'city' => 'SF', 'postal_code' => '94102', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ];

    // Order 1
    $req1 = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($payload));
    $app->instance('request', $req1);
    $app->handle($req1);

    // Order 2
    $req2 = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($payload));
    $app->instance('request', $req2);
    $app->handle($req2);

    $customerCount = User::where('email', $repeatEmail)->count();
    if ($customerCount !== 1) return "Expected exactly 1 customer record, found {$customerCount}";

    $guestUser = User::where('email', $repeatEmail)->first();
    $orderCount = Order::where('user_id', $guestUser->id)->count();
    if ($orderCount < 2) return "Expected at least 2 linked orders, found {$orderCount}";

    return true;
});

// --------------------------------------------------------------------------
// TEST 4: Admin blocks IP -> New order request from that IP is rejected (403)
// --------------------------------------------------------------------------
runTest(4, "Active IP block immediately rejects order creation with HTTP 403", function () use ($app, $testProduct) {
    $blockedIp = '198.51.100.44';
    CustomerRiskService::blockIp($blockedIp, 'Abusive automated bot attack', 'Test block notes', null, 'permanent');

    $server = ['REMOTE_ADDR' => $blockedIp, 'HTTP_HOST' => 'localhost:8000', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    $payload = [
        'customer_name' => 'Blocked Attacker',
        'customer_email' => 'attacker_' . uniqid() . '@example.com',
        'shipping_address' => ['address_line1' => '1 Evil Lane', 'city' => 'Nowhere', 'postal_code' => '00000', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ];

    $req = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($payload));
    $app->instance('request', $req);
    $res = $app->handle($req);

    if ($res->getStatusCode() !== 403) return "Expected 403 Forbidden, got " . $res->getStatusCode();
    $data = json_decode($res->getContent(), true);
    if (!str_contains($data['message'] ?? '', 'cannot be completed')) return "Expected generic security response";

    return true;
});

// --------------------------------------------------------------------------
// TEST 5: Blocked IP modifying frontend still receives backend 403
// --------------------------------------------------------------------------
runTest(5, "Blocked IP tampering with frontend client requests is still rejected by backend", function () use ($app, $testProduct) {
    $blockedIp = '198.51.100.55';
    CustomerRiskService::blockIp($blockedIp, 'Credential abuse', null, null, '24_hours');

    $server = ['REMOTE_ADDR' => $blockedIp, 'HTTP_HOST' => 'localhost:8000', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    $payload = [
        'customer_name' => 'Tampered Client',
        'customer_email' => 'tampered_' . uniqid() . '@example.com',
        'shipping_address' => ['address_line1' => '99 Spoof Dr', 'city' => 'Nowhere', 'postal_code' => '99999', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ];

    $req = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($payload));
    $app->instance('request', $req);
    $res = $app->handle($req);

    return $res->getStatusCode() === 403 ? true : "Expected 403, got " . $res->getStatusCode();
});

// --------------------------------------------------------------------------
// TEST 6: Blocked IP direct API request receives HTTP 403
// --------------------------------------------------------------------------
runTest(6, "Blocked IP direct headless curl/API POST receives HTTP 403", function () use ($app, $testProduct) {
    $blockedIp = '198.51.100.66';
    CustomerRiskService::blockIp($blockedIp, 'Direct API probe', null, null, 'permanent');

    $server = ['REMOTE_ADDR' => $blockedIp, 'HTTP_HOST' => 'localhost:8000', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    $req = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode([
        'customer_name' => 'Direct API Tester',
        'customer_email' => 'api_direct_' . uniqid() . '@example.com',
        'shipping_address' => ['address_line1' => '10 API Rd', 'city' => 'Austin', 'postal_code' => '78701', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ]));
    $app->instance('request', $req);
    $res = $app->handle($req);

    return $res->getStatusCode() === 403 ? true : "Expected 403, got " . $res->getStatusCode();
});

// --------------------------------------------------------------------------
// TEST 7: Customer is suspended -> Authenticated order creation rejected (403)
// --------------------------------------------------------------------------
runTest(7, "Suspended customer attempting authenticated order creation receives HTTP 403", function () use ($app, $testProduct) {
    $suspendedCustomer = User::create([
        'name' => 'Suspended Customer',
        'email' => 'suspended_' . uniqid() . '@example.com',
        'password' => Hash::make('password123'),
        'role' => 'customer',
        'status' => 'suspended',
        'suspension_reason' => 'Policy violation',
    ]);

    $token = $suspendedCustomer->createToken('test_token')->plainTextToken;
    $server = ['REMOTE_ADDR' => '198.51.100.77', 'HTTP_HOST' => 'localhost:8000', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    $payload = [
        'customer_name' => $suspendedCustomer->name,
        'customer_email' => $suspendedCustomer->email,
        'shipping_address' => ['address_line1' => '12 Pine St', 'city' => 'Seattle', 'postal_code' => '98101', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ];

    $req = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($payload));
    $app->instance('request', $req);
    $res = $app->handle($req);

    return $res->getStatusCode() === 403 ? true : "Expected 403, got " . $res->getStatusCode();
});

// --------------------------------------------------------------------------
// TEST 8: Suspended customer changes IP -> Customer-level suspension remains
// --------------------------------------------------------------------------
runTest(8, "Suspended customer changing IP address remains strictly blocked on backend", function () use ($app, $testProduct) {
    $suspendedCustomer = User::create([
        'name' => 'IP Shifting Customer',
        'email' => 'ip_shift_' . uniqid() . '@example.com',
        'password' => Hash::make('password123'),
        'role' => 'customer',
        'status' => 'suspended',
    ]);

    // Attempt from a brand new, clean IP
    $newCleanIp = '198.51.100.88';
    $server = ['REMOTE_ADDR' => $newCleanIp, 'HTTP_HOST' => 'localhost:8000', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    $payload = [
        'customer_name' => $suspendedCustomer->name,
        'customer_email' => $suspendedCustomer->email,
        'shipping_address' => ['address_line1' => '500 Clean IP Way', 'city' => 'Denver', 'postal_code' => '80201', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ];

    $req = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($payload));
    $app->instance('request', $req);
    $res = $app->handle($req);

    return $res->getStatusCode() === 403 ? true : "Expected 403, got " . $res->getStatusCode();
});

// --------------------------------------------------------------------------
// TEST 9: Two legitimate customers share an IP -> Isolated accounts
// --------------------------------------------------------------------------
runTest(9, "Two legitimate customers sharing a public IP are isolated and not merged", function () use ($app, $testProduct) {
    $sharedIp = '198.51.100.99';
    $server = ['REMOTE_ADDR' => $sharedIp, 'HTTP_HOST' => 'localhost:8000', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    // Customer A order
    $emailA = 'tenant_a_' . uniqid() . '@example.com';
    $reqA = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode([
        'customer_name' => 'Tenant A',
        'customer_email' => $emailA,
        'shipping_address' => ['address_line1' => '1 Dormitory Hall', 'city' => 'Boston', 'postal_code' => '02115', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ]));
    $app->instance('request', $reqA);
    $resA = $app->handle($reqA);

    // Customer B order
    $emailB = 'tenant_b_' . uniqid() . '@example.com';
    $reqB = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode([
        'customer_name' => 'Tenant B',
        'customer_email' => $emailB,
        'shipping_address' => ['address_line1' => '2 Dormitory Hall', 'city' => 'Boston', 'postal_code' => '02115', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ]));
    $app->instance('request', $reqB);
    $resB = $app->handle($reqB);

    $userA = User::where('email', $emailA)->first();
    $userB = User::where('email', $emailB)->first();

    if (!$userA || !$userB) return "Failed to create customer records";
    if ($userA->id === $userB->id) return "Error: Two customers sharing IP were incorrectly merged";

    return true;
});

// --------------------------------------------------------------------------
// TEST 10: Repeated cancellations -> Risk engine flags high risk
// --------------------------------------------------------------------------
runTest(10, "Repeated cancellations trigger risk scoring and high-risk flags", function () use ($testProduct) {
    $abusiveCustomer = User::create([
        'name' => 'Cancellation Spammer',
        'email' => 'cancel_spammer_' . uniqid() . '@example.com',
        'password' => Hash::make('password123'),
        'role' => 'customer',
        'customer_type' => 'registered',
        'status' => 'active',
    ]);

    // Create 4 cancelled orders in the last 2 hours
    for ($i = 0; $i < 4; $i++) {
        $order = Order::create([
            'user_id' => $abusiveCustomer->id,
            'order_number' => 'ORD-CANC-' . Str::random(6),
            'customer_name' => $abusiveCustomer->name,
            'customer_email' => $abusiveCustomer->email,
            'shipping_address' => ['address_line1' => '100 Test Way', 'city' => 'LA', 'postal_code' => '90001', 'country' => 'USA'],
            'subtotal' => 100,
            'tax_amount' => 8,
            'shipping_amount' => 15,
            'total_amount' => 123,
            'payment_status' => 'paid',
            'payment_method' => 'credit_card',
            'order_status' => 'cancelled',
            'ip_address' => '198.51.100.100',
            'notes' => 'Customer requested cancellation immediately',
            'created_at' => now()->subMinutes(30 * ($i + 1)),
        ]);
        $order->items()->create([
            'product_id' => $testProduct->id,
            'product_name' => $testProduct->name,
            'unit_price' => 100,
            'quantity' => 1,
            'total_price' => 100,
        ]);
    }

    $riskData = CustomerRiskService::calculateCustomerRisk($abusiveCustomer);
    $riskScore = $riskData['risk']['score'];
    $riskLevel = $riskData['risk']['level'];
    $reasons = $riskData['risk']['reasons'];

    if ($riskScore < 50) return "Expected risk score >= 50, got {$riskScore}";
    if (!in_array($riskLevel, ['high', 'critical'])) return "Expected high or critical risk level, got {$riskLevel}";
    if (empty($reasons)) return "Expected risk reasons array to be populated";

    return true;
});

// --------------------------------------------------------------------------
// TEST 11: Unauthorized staff member attempts to block an IP -> 403 Forbidden
// --------------------------------------------------------------------------
runTest(11, "Staff member lacking 'security.ip_block' permission is rejected with HTTP 403", function () use ($app) {
    $unauthorizedStaff = User::create([
        'name' => 'Junior Staff',
        'email' => 'junior_staff_' . uniqid() . '@example.com',
        'password' => Hash::make('password123'),
        'role' => 'staff',
        'permissions' => ['products.view'], // Lacks 'security.ip_block'
    ]);

    $token = $unauthorizedStaff->createToken('staff_token')->plainTextToken;
    $server = ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    $req = Request::create('/api/admin/blocked-ips', 'POST', [], [], [], $server, json_encode([
        'ip_address' => '198.51.100.111',
        'reason' => 'Unauthorized block attempt',
        'duration' => 'permanent',
    ]));
    $app->instance('request', $req);
    $res = $app->handle($req);

    return $res->getStatusCode() === 403 ? true : "Expected 403 Forbidden, got " . $res->getStatusCode();
});

// --------------------------------------------------------------------------
// TEST 12: Unauthorized staff member attempts to suspend customer -> 403 Forbidden
// --------------------------------------------------------------------------
runTest(12, "Staff member lacking 'customers.suspend' permission is rejected with HTTP 403", function () use ($app) {
    $unauthorizedStaff = User::create([
        'name' => 'Read-Only Staff',
        'email' => 'readonly_staff_' . uniqid() . '@example.com',
        'password' => Hash::make('password123'),
        'role' => 'staff',
        'permissions' => ['customers.view'], // Lacks 'customers.suspend' and 'customers.manage'
    ]);

    $targetCustomer = User::where('role', 'customer')->first();

    $token = $unauthorizedStaff->createToken('staff_token')->plainTextToken;
    $server = ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    $req = Request::create("/api/admin/customers/{$targetCustomer->id}/suspend", 'POST', [], [], [], $server, json_encode([
        'duration' => '1_day',
        'reason' => 'Unauthorized suspension attempt',
    ]));
    $app->instance('request', $req);
    $res = $app->handle($req);

    return $res->getStatusCode() === 403 ? true : "Expected 403 Forbidden, got " . $res->getStatusCode();
});

// --------------------------------------------------------------------------
// TEST 13: Expired IP block -> IP becomes usable again
// --------------------------------------------------------------------------
runTest(13, "Expired temporary IP block automatically permits order operations again", function () use ($app, $testProduct) {
    $expiredIp = '198.51.100.133';
    // Create an IP block that expired 1 hour ago
    BlockedIp::updateOrCreate(
        ['ip_address' => $expiredIp],
        [
            'status' => 'active',
            'reason' => 'Past temporary block',
            'expires_at' => now()->subHour(),
        ]
    );

    $server = ['REMOTE_ADDR' => $expiredIp, 'HTTP_HOST' => 'localhost:8000', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    $payload = [
        'customer_name' => 'Post Expiry Customer',
        'customer_email' => 'post_expiry_' . uniqid() . '@example.com',
        'shipping_address' => ['address_line1' => '77 Freedom Way', 'city' => 'Austin', 'postal_code' => '78701', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ];

    $req = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($payload));
    $app->instance('request', $req);
    $res = $app->handle($req);

    return $res->getStatusCode() === 201 ? true : "Expected 201, got " . $res->getStatusCode();
});

// --------------------------------------------------------------------------
// TEST 14: Admin unblocks IP -> Previously blocked IP can place orders again
// --------------------------------------------------------------------------
runTest(14, "Admin unblocking an active IP restores immediate order placement capability", function () use ($app, $testProduct) {
    $ipToUnblock = '198.51.100.144';
    CustomerRiskService::blockIp($ipToUnblock, 'Temporary dispute', null, null, 'permanent');

    // Verify it is blocked
    if (!CustomerRiskService::isIpBlocked($ipToUnblock)) return "Initial block failed";

    // Unblock IP
    CustomerRiskService::unblockIp($ipToUnblock, null, 'Dispute resolved with customer');

    if (CustomerRiskService::isIpBlocked($ipToUnblock)) return "IP is still reporting blocked after unblock call";

    $server = ['REMOTE_ADDR' => $ipToUnblock, 'HTTP_HOST' => 'localhost:8000', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    $payload = [
        'customer_name' => 'Restored Customer',
        'customer_email' => 'restored_' . uniqid() . '@example.com',
        'shipping_address' => ['address_line1' => '88 Restored Blvd', 'city' => 'Phoenix', 'postal_code' => '85001', 'country' => 'USA'],
        'payment_method' => 'credit_card',
        'items' => [['product_id' => $testProduct->id, 'quantity' => 1]],
    ];

    $req = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($payload));
    $app->instance('request', $req);
    $res = $app->handle($req);

    return $res->getStatusCode() === 201 ? true : "Expected 201, got " . $res->getStatusCode();
});

echo "\n-----------------------------------------------------------\n";
echo "SUMMARY: {$passCount} / {$totalTests} Customer Risk & Abuse Tests Passed!\n";
echo "-----------------------------------------------------------\n\n";

if ($passCount === $totalTests) {
    exit(0);
} else {
    exit(1);
}
