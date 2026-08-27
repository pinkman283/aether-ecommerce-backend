<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminStaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'staff.view', 'staff.manage');

        $staff = User::whereIn('role', ['staff', 'admin', 'super_admin'])
            ->latest()
            ->get([
                'id', 'name', 'email', 'role', 'permissions', 'status', 
                'suspended_until', 'suspension_reason', 'phone', 'avatar', 'created_at'
            ]);

        return response()->json($staff);
    }

    public function store(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        // Only super_admin or admin can create staff
        if (!$currentUser->isSuperAdmin() && !$currentUser->isAdmin()) {
            return response()->json(['message' => 'Unauthorized to create administrative accounts.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:staff,admin,super_admin',
            'permissions' => 'nullable|array',
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|string',
        ]);

        // Only super_admin can create another super_admin
        if ($validated['role'] === 'super_admin' && !$currentUser->isSuperAdmin()) {
            return response()->json(['message' => 'Only Super Administrators can create Super Admin accounts.'], 403);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'permissions' => $validated['permissions'] ?? ($validated['role'] === 'staff' ? ['products.view', 'products.manage', 'orders.view', 'orders.manage', 'inventory.manage'] : []),
            'status' => 'active',
            'phone' => $validated['phone'] ?? null,
            'avatar' => $validated['avatar'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80',
        ]);

        AuditLog::log(
            $currentUser,
            'staff.created',
            'User',
            $user->id,
            "Created {$user->role} account '{$user->name}' ({$user->email}) with " . count($user->permissions ?? []) . " dynamic permissions."
        );

        return response()->json([
            'message' => 'Staff account created successfully',
            'staff' => $user,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        $staff = User::whereIn('role', ['staff', 'admin', 'super_admin'])->findOrFail($id);

        // Super Admin is untouchable for all other users
        if ($staff->isSuperAdmin() && $currentUser->id !== $staff->id) {
            return response()->json(['message' => 'Security Clearance Violation: Super Administrator account is untouchable and permanently protected from external modification.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'role' => 'sometimes|required|in:staff,admin,super_admin',
            'permissions' => 'nullable|array',
            'status' => 'sometimes|required|in:active,suspended',
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|string',
            'password' => 'nullable|string|min:8',
        ]);

        // Role modification clearance check: only Super Admin can promote/demote or assign roles
        if (isset($validated['role']) && $validated['role'] !== $staff->role) {
            if (!$currentUser->isSuperAdmin()) {
                return response()->json(['message' => 'Security Clearance Violation: Only the Super Administrator has authority to change personnel roles.'], 403);
            }
            if ($staff->isSuperAdmin() && $validated['role'] !== 'super_admin') {
                return response()->json(['message' => 'Security Violation: Super Administrator role cannot be modified.'], 403);
            }
        }

        if (isset($validated['role']) && $validated['role'] === 'super_admin' && !$currentUser->isSuperAdmin()) {
            return response()->json(['message' => 'Only Super Administrators can assign the Super Admin role.'], 403);
        }

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $oldValues = $staff->toArray();
        $staff->fill($validated);
        $wasDirty = $staff->isDirty();
        $staff->save();

        if (isset($validated['status']) && $validated['status'] === 'suspended') {
            $staff->tokens()->delete();
        }

        if ($wasDirty) {
            AuditLog::log(
                $currentUser,
                'staff.updated',
                'User',
                $staff->id,
                "Updated administrative permissions and profile for '{$staff->name}' ({$staff->email})",
                $oldValues,
                $staff->toArray()
            );
        }

        return response()->json([
            'message' => 'Staff account updated successfully',
            'staff' => $staff,
        ]);
    }

    public function promote(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();

        if (!$currentUser->isSuperAdmin()) {
            return response()->json([
                'message' => 'Security Clearance Error: Only the Super Administrator has authority to promote personnel to Admin status.'
            ], 403);
        }

        $staff = User::whereIn('role', ['staff', 'admin', 'super_admin'])->findOrFail($id);

        if ($staff->isSuperAdmin()) {
            return response()->json(['message' => 'Super Administrator is already at the highest clearance level.'], 422);
        }

        if ($staff->role === 'admin') {
            return response()->json(['message' => "Account '{$staff->name}' already holds Administrator clearance."], 422);
        }

        $oldValues = $staff->toArray();

        $staff->update([
            'role' => 'admin',
            'permissions' => [], // Admins have full access across modules
        ]);

        AuditLog::log(
            $currentUser,
            'staff.promoted',
            'User',
            $staff->id,
            "Super Admin promoted '{$staff->name}' ({$staff->email}) from Staff to Administrator with executive clearance.",
            $oldValues,
            $staff->toArray()
        );

        return response()->json([
            'message' => "Personnel '{$staff->name}' has been successfully promoted to Administrator.",
            'staff' => $staff,
        ]);
    }

    public function demote(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();

        if (!$currentUser->isSuperAdmin()) {
            return response()->json([
                'message' => 'Security Clearance Error: Only the Super Administrator has authority to demote Administrator personnel.'
            ], 403);
        }

        $staff = User::whereIn('role', ['staff', 'admin', 'super_admin'])->findOrFail($id);

        if ($staff->isSuperAdmin()) {
            return response()->json([
                'message' => 'Security Clearance Violation: The Super Administrator is untouchable and cannot be demoted.'
            ], 403);
        }

        if ($currentUser->id === $staff->id) {
            return response()->json(['message' => 'Security Violation: You cannot demote your own account.'], 422);
        }

        if ($staff->role === 'staff') {
            return response()->json(['message' => "Account '{$staff->name}' is already at Staff clearance."], 422);
        }

        $oldValues = $staff->toArray();

        $staff->update([
            'role' => 'staff',
            'permissions' => ['products.view', 'products.manage', 'orders.view', 'orders.manage', 'inventory.manage'],
        ]);

        AuditLog::log(
            $currentUser,
            'staff.demoted',
            'User',
            $staff->id,
            "Super Admin demoted '{$staff->name}' ({$staff->email}) from Administrator to Staff clearance with default operational permissions.",
            $oldValues,
            $staff->toArray()
        );

        return response()->json([
            'message' => "Personnel '{$staff->name}' has been demoted to Staff status.",
            'staff' => $staff,
        ]);
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();

        if ($currentUser->id === $id) {
            return response()->json(['message' => 'Security Violation: You cannot suspend your own administrative account.'], 422);
        }

        $staff = User::whereIn('role', ['staff', 'admin', 'super_admin'])->findOrFail($id);

        // Super Admin is untouchable
        if ($staff->isSuperAdmin()) {
            return response()->json([
                'message' => 'Security Clearance Violation: Super Administrator account is untouchable and cannot be suspended.'
            ], 403);
        }

        $oldValues = $staff->toArray();

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
                    $suspendedUntil = Carbon::parse($validated['suspended_until']);
                }
                break;
            case 'indefinite':
            default:
                $suspendedUntil = null;
                break;
        }

        $staff->update([
            'status' => 'suspended',
            'suspended_until' => $suspendedUntil,
            'suspension_reason' => $validated['reason'] ?? null,
        ]);

        // Revoke active sessions
        $staff->tokens()->delete();

        $durationLabel = $suspendedUntil ? "until {$suspendedUntil->format('M d, Y H:i T')}" : "indefinitely";
        $reasonLabel = !empty($validated['reason']) ? " (Reason: {$validated['reason']})" : "";

        AuditLog::log(
            $currentUser,
            'staff.suspended',
            'User',
            $staff->id,
            "Suspended staff account '{$staff->name}' ({$staff->email}) {$durationLabel}{$reasonLabel}.",
            $oldValues,
            $staff->toArray()
        );

        return response()->json([
            'message' => "Staff account '{$staff->name}' suspended {$durationLabel}.",
            'staff' => $staff,
        ]);
    }

    public function reactivate(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        $staff = User::whereIn('role', ['staff', 'admin', 'super_admin'])->findOrFail($id);
        $oldValues = $staff->toArray();

        $staff->update([
            'status' => 'active',
            'suspended_until' => null,
            'suspension_reason' => null,
        ]);

        AuditLog::log(
            $currentUser,
            'staff.reactivated',
            'User',
            $staff->id,
            "Reactivated staff account '{$staff->name}' ({$staff->email}) to Active status.",
            $oldValues,
            $staff->toArray()
        );

        return response()->json([
            'message' => "Staff account '{$staff->name}' reactivated to Active.",
            'staff' => $staff,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();

        if ($currentUser->id === $id) {
            return response()->json(['message' => 'Cannot delete your own administrative account.'], 422);
        }

        $staff = User::whereIn('role', ['staff', 'admin', 'super_admin'])->findOrFail($id);

        // Super Admin is untouchable and cannot be deleted
        if ($staff->isSuperAdmin()) {
            return response()->json([
                'message' => 'Security Clearance Violation: Super Administrator account is permanent, untouchable, and cannot be deleted.'
            ], 403);
        }

        $name = $staff->name;
        $staff->tokens()->delete();
        $staff->delete();

        AuditLog::log(
            $currentUser,
            'staff.deleted',
            'User',
            $id,
            "Deleted administrative account '{$name}'"
        );

        return response()->json(['message' => "Administrative account '{$name}' deleted successfully."]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:users,id',
        ]);

        $currentUser = $request->user();
        $deletedCount = 0;
        $skippedCount = 0;

        foreach ($validated['ids'] as $id) {
            if ($id === $currentUser->id) {
                $skippedCount++;
                continue;
            }

            $staff = User::whereIn('role', ['staff', 'admin', 'super_admin'])->find($id);
            if (!$staff) continue;

            if ($staff->isSuperAdmin()) {
                $skippedCount++;
                continue;
            }

            $name = $staff->name;
            $staff->tokens()->delete();
            $staff->delete();
            $deletedCount++;

            AuditLog::log(
                $currentUser,
                'staff.deleted',
                'User',
                $id,
                "Bulk deleted administrative account '{$name}'"
            );
        }

        $msg = "Successfully deleted {$deletedCount} staff member(s).";
        if ($skippedCount > 0) {
            $msg .= " ({$skippedCount} protected/self account(s) skipped)";
        }

        return response()->json([
            'message' => $msg,
            'deleted_count' => $deletedCount,
            'skipped_count' => $skippedCount,
        ]);
    }
}
