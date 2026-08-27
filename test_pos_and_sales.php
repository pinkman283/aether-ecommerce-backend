<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Product;
use App\Models\PosRegister;
use App\Models\PosRegisterSession;
use App\Http\Controllers\Api\AdminPosController;
use App\Http\Controllers\Api\AdminSalesController;
use Illuminate\Http\Request;

$user = User::where('role', 'super_admin')->first();
$reg = PosRegister::first();
$session = PosRegisterSession::where('pos_register_id', $reg->id)->where('status', 'open')->first();
if (!$session) {
    $session = PosRegisterSession::create([
        'pos_register_id' => $reg->id,
        'user_id' => $user->id,
        'opened_at' => now(),
        'opening_balance' => 100,
        'expected_cash_balance' => 100,
        'status' => 'open'
    ]);
}

$product = Product::first();
$initialStock = $product->stock_quantity;
echo "Initial Stock: {$initialStock}\n";

$req = Request::create('/api/admin/pos/checkout', 'POST', [
    'pos_register_session_id' => $session->id,
    'payment_method' => 'cash',
    'cash_received' => 200,
    'discount_amount' => 0,
    'tax_amount' => 0,
    'items' => [
        [
            'product_id' => $product->id,
            'unit_price' => 50,
            'quantity' => 2,
            'discount_amount' => 0,
        ]
    ]
]);
$req->setUserResolver(fn() => $user);

$posController = new AdminPosController();
$res = $posController->checkout($req);

echo "POS Response Code: " . $res->getStatusCode() . "\n";
echo "POS Response Content: " . $res->getContent() . "\n";

$product->refresh();
echo "Updated Stock: {$product->stock_quantity} (Difference: " . ($initialStock - $product->stock_quantity) . ")\n";

// Test Sales Index
$salesReq = Request::create('/api/admin/sales', 'GET');
$salesReq->setUserResolver(fn() => $user);
$salesController = new AdminSalesController();
$salesRes = $salesController->index($salesReq);
echo "Sales Index Status: " . $salesRes->getStatusCode() . "\n";
echo "Sales Index Summary: " . json_encode(json_decode($salesRes->getContent())->summary) . "\n";
