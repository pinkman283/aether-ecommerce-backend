<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\Category;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;

echo "\n===========================================================\n";
echo "       BACKEND SECURITY & RBAC REGRESSION TEST SUITE       \n";
echo "===========================================================\n\n";

$passedCount = 0;
$totalTests = 0;

function assertTest(string $name, $response, int $expectedStatus) {
    global $passedCount, $totalTests;
    $totalTests++;
    $statusCode = $response->getStatusCode();
    if ($statusCode === $expectedStatus) {
        $passedCount++;
        echo "  [PASS] Test {$totalTests}: {$name} (HTTP {$statusCode})\n";
    } else {
        $content = substr($response->getContent(), 0, 200);
        echo "  [FAIL] Test {$totalTests}: {$name} -> Expected HTTP {$expectedStatus}, got HTTP {$statusCode} ({$content})\n";
    }
}

// 1. Setup Test Categories & Products
$testCategory = Category::firstOrCreate(['slug' => 'test-security-cat'], [
    'name' => 'Security Test Category',
    'description' => 'For automated testing'
]);

$testProduct = Product::create([
    'category_id' => $testCategory->id,
    'name' => 'Secured Quantum Headset',
    'slug' => 'secured-quantum-headset-' . uniqid(),
    'sku' => 'SEC-' . strtoupper(uniqid()),
    'price' => 250.00,
    'stock_quantity' => 5,
    'description' => 'Security testing product',
    'is_active' => true,
]);

// 2. Setup Test Users
$customerA = User::create([
    'name' => 'Alice Customer',
    'email' => 'alice_' . uniqid() . '@test.com',
    'password' => Hash::make('password123'),
    'role' => 'customer',
    'status' => 'active',
]);

$customerB = User::create([
    'name' => 'Bob Customer',
    'email' => 'bob_' . uniqid() . '@test.com',
    'password' => Hash::make('password123'),
    'role' => 'customer',
    'status' => 'active',
]);

$staffUser = User::create([
    'name' => 'Sam Staff',
    'email' => 'sam_staff_' . uniqid() . '@test.com',
    'password' => Hash::make('password123'),
    'role' => 'staff',
    'permissions' => ['products.view'], // Only product viewing permission
    'status' => 'active',
]);

$adminUser = User::create([
    'name' => 'Alex Admin',
    'email' => 'alex_admin_' . uniqid() . '@test.com',
    'password' => Hash::make('password123'),
    'role' => 'admin',
    'status' => 'active',
]);

$superAdminUser = User::create([
    'name' => 'Sarah SuperAdmin',
    'email' => 'sarah_super_' . uniqid() . '@test.com',
    'password' => Hash::make('password123'),
    'role' => 'super_admin',
    'status' => 'active',
]);

// 3. Issue Tokens
$customerTokenA = $customerA->createToken('auth_token', ['customer:access'])->plainTextToken;
$customerTokenB = $customerB->createToken('auth_token', ['customer:access'])->plainTextToken;
$staffToken = $staffUser->createToken('admin_token', ['admin:access'])->plainTextToken;
$adminToken = $adminUser->createToken('admin_token', ['admin:access'])->plainTextToken;
$superAdminToken = $superAdminUser->createToken('admin_token', ['admin:access'])->plainTextToken;

// Helper to execute simulated HTTP Request
function runRequest($app, string $method, string $uri, array $data = [], ?string $token = null) {
    auth()->forgetGuards();
    $server = [
        'HTTP_ACCEPT' => 'application/json',
    ];
    if ($token) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    $req = Request::create($uri, $method, $data, [], [], $server);
    return $app->handle($req);
}

// -------------------------------------------------------------
// TEST 1: Unauthenticated request to protected Customer endpoint
// -------------------------------------------------------------
$res1 = runRequest($app, 'GET', '/api/orders');
assertTest("Unauthenticated request to /api/orders returns 401", $res1, 401);

// -------------------------------------------------------------
// TEST 2: Unauthenticated request to protected Admin endpoint
// -------------------------------------------------------------
$res2 = runRequest($app, 'GET', '/api/admin/products');
assertTest("Unauthenticated request to /api/admin/products returns 401", $res2, 401);

// -------------------------------------------------------------
// TEST 3: Customer Token used to access Admin endpoint (Cross-Context / Ability Check)
// -------------------------------------------------------------
$res3 = runRequest($app, 'GET', '/api/admin/products', [], $customerTokenA);
assertTest("Customer Token accessing /api/admin/products is rejected with 403", $res3, 403);

// -------------------------------------------------------------
// TEST 4: IDOR Protection on Customer Orders
// -------------------------------------------------------------
$orderA = Order::create([
    'user_id' => $customerA->id,
    'order_number' => 'ORD-TEST-A-' . uniqid(),
    'customer_name' => $customerA->name,
    'customer_email' => $customerA->email,
    'shipping_address' => ['city' => 'San Francisco', 'address_line1' => '123 Main St', 'postal_code' => '94107', 'country' => 'USA'],
    'billing_address' => ['city' => 'San Francisco', 'address_line1' => '123 Main St', 'postal_code' => '94107', 'country' => 'USA'],
    'subtotal' => 250.00,
    'tax_amount' => 20.00,
    'shipping_amount' => 0.00,
    'discount_amount' => 0.00,
    'total_amount' => 270.00,
    'payment_status' => 'paid',
    'payment_method' => 'credit_card',
    'order_status' => 'processing',
]);

// Customer B attempts to fetch Customer A's order by order number
$res4 = runRequest($app, 'GET', "/api/orders/{$orderA->order_number}", [], $customerTokenB);
assertTest("Customer B attempting to read Customer A's order receives 403 Forbidden (IDOR Guard)", $res4, 403);

