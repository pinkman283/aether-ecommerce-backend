<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        // STRICT SECURITY BOUNDARY: Reject normal customer accounts
        if (!$user->isStaffOrAdmin()) {
            return response()->json([
                'message' => 'Access denied. This account does not possess administrative privileges.',
            ], 403);
        }

        if ($user->isSuspended()) {
            $reasonText = $user->suspension_reason ? " Reason: {$user->suspension_reason}." : "";
            if ($user->suspended_until) {
                $untilFormatted = $user->suspended_until->format('M d, Y H:i T');
                return response()->json([
                    'message' => "Your administrative account is temporarily suspended until {$untilFormatted}.{$reasonText}",
                    'suspended' => true,
                    'suspended_until' => $user->suspended_until,
                    'suspension_reason' => $user->suspension_reason,
                ], 403);
            }
            return response()->json([
                'message' => "Your administrative account has been suspended indefinitely. Please contact the Super Administrator.{$reasonText}",
                'suspended' => true,
                'suspended_until' => null,
                'suspension_reason' => $user->suspension_reason,
            ], 403);
        }

        // Issue token with admin ability
        $token = $user->createToken('admin_token', ['admin:access'])->plainTextToken;

        AuditLog::log(
            $user,
            'admin.login',
            'User',
            $user->id,
            "Administrator {$user->name} authenticated into Executive Portal."
        );

        return response()->json([
            'message' => 'Administrator authenticated successfully',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $user->permissions ?? [],
                'avatar' => $user->avatar,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $user->permissions ?? [],
                'status' => $user->status,
                'avatar' => $user->avatar,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $oldValues = $user->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|string',
            'password' => 'nullable|string|min:8',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->fill($validated);
        $wasDirty = $user->isDirty();
        $user->save();

        if ($wasDirty) {
            AuditLog::log(
                $user,
                'admin.profile_updated',
                'User',
                $user->id,
                "Administrator {$user->name} updated their profile settings & avatar.",
                $oldValues,
                $user->toArray()
            );
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $user->permissions ?? [],
                'status' => $user->status,
                'avatar' => $user->avatar,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            AuditLog::log(
                $user,
                'admin.logout',
                'User',
                $user->id,
                "Administrator {$user->name} logged out from Executive Portal."
            );
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Admin session terminated successfully',
        ]);
    }
}
