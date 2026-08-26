<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
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

        $perPage = (int) $request->input('per_page', 25);
        $customers = $query->paginate($perPage);

        return response()->json($customers);
    }

    public function show(int $id): JsonResponse
    {
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

        return response()->json($customer);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|string',
            'status' => 'required|in:active,suspended',
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
            'phone' => $validated['phone'] ?? null,
            'status' => $validated['status'] ?? 'active',
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
        $customer = User::where('role', 'customer')->findOrFail($id);
        $oldValues = $customer->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => "sometimes|required|email|max:255|unique:users,email,{$id}",
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|string',
            'status' => 'sometimes|required|in:active,suspended',
            'password' => 'nullable|string|min:6',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $customer->fill($validated);
        $wasDirty = $customer->isDirty();
        $customer->save();

        if (isset($validated['status']) && $validated['status'] === 'suspended') {
            $customer->tokens()->delete();
        }

        if ($wasDirty) {
            AuditLog::log(
                $request->user(),
                'customer.updated',
                'User',
                $customer->id,
                "Updated customer profile for '{$customer->name}' ({$customer->email}).",
                $oldValues,
                $customer->toArray()
            );
        }

        return response()->json([
            'message' => "Customer '{$customer->name}' updated successfully.",
            'customer' => $customer,
        ]);
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $customer = User::where('role', 'customer')->findOrFail($id);
        $oldValues = $customer->toArray();

        $validated = $request->validate([
            'duration_type' => 'required|in:indefinite,24h,3d,7d,30d,custom',
            'suspended_until' => 'nullable|date',
            'reason' => 'nullable|string|max:255',
        ]);

        $suspendedUntil = null;
        switch ($validated['duration_type']) {
            case '24h':
                $suspendedUntil = now()->addHours(24);
                break;
            case '3d':
                $suspendedUntil = now()->addDays(3);
                break;
            case '7d':
                $suspendedUntil = now()->addDays(7);
                break;
            case '30d':
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

    public function reactivate(Request $request, int $id): JsonResponse
    {
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

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
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
}
