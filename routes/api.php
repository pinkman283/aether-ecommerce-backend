<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminAuditLogController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminBlockedIpController;
use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminCouponController;
use App\Http\Controllers\Api\AdminCustomerController;
use App\Http\Controllers\Api\AdminExpenseController;
use App\Http\Controllers\Api\AdminFinanceController;
use App\Http\Controllers\Api\AdminGoodsReceiptController;
use App\Http\Controllers\Api\AdminInventoryController;
use App\Http\Controllers\Api\AdminInventoryMovementController;
use App\Http\Controllers\Api\AdminInventoryValuationController;
use App\Http\Controllers\Api\AdminLeadController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminPosController;
use App\Http\Controllers\Api\AdminPosRegisterController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AdminPurchaseOrderController;
use App\Http\Controllers\Api\AdminReviewController;
use App\Http\Controllers\Api\AdminSalesController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AdminStaffController;
use App\Http\Controllers\Api\AdminVendorController;
use App\Http\Controllers\Api\AdminVendorProductController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\LeadCaptureController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// 1. PUBLIC STOREFRONT ENDPOINTS
// ==========================================
Route::get('/featured', [ProductController::class, 'featured']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{slug}', [CategoryController::class, 'show']);
Route::post('/coupons/validate', [CouponController::class, 'validateCoupon'])->middleware('throttle:coupon-validation');
Route::get('/orders/track/{orderNumber}', [OrderController::class, 'track']);
Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:order-checkout'); // Guest / Customer Checkout
Route::post('/leads/capture', [LeadCaptureController::class, 'capture'])->middleware('throttle:leads-capture'); // Storefront Checkout Abandonment Capture

// ==========================================
// 2. CUSTOMER AUTHENTICATION
// ==========================================
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-customer-login');

// Customer Authenticated Routes (Strictly require customer:access token ability)
Route::middleware(['auth:sanctum', 'ability:customer:access'])->group(function () {
    Route::get('/auth/profile', [AuthController::class, 'profile']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Customer Orders & Addresses
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{orderNumber}', [OrderController::class, 'show']);
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);

    // Review submission by customer
    Route::post('/products/{productId}/reviews', [ReviewController::class, 'store'])->middleware('throttle:customer-reviews');
});

// ==========================================
// 3. DEDICATED ADMIN AUTHENTICATION
// ==========================================
// Protected by named composite rate limiter auth-admin-login
Route::middleware('throttle:auth-admin-login')->group(function () {
    Route::post('/admin/auth/login', [AdminAuthController::class, 'login']);
});

// ==========================================
// 4. ENTERPRISE ADMIN SECURE BOUNDARY
// ==========================================
// Protected by Sanctum token authentication, admin:access ability AND EnsureAdmin role verification
Route::middleware(['auth:sanctum', 'ability:admin:access', 'admin'])->prefix('admin')->group(function () {
    // Admin Session
    Route::get('/auth/me', [AdminAuthController::class, 'me']);
    Route::put('/auth/profile', [AdminAuthController::class, 'updateProfile']);
    Route::post('/auth/logout', [AdminAuthController::class, 'logout']);

    // Executive Overview & Analytics
    Route::get('/analytics', [AdminController::class, 'analytics']);

    // Product Management
    Route::get('/products', [AdminProductController::class, 'index']);
    Route::get('/products/{id}', [AdminProductController::class, 'show']);
    Route::post('/products/upload-image', [AdminProductController::class, 'uploadImage']);
    Route::post('/products', [AdminProductController::class, 'store']);
    Route::post('/products/bulk-delete', [AdminProductController::class, 'bulkDestroy']);
    Route::put('/products/{id}', [AdminProductController::class, 'update']);
    Route::delete('/products/{id}', [AdminProductController::class, 'destroy']);

    // Category Management
    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::post('/categories/bulk-delete', [AdminCategoryController::class, 'bulkDestroy']);
    Route::put('/categories/{id}', [AdminCategoryController::class, 'update']);
    Route::delete('/categories/{id}', [AdminCategoryController::class, 'destroy']);

    // Order Lifecycle & Fulfillment
    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/orders/{id}', [AdminOrderController::class, 'show']);
    Route::post('/orders', [AdminOrderController::class, 'store']);
    Route::post('/orders/bulk-delete', [AdminOrderController::class, 'bulkDestroy']);
    Route::put('/orders/{id}', [AdminOrderController::class, 'update']);
    Route::delete('/orders/{id}', [AdminOrderController::class, 'destroy']);
    Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
    Route::post('/orders/{id}/refund', [AdminOrderController::class, 'refund'])->middleware('throttle:sensitive-admin-action');

    // Commercial Sales History & Invoices
    Route::get('/sales', [AdminSalesController::class, 'index']);
    Route::get('/sales/invoice/{id}', [AdminSalesController::class, 'invoice']);
    Route::get('/sales/export', [AdminSalesController::class, 'export']);

    // Lead Management & Abandoned Checkout Recovery
    Route::get('/leads', [AdminLeadController::class, 'index']);
    Route::get('/leads/{id}', [AdminLeadController::class, 'show']);
    Route::post('/leads/bulk-delete', [AdminLeadController::class, 'bulkDestroy']);
    Route::put('/leads/{id}', [AdminLeadController::class, 'update']);
    Route::delete('/leads/{id}', [AdminLeadController::class, 'destroy']);
    Route::post('/leads/{id}/convert-to-order', [AdminLeadController::class, 'convertToOrder']);

    // Customer Management & Risk Intelligence
    Route::get('/customers', [AdminCustomerController::class, 'index']);
    Route::get('/customers/{id}', [AdminCustomerController::class, 'show']);
    Route::get('/customers/{id}/timeline', [AdminCustomerController::class, 'timeline']);
    Route::get('/customers/{id}/ip-history', [AdminCustomerController::class, 'ipHistory']);
    Route::post('/customers', [AdminCustomerController::class, 'store']);
    Route::post('/customers/bulk-delete', [AdminCustomerController::class, 'bulkDestroy']);
    Route::put('/customers/{id}', [AdminCustomerController::class, 'update']);
    Route::delete('/customers/{id}', [AdminCustomerController::class, 'destroy']);
    Route::patch('/customers/{id}/status', [AdminCustomerController::class, 'toggleStatus']);
    Route::post('/customers/{id}/suspend', [AdminCustomerController::class, 'suspend']);
    Route::post('/customers/{id}/reactivate', [AdminCustomerController::class, 'reactivate']);
    Route::post('/customers/{id}/block', [AdminCustomerController::class, 'block']);
    Route::post('/customers/{id}/unblock', [AdminCustomerController::class, 'unblock']);
    Route::post('/customers/{id}/review', [AdminCustomerController::class, 'setReview']);
    Route::put('/customers/{id}/notes', [AdminCustomerController::class, 'updateNotes']);

    // Security & IP Abuse Registry
    Route::get('/blocked-ips', [AdminBlockedIpController::class, 'index']);
    Route::get('/blocked-ips/{id}', [AdminBlockedIpController::class, 'show']);
    Route::get('/blocked-ips/{id}/related', [AdminBlockedIpController::class, 'relatedEntities']);
    Route::post('/blocked-ips', [AdminBlockedIpController::class, 'store'])->middleware('throttle:sensitive-admin-action');
    Route::delete('/blocked-ips/{id}', [AdminBlockedIpController::class, 'destroy'])->middleware('throttle:sensitive-admin-action');

    // Coupons & Discounts
    Route::get('/coupons', [AdminCouponController::class, 'index']);
    Route::post('/coupons', [AdminCouponController::class, 'store']);
    Route::post('/coupons/bulk-delete', [AdminCouponController::class, 'bulkDestroy']);
    Route::put('/coupons/{id}', [AdminCouponController::class, 'update']);
    Route::delete('/coupons/{id}', [AdminCouponController::class, 'destroy']);

    // Real-Time Inventory & Stock Adjustments
    Route::get('/inventory', [AdminInventoryController::class, 'index']);
    Route::post('/inventory/{id}/adjust', [AdminInventoryController::class, 'adjustStock']);

    // Inventory Costing, Valuation & Auditable Movement Ledger
    Route::get('/inventory-valuation', [AdminInventoryValuationController::class, 'index']);
    Route::post('/inventory-valuation/adjust', [AdminInventoryValuationController::class, 'adjust']);
    Route::get('/inventory-ledger', [AdminInventoryMovementController::class, 'index']);

    // Vendor Management & Procurement
    Route::get('/vendors', [AdminVendorController::class, 'index']);
    Route::get('/vendors/{id}', [AdminVendorController::class, 'show']);
    Route::post('/vendors', [AdminVendorController::class, 'store']);
    Route::put('/vendors/{id}', [AdminVendorController::class, 'update']);
    Route::delete('/vendors/{id}', [AdminVendorController::class, 'destroy']);

    Route::get('/vendor-products', [AdminVendorProductController::class, 'index']);
    Route::post('/vendor-products', [AdminVendorProductController::class, 'store']);
    Route::put('/vendor-products/{id}', [AdminVendorProductController::class, 'update']);
    Route::delete('/vendor-products/{id}', [AdminVendorProductController::class, 'destroy']);

    // Purchase Orders & Procurement Flow
    Route::get('/purchase-orders', [AdminPurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{id}', [AdminPurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders', [AdminPurchaseOrderController::class, 'store']);
    Route::post('/purchase-orders/{id}/submit', [AdminPurchaseOrderController::class, 'submit']);
    Route::post('/purchase-orders/{id}/approve', [AdminPurchaseOrderController::class, 'approve']);
    Route::post('/purchase-orders/{id}/cancel', [AdminPurchaseOrderController::class, 'cancel']);

    // Goods Receipts (GRN) & Physical Receiving
    Route::get('/goods-receipts', [AdminGoodsReceiptController::class, 'index']);
    Route::get('/goods-receipts/{id}', [AdminGoodsReceiptController::class, 'show']);
    Route::post('/goods-receipts', [AdminGoodsReceiptController::class, 'store']);

    // POS (Point of Sale) & Register Sessions
    Route::get('/pos/registers', [AdminPosRegisterController::class, 'index']);
    Route::get('/pos/registers/current-session', [AdminPosRegisterController::class, 'currentSession']);
    Route::post('/pos/registers/{id}/open-session', [AdminPosRegisterController::class, 'openSession']);
    Route::post('/pos/registers/{id}/close-session', [AdminPosRegisterController::class, 'closeSession']);
    Route::post('/pos/registers/{id}/cash-movement', [AdminPosRegisterController::class, 'cashMovement']);

    Route::get('/pos/products', [AdminPosController::class, 'products']);
    Route::post('/pos/checkout', [AdminPosController::class, 'checkout']);

    // Operating Expenses & Categories
    Route::get('/expenses', [AdminExpenseController::class, 'index']);
    Route::post('/expenses', [AdminExpenseController::class, 'store']);
    Route::put('/expenses/{id}', [AdminExpenseController::class, 'update']);
    Route::delete('/expenses/{id}', [AdminExpenseController::class, 'destroy']);
    Route::get('/expense-categories', [AdminExpenseController::class, 'categories']);
    Route::post('/expense-categories', [AdminExpenseController::class, 'storeCategory']);

    // Financial Engine, P&L, Profitability & Drill-Downs
    Route::get('/finance/summary', [AdminFinanceController::class, 'summary']);
    Route::get('/finance/product-profitability', [AdminFinanceController::class, 'productProfitability']);
    Route::get('/finance/vendor-analytics', [AdminFinanceController::class, 'vendorAnalytics']);
    Route::get('/finance/drilldown', [AdminFinanceController::class, 'drilldown']);
    Route::get('/finance/export', [AdminFinanceController::class, 'export']);

    // Review Moderation Queue
    Route::get('/reviews', [AdminReviewController::class, 'index']);
    Route::post('/reviews/bulk-delete', [AdminReviewController::class, 'bulkDestroy']);
    Route::patch('/reviews/{id}/approval', [AdminReviewController::class, 'toggleApproval']);
    Route::delete('/reviews/{id}', [AdminReviewController::class, 'destroy']);

    // Staff & Access Control (Super Admin / Admin)
    Route::get('/staff', [AdminStaffController::class, 'index']);
    Route::post('/staff', [AdminStaffController::class, 'store']);
    Route::post('/staff/bulk-delete', [AdminStaffController::class, 'bulkDestroy']);
    Route::put('/staff/{id}', [AdminStaffController::class, 'update']);
    Route::delete('/staff/{id}', [AdminStaffController::class, 'destroy']);
    Route::post('/staff/{id}/suspend', [AdminStaffController::class, 'suspend']);
    Route::post('/staff/{id}/reactivate', [AdminStaffController::class, 'reactivate']);
    Route::post('/staff/{id}/promote', [AdminStaffController::class, 'promote'])->middleware('throttle:sensitive-admin-action');
    Route::post('/staff/{id}/demote', [AdminStaffController::class, 'demote'])->middleware('throttle:sensitive-admin-action');

    // Audit Trail & Logging
    Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);
    Route::post('/audit-logs/bulk-delete', [AdminAuditLogController::class, 'bulkDestroy'])->middleware('throttle:sensitive-admin-action');

    // System Settings & Defaults
    Route::get('/settings', [AdminSettingsController::class, 'index']);
    Route::put('/settings', [AdminSettingsController::class, 'update']);
});
