<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAuditLogController extends Controller
{
    /**
     * Get paginated audit logs with multi-dimensional filtering, stats, and facet metadata.
     */
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'audit_logs.view');

        $query = AuditLog::with('user:id,name,email,role,avatar')->latest('created_at');

        // 1. Search Query
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('user_name', 'like', "%{$search}%")
                  ->orWhere('action', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('entity_type', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%");
            });
        }

        // 2. Action Module Filter (e.g. 'pos', 'auth', 'order', 'procurement', 'inventory', 'expense', 'lead', 'product')
        if ($request->filled('module') && $request->input('module') !== 'all') {
            $module = $request->input('module');
            $query->where('action', 'like', "{$module}.%");
        }

        // 3. Exact Action Filter
        if ($request->filled('action') && $request->input('action') !== 'all') {
            $query->where('action', $request->input('action'));
        }

        // 4. Entity Type Filter (Model)
        if ($request->filled('entity_type') && $request->input('entity_type') !== 'all') {
            $query->where('entity_type', $request->input('entity_type'));
        }

        // 5. Actor Role Filter
        if ($request->filled('user_role') && $request->input('user_role') !== 'all') {
            $query->where('user_role', $request->input('user_role'));
        }

        // 6. Specific Actor Filter
        if ($request->filled('user_id') && $request->input('user_id') !== 'all') {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        // 7. Date Range Filter
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // Compute Overview KPIs on the base dataset
        $totalLogsCount = AuditLog::count();
        $todayLogsCount = AuditLog::whereDate('created_at', now()->toDateString())->count();
        $authEventsCount = AuditLog::where('action', 'like', 'auth.%')->count();
        $financialOpsCount = AuditLog::where(function ($q) {
            $q->where('action', 'like', 'pos.%')
              ->orWhere('action', 'like', 'order.%')
              ->orWhere('action', 'like', 'expense.%')
              ->orWhere('action', 'like', 'purchase_order.%')
              ->orWhere('action', 'like', 'inventory.%');
        })->count();

        // Get Distinct Entity Types & Available Modules for dropdowns
        $availableEntityTypes = AuditLog::whereNotNull('entity_type')
            ->distinct()
            ->pluck('entity_type')
            ->filter()
            ->values();

        $availableActors = User::whereIn('role', ['super_admin', 'admin', 'staff'])
            ->select('id', 'name', 'email', 'role')
            ->orderBy('name')
            ->get();

        $perPage = (int) $request->input('per_page', 20);
        $logs = $query->paginate($perPage);

        return response()->json([
            'logs' => $logs,
            'stats' => [
                'total_logs' => $totalLogsCount,
                'today_logs' => $todayLogsCount,
                'auth_events' => $authEventsCount,
                'financial_ops' => $financialOpsCount,
            ],
            'facets' => [
                'entity_types' => $availableEntityTypes,
                'actors' => $availableActors,
                'modules' => [
                    ['id' => 'all', 'label' => 'All System Modules'],
                    ['id' => 'auth', 'label' => 'Authentication & Access'],
                    ['id' => 'pos', 'label' => 'POS Terminal Operations'],
                    ['id' => 'order', 'label' => 'Orders & Fulfillment'],
                    ['id' => 'lead', 'label' => 'Checkout Leads & Recovery'],
                    ['id' => 'customer', 'label' => 'Customer Relations'],
                    ['id' => 'inventory', 'label' => 'Inventory & Cost Layers'],
                    ['id' => 'purchase_order', 'label' => 'Procurement & POs'],
                    ['id' => 'goods_receipt', 'label' => 'Goods Receiving (GRN)'],
                    ['id' => 'vendor', 'label' => 'Vendors & Suppliers'],
                    ['id' => 'expense', 'label' => 'Operating Expenses'],
                    ['id' => 'product', 'label' => 'Products & Catalog'],
                    ['id' => 'category', 'label' => 'Categories'],
                    ['id' => 'coupon', 'label' => 'Coupons & Discounts'],
                    ['id' => 'staff', 'label' => 'Staff RBAC & Roles'],
                    ['id' => 'settings', 'label' => 'System Settings'],
                ]
            ]
        ]);
    }

    /**
     * Purge selected audit logs (Super Admin only).
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:audit_logs,id',
        ]);

        $deletedCount = AuditLog::whereIn('id', $validated['ids'])->delete();

        return response()->json([
            'message' => "Successfully purged {$deletedCount} audit log record(s).",
            'deleted_count' => $deletedCount,
        ]);
    }
}
