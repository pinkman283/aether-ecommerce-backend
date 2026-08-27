<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\CustomerRiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'customers.view', 'customers.manage');

        $query = User::where('role', 'customer')
            ->withCount('orders')
            ->withSum(['orders as total_spent' => function ($q) {
                $q->where('payment_status', 'paid');
            }], 'total_amount')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('customer_type') && $request->input('customer_type') !== 'all') {
            $query->where('customer_type', $request->input('customer_type'));
        }

        if ($request->filled('risk_level') && $request->input('risk_level') !== 'all') {
            $query->where('risk_level', $request->input('risk_level'));
        }

        $perPage = (int) $request->input('per_page', 25);
        $customers = $query->paginate($perPage);

        return response()->json($customers);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.view', 'customers.manage');

        $customer = User::where('role', 'customer')
            ->with([
                'addresses',
                'orders' => function ($q) {
                    $q->latest()->with('items');
                }
            ])
            ->withCount('orders')
            ->withSum(['orders as total_spent' => function ($q) {
                $q->where('payment_status', 'paid');
            }], 'total_amount')
            ->findOrFail($id);

        // Compute Live Risk Intelligence & Multi-IP History
        $riskData = CustomerRiskService::calculateCustomerRisk($customer);
        $ipHistory = CustomerRiskService::getCustomerIpHistory($customer);
        $timeline = CustomerRiskService::getCustomerActivityTimeline($customer);

        $response = $customer->toArray();
        $response['risk_analysis'] = $riskData['risk'];
        $response['risk_metrics'] = $riskData['metrics'];
        $response['ip_history'] = $ipHistory;
        $response['activity_timeline'] = $timeline;

        return response()->json($response);
    }

    public function timeline(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.view', 'customers.manage');
        $customer = User::where('role', 'customer')->findOrFail($id);

        $timeline = CustomerRiskService::getCustomerActivityTimeline($customer);
        return response()->json($timeline);
    }

    public function ipHistory(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.view', 'customers.manage');
        $customer = User::where('role', 'customer')->findOrFail($id);

        $ipHistory = CustomerRiskService::getCustomerIpHistory($customer);
        return response()->json($ipHistory);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'customers.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|string',
            'customer_type' => 'nullable|in:registered,guest',
            'status' => 'required|in:active,suspended,blocked,review',
            'internal_notes' => 'nullable|string',
            'address_line1' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'postal_code' => 'nullable|string',
            'country' => 'nullable|string',
        ]);

        $customer = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'customer',
            'customer_type' => $validated['customer_type'] ?? 'registered',
            'phone' => $validated['phone'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'internal_notes' => $validated['internal_notes'] ?? null,
            'avatar' => $validated['avatar'] ?? 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=200&q=80',
        ]);

        if (!empty($validated['address_line1'])) {
            Address::create([
                'user_id' => $customer->id,
                'type' => 'shipping',
                'full_name' => $customer->name,
                'phone' => $customer->phone,
                'address_line1' => $validated['address_line1'],
                'city' => $validated['city'] ?? 'San Francisco',
                'state' => $validated['state'] ?? 'CA',
                'postal_code' => $validated['postal_code'] ?? '94107',
                'country' => $validated['country'] ?? 'United States',
                'is_default' => true,
            ]);
        }

        AuditLog::log(
            $request->user(),
            'customer.created',
            'User',
            $customer->id,
            "Created customer account '{$customer->name}' ({$customer->email}).",
            null,
            $customer->toArray()
        );

        return response()->json([
            'message' => "Customer '{$customer->name}' created successfully.",
            'customer' => $customer->load('addresses'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.manage');

        $customer = User::where('role', 'customer')->findOrFail($id);
        $oldValues = $customer->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => "sometimes|required|email|max:255|unique:users,email,{$id}",
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|string',
            'customer_type' => 'nullable|in:registered,guest',
            'status' => 'sometimes|required|in:active,suspended,blocked,review',
            'internal_notes' => 'nullable|string',
            'password' => 'nullable|string|min:6',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $customer->update($validated);

        AuditLog::log(
            $request->user(),
            'customer.updated',
            'User',
            $customer->id,
            "Updated customer profile for '{$customer->name}' ({$customer->email}).",
            $oldValues,
            $customer->toArray()
        );

        return response()->json([
            'message' => "Customer '{$customer->name}' updated successfully.",
            'customer' => $customer,
        ]);
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.suspend', 'customers.manage');

        $customer = User::where('role', 'customer')->findOrFail($id);
        $oldValues = $customer->toArray();

        $validated = $request->validate([
            'duration' => 'required|in:1_day,3_days,7_days,30_days,indefinite,custom',
            'suspended_until' => 'nullable|required_if:duration,custom|date|after:now',
            'reason' => 'nullable|string|max:1000',
        ]);

        $suspendedUntil = null;
        switch ($validated['duration']) {
            case '1_day':
                $suspendedUntil = now()->addDay();
                break;
            case '3_days':
                $suspendedUntil = now()->addDays(3);
                break;
            case '7_days':
                $suspendedUntil = now()->addDays(7);
                break;
            case '30_days':
                $suspendedUntil = now()->addDays(30);
                break;
            case 'custom':
                if (!empty($validated['suspended_until'])) {
                    $suspendedUntil = \Carbon\Carbon::parse($validated['suspended_until']);
                }
                break;
            case 'indefinite':
            default:
                $suspendedUntil = null;
                break;
        }

        $customer->update([
            'status' => 'suspended',
            'suspended_until' => $suspendedUntil,
            'suspension_reason' => $validated['reason'] ?? null,
        ]);

        // Revoke active customer sessions
        $customer->tokens()->delete();

        $durationLabel = $suspendedUntil ? "until {$suspendedUntil->format('M d, Y H:i T')}" : "indefinitely";
        $reasonLabel = !empty($validated['reason']) ? " (Reason: {$validated['reason']})" : "";

        AuditLog::log(
            $request->user(),
            'customer.suspended',
            'User',
            $customer->id,
            "Suspended customer '{$customer->name}' {$durationLabel}{$reasonLabel}.",
            $oldValues,
            $customer->toArray()
        );

        return response()->json([
            'message' => "Customer '{$customer->name}' suspended {$durationLabel}.",
            'customer' => $customer,
        ]);
    }

    public function block(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.block', 'customers.manage');

        $customer = User::where('role', 'customer')->findOrFail($id);
        $oldValues = $customer->toArray();

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $customer->update([
            'status' => 'blocked',
            'suspension_reason' => $validated['reason'],
            'suspended_until' => null,
        ]);

        $customer->tokens()->delete();

        AuditLog::log(
            $request->user(),
            'customer.blocked',
            'User',
            $customer->id,
            "Blocked customer '{$customer->name}' ({$customer->email}). Reason: {$validated['reason']}",
            $oldValues,
            $customer->toArray()
        );

        CustomerRiskService::calculateCustomerRisk($customer);

        return response()->json([
            'message' => "Customer '{$customer->name}' has been blocked.",
            'customer' => $customer,
        ]);
    }

    public function unblock(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.block', 'customers.manage');

        $customer = User::where('role', 'customer')->findOrFail($id);
        $oldValues = $customer->toArray();

        $customer->update([
            'status' => 'active',
            'suspension_reason' => null,
            'suspended_until' => null,
        ]);

        AuditLog::log(
            $request->user(),
            'customer.unblocked',
            'User',
            $customer->id,
            "Unblocked customer '{$customer->name}' ({$customer->email}).",
            $oldValues,
            $customer->toArray()
        );

        CustomerRiskService::calculateCustomerRisk($customer);

        return response()->json([
            'message' => "Customer '{$customer->name}' is now unblocked and active.",
            'customer' => $customer,
        ]);
    }

    public function setReview(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.manage');

        $customer = User::where('role', 'customer')->findOrFail($id);
        $customer->update(['status' => 'review']);

        AuditLog::log(
            $request->user(),
            'customer.review_flagged',
            'User',
            $customer->id,
            "Flagged customer '{$customer->name}' for security/risk review."
        );

        return response()->json([
            'message' => "Customer '{$customer->name}' marked as under review.",
            'customer' => $customer,
        ]);
    }

    public function reactivate(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.suspend', 'customers.manage');

        $customer = User::where('role', 'customer')->findOrFail($id);
        $oldValues = $customer->toArray();

        $customer->update([
            'status' => 'active',
            'suspended_until' => null,
            'suspension_reason' => null,
        ]);

        AuditLog::log(
            $request->user(),
            'customer.reactivated',
            'User',
            $customer->id,
            "Reactivated customer account '{$customer->name}'.",
            $oldValues,
            $customer->toArray()
        );

        return response()->json([
            'message' => "Customer '{$customer->name}' account reactivated to Active.",
            'customer' => $customer,
        ]);
    }

    public function updateNotes(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.manage');

        $customer = User::where('role', 'customer')->findOrFail($id);
        $validated = $request->validate([
            'internal_notes' => 'nullable|string|max:5000',
        ]);

        $customer->update([
            'internal_notes' => $validated['internal_notes'],
        ]);

        AuditLog::log(
            $request->user(),
            'customer.notes_updated',
            'User',
            $customer->id,
            "Updated internal security notes for customer '{$customer->name}'."
        );

        return response()->json([
            'message' => "Internal notes updated successfully.",
            'customer' => $customer,
        ]);
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.manage');

        $customer = User::where('role', 'customer')->findOrFail($id);
        $newStatus = $customer->status === 'active' ? 'suspended' : 'active';

        $customer->update([
            'status' => $newStatus,
            'suspended_until' => null,
            'suspension_reason' => null,
        ]);

        if ($newStatus === 'suspended') {
            $customer->tokens()->delete();
        }

        AuditLog::log(
            $request->user(),
            'customer.status_toggled',
            'User',
            $customer->id,
            "Customer {$customer->name} ({$customer->email}) status changed to '{$newStatus}'."
        );

        return response()->json([
            'message' => "Customer status changed to {$newStatus}.",
            'customer' => $customer,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'customers.manage');

        $customer = User::where('role', 'customer')->findOrFail($id);
        $name = $customer->name;
        $email = $customer->email;
        $oldValues = $customer->toArray();

        $customer->tokens()->delete();
        $customer->addresses()->delete();
        $customer->delete();

        AuditLog::log(
            $request->user(),
            'customer.deleted',
            'User',
            $id,
            "Deleted customer account '{$name}' ({$email}).",
            $oldValues,
            null
        );

        return response()->json([
            'message' => "Customer '{$name}' deleted successfully.",
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'customers.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:users,id',
        ]);

        $count = 0;

        foreach ($validated['ids'] as $id) {
            $customer = User::where('role', 'customer')->find($id);
            if ($customer) {
                $name = $customer->name;
                $email = $customer->email;
                $oldValues = $customer->toArray();

                $customer->tokens()->delete();
                $customer->addresses()->delete();
                $customer->delete();
                $count++;

                AuditLog::log(
                    $request->user(),
                    'customer.deleted',
                    'User',
                    $id,
                    "Bulk deleted customer account '{$name}' ({$email}).",
                    $oldValues,
                    null
                );
            }
        }

        return response()->json([
            'message' => "Successfully deleted {$count} customer account(s).",
            'deleted_count' => $count,
        ]);
    }
}