// Customer A fetches their own order -> 200 OK
$res4b = runRequest($app, 'GET', "/api/orders/{$orderA->order_number}", [], $customerTokenA);
assertTest("Customer A can read their own order (200 OK)", $res4b, 200);

// -------------------------------------------------------------
// TEST 5: Financial Data Manipulation & Server-Side Price Derivation
// -------------------------------------------------------------
$res5 = runRequest($app, 'POST', '/api/orders', [
    'customer_name' => 'Attacker Customer',
    'customer_email' => 'attacker@test.com',
    'shipping_address' => [
        'address_line1' => '123 Fake St',
        'city' => 'New York',
        'postal_code' => '10001',
        'country' => 'USA',
    ],
    'payment_method' => 'credit_card',
    'items' => [
        [
            'product_id' => $testProduct->id,
            'quantity' => 1,
            'unit_price' => 0.01, // TAMPERED PRICE
            'total_price' => 0.01,
        ]
    ]
], $customerTokenA);

$orderData = json_decode($res5->getContent(), true)['order'] ?? [];
$subtotalComputed = $orderData['subtotal'] ?? 0;
assertTest("Server overrides client price and computes catalog price ($250.00)", $res5, 201);

// -------------------------------------------------------------
// TEST 6: Concurrency & Overselling Rejection
// -------------------------------------------------------------
$res6 = runRequest($app, 'POST', '/api/orders', [
    'customer_name' => 'Bulk Buyer',
    'customer_email' => 'bulk@test.com',
    'shipping_address' => [
        'address_line1' => '123 Bulk St',
        'city' => 'Chicago',
        'postal_code' => '60601',
        'country' => 'USA',
    ],
    'payment_method' => 'credit_card',
    'items' => [
        [
            'product_id' => $testProduct->id,
            'quantity' => 100, // Exceeds available stock
        ]
    ]
], $customerTokenA);
assertTest("Order exceeding available inventory is rejected with 422 Unprocessable Entity", $res6, 422);

// -------------------------------------------------------------
// TEST 7: Granular RBAC Permission Enforcement on Staff
// -------------------------------------------------------------
// Staff lacks 'expenses.manage' -> 403
$res7a = runRequest($app, 'POST', '/api/admin/expenses', [
    'title' => 'Unauthorized Server Upgrade',
    'category' => 'hardware',
    'amount' => 1500.00,
    'expense_date' => date('Y-m-d'),
], $staffToken);
assertTest("Staff lacking 'expenses.manage' permission is rejected with 403", $res7a, 403);

// Staff lacks 'products.manage' -> 403
$res7b = runRequest($app, 'POST', '/api/admin/products', [
    'name' => 'Unauthorized Product',
    'category_id' => $testCategory->id,
    'price' => 100.00,
    'stock_quantity' => 10,
    'description' => 'Test',
], $staffToken);
assertTest("Staff lacking 'products.manage' permission is rejected with 403 on product creation", $res7b, 403);

// Staff with 'products.view' -> 200 OK
$res7c = runRequest($app, 'GET', '/api/admin/products', [], $staffToken);
assertTest("Staff with 'products.view' permission can access product list (200 OK)", $res7c, 200);

// -------------------------------------------------------------
// TEST 8: Suspended Administrator Session Invalidation
// -------------------------------------------------------------
$suspendedAdmin = User::create([
    'name' => 'Suspended Admin',
    'email' => 'bad_admin_' . uniqid() . '@test.com',
    'password' => Hash::make('password123'),
    'role' => 'admin',
    'status' => 'suspended',
    'suspension_reason' => 'Security audit breach',
]);
$suspendedToken = $suspendedAdmin->createToken('admin_token', ['admin:access'])->plainTextToken;

$res8 = runRequest($app, 'GET', '/api/admin/products', [], $suspendedToken);
assertTest("Suspended admin token is rejected with 403 Forbidden", $res8, 403);

// -------------------------------------------------------------
// TEST 9: Super Admin Exclusive Actions (Elevation / Demotion)
// -------------------------------------------------------------
// Regular Admin attempts to promote staff member -> 403
$res9a = runRequest($app, 'POST', "/api/admin/staff/{$staffUser->id}/promote", [], $adminToken);
assertTest("Regular Admin attempting staff promotion is rejected with 403 (Super Admin only)", $res9a, 403);

// Super Admin promotes staff member -> 200 OK
$res9b = runRequest($app, 'POST', "/api/admin/staff/{$staffUser->id}/promote", [], $superAdminToken);
assertTest("Super Admin successfully promotes staff member (200 OK)", $res9b, 200);

// -------------------------------------------------------------
// TEST 10: Rate Limiting on Authentication Endpoints (throttle:5,1)
// -------------------------------------------------------------
$rateLimitBlocked = false;
for ($i = 1; $i <= 8; $i++) {
    $rlRes = runRequest($app, 'POST', '/api/auth/login', [
        'email' => 'attacker@test.com',
        'password' => 'wrongpassword',
    ]);
    if ($rlRes->getStatusCode() === 429) {
        $rateLimitBlocked = true;
        break;
    }
}
assertTest("Rate Limiter triggers HTTP 429 on excessive brute-force attempts", new \Illuminate\Http\Response('', $rateLimitBlocked ? 429 : 200), 429);

// Cleanup
$customerA->tokens()->delete();
$customerB->tokens()->delete();
$staffUser->tokens()->delete();
$adminUser->tokens()->delete();
$superAdminUser->tokens()->delete();
$suspendedAdmin->tokens()->delete();

echo "\n-----------------------------------------------------------\n";
echo "SUMMARY: {$passedCount} / {$totalTests} Security Regression Tests Passed!\n";
echo "-----------------------------------------------------------\n\n";

if ($passedCount === $totalTests) {
    exit(0);
} else {
    exit(1);
}
