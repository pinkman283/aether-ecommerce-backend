<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

echo "===========================================================\n";
echo "           ORDER IP CAPTURE VERIFICATION TEST             \n";
echo "===========================================================\n\n";

$testProduct = Product::first();
if (!$testProduct) {
    echo "No product found to test.\n";
    exit(1);
}

// 1. Test Storefront Customer Checkout with specific simulated IP
$clientIp = '203.0.113.195';
$server = [
    'REMOTE_ADDR' => $clientIp,
    'HTTP_HOST' => 'localhost:8000',
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
];

$orderPayload = [
    'customer_name' => 'IP Telemetry Tester',
    'customer_email' => 'ip_test_' . uniqid() . '@example.com',
    'customer_phone' => '+15550199',
    'shipping_address' => [
        'address_line1' => '100 Cybernetic Way',
        'city' => 'San Francisco',
        'postal_code' => '94107',
        'country' => 'United States',
    ],
    'payment_method' => 'credit_card',
    'items' => [
        [
            'product_id' => $testProduct->id,
            'quantity' => 1,
        ],
    ],
];

$request = Request::create('/api/orders', 'POST', [], [], [], $server, json_encode($orderPayload));
$app->instance('request', $request);
$response = $app->handle($request);

if ($response->getStatusCode() !== 201) {
    echo "\033[31m[FAIL]\033[0m - Expected HTTP 201, got " . $response->getStatusCode() . "\n";
    echo $response->getContent() . "\n";
    exit(1);
}

$data = json_decode($response->getContent(), true);
$orderId = $data['order']['id'] ?? null;
$createdOrder = Order::find($orderId);

if (!$createdOrder) {
    echo "\033[31m[FAIL]\033[0m - Order could not be retrieved from database.\n";
    exit(1);
}

echo "Created Order ID: #{$createdOrder->id} ({$createdOrder->order_number})\n";
echo "Recorded IP Address: " . ($createdOrder->ip_address ?: "NULL") . "\n";

if ($createdOrder->ip_address === $clientIp) {
    echo "\033[32m[PASS]\033[0m - Order successfully captured the user's client IP ({$clientIp})!\n\n";
    
    // Cleanup test order
    $createdOrder->items()->delete();
    $createdOrder->delete();
    exit(0);
} else {
    echo "\033[31m[FAIL]\033[0m - Expected IP {$clientIp}, but found: " . ($createdOrder->ip_address ?: 'null') . "\n\n";
    exit(1);
}
