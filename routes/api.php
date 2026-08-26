<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminAuditLogController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminCouponController;
use App\Http\Controllers\Api\AdminCustomerController;
use App\Http\Controllers\Api\AdminInventoryController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AdminReviewController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AdminStaffController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CouponController;
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
Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);
Route::get('/orders/track/{orderNumber}', [OrderController::class, 'track']);
Route::post('/orders', [OrderController::class, 'store']); // Guest / Customer Checkout

// ==========================================
// 2. CUSTOMER AUTHENTICATION
// ==========================================
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Customer Authenticated Routes
Route::middleware('auth:sanctum')->group(function () {
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
    Route::post('/products/{productId}/reviews', [ReviewController::class, 'store']);
});

// ==========================================
// 3. DEDICATED ADMIN AUTHENTICATION
// ==========================================
// Rate limited to 5 attempts per minute to prevent brute-force attacks
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/admin/auth/login', [AdminAuthController::class, 'login']);
});

// ==========================================
// 4. ENTERPRISE ADMIN SECURE BOUNDARY
// ==========================================
// Protected by Sanctum token authentication AND EnsureAdmin role verification
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Admin Session
    Route::get('/auth/me', [AdminAuthController::class, 'me']);
    Route::put('/auth/profile', [AdminAuthController::class, 'updateProfile']);
    Route::post('/auth/logout', [AdminAuthController::class, 'logout']);

    // Executive Overview & Analytics
    Route::get('/analytics', [AdminController::class, 'analytics']);

    // Product Management
    Route::get('/products', [AdminProductController::class, 'index']);
    Route::get('/products/{id}', [AdminProductController::class, 'show']);
    Route::post('/products', [AdminProductController::class, 'store']);
    Route::put('/products/{id}', [AdminProductController::class, 'update']);
    Route::delete('/products/{id}', [AdminProductController::class, 'destroy']);

    // Category Management
    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::put('/categories/{id}', [AdminCategoryController::class, 'update']);
    Route::delete('/categories/{id}', [AdminCategoryController::class, 'destroy']);

    // Order Lifecycle & Fulfillment
    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/orders/{id}', [AdminOrderController::class, 'show']);
    Route::post('/orders', [AdminOrderController::class, 'store']);
    Route::put('/orders/{id}', [AdminOrderController::class, 'update']);
    Route::delete('/orders/{id}', [AdminOrderController::class, 'destroy']);
    Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
    Route::post('/orders/{id}/refund', [AdminOrderController::class, 'refund']);

    // Customer Management
    Route::get('/customers', [AdminCustomerController::class, 'index']);
    Route::get('/customers/{id}', [AdminCustomerController::class, 'show']);
    Route::post('/customers', [AdminCustomerController::class, 'store']);
    Route::put('/customers/{id}', [AdminCustomerController::class, 'update']);
    Route::delete('/customers/{id}', [AdminCustomerController::class, 'destroy']);
    Route::patch('/customers/{id}/status', [AdminCustomerController::class, 'toggleStatus']);
    Route::post('/customers/{id}/suspend', [AdminCustomerController::class, 'suspend']);
    Route::post('/customers/{id}/reactivate', [AdminCustomerController::class, 'reactivate']);

    // Coupons & Discounts
    Route::get('/coupons', [AdminCouponController::class, 'index']);
    Route::post('/coupons', [AdminCouponController::class, 'store']);
    Route::put('/coupons/{id}', [AdminCouponController::class, 'update']);
    Route::delete('/coupons/{id}', [AdminCouponController::class, 'destroy']);

    // Real-Time Inventory & Stock Adjustments
    Route::get('/inventory', [AdminInventoryController::class, 'index']);
    Route::post('/inventory/{id}/adjust', [AdminInventoryController::class, 'adjustStock']);

    // Review Moderation Queue
    Route::get('/reviews', [AdminReviewController::class, 'index']);
    Route::patch('/reviews/{id}/approval', [AdminReviewController::class, 'toggleApproval']);
    Route::delete('/reviews/{id}', [AdminReviewController::class, 'destroy']);

    // Staff & Access Control (Super Admin / Admin)
    Route::get('/staff', [AdminStaffController::class, 'index']);
    Route::post('/staff', [AdminStaffController::class, 'store']);
    Route::put('/staff/{id}', [AdminStaffController::class, 'update']);
    Route::delete('/staff/{id}', [AdminStaffController::class, 'destroy']);
    Route::post('/staff/{id}/suspend', [AdminStaffController::class, 'suspend']);
    Route::post('/staff/{id}/reactivate', [AdminStaffController::class, 'reactivate']);
    Route::post('/staff/{id}/promote', [AdminStaffController::class, 'promote']);
    Route::post('/staff/{id}/demote', [AdminStaffController::class, 'demote']);

    // Audit Trail & Logging
    Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);

    // System Settings & Defaults
    Route::get('/settings', [AdminSettingsController::class, 'index']);
    Route::put('/settings', [AdminSettingsController::class, 'update']);
});
