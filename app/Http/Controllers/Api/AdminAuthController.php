<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
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
        if (!in_array($user->role, ['super_admin', 'admin', 'staff'])) {
            AuditLog::log(
                null,
                'admin.auth_rejected',
                'User',
                $user->id,
                "Unauthorized login attempt to admin console by non-admin user {$user->email} (Role: {$user->role})",
                ['email' => $user->email, 'role' => $user->role]
            );

            return response()->json([
                'message' => 'Access Denied: You do not have administrative privileges to access this console.',
            ], 403);
        }

        if ($user->isSuspended()) {
            AuditLog::log(
                null,
                'admin.suspended_login_blocked',
                'User',
                $user->id,
                "Suspended administrative account {$user->email} attempted to log in.",
                ['email' => $user->email, 'role' => $user->role, 'suspended_until' => $user->suspended_until]
            );

            $reasonText = $user->suspension_reason ? " Reason: {$user->suspension_reason}." : "";
            return response()->json([
                'message' => "This administrative account is suspended.{$reasonText} Contact executive management.",
            ], 403);
        }

        // Revoke older admin session tokens
        $user->tokens()->where('name', 'admin_token')->delete();

        // Generate high-privilege token
        $token = $user->createToken('admin_token', ['admin:access'])->plainTextToken;

        // Log successful administrator authentication in cryptographic audit trail
        AuditLog::log(
            $user,
            'admin.login_success',
            'User',
            $user->id,
            "Administrator {$user->name} ({$user->role}) signed in successfully to operations console.",
            ['email' => $user->email, 'role' => $user->role, 'ip' => $request->ip()]
        );

        return response()->json([
            'message' => 'Administrative authentication authorized.',
            'token' => $token,
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
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|string',
            'current_password' => 'nullable|string',
            'password' => 'nullable|string',
            'new_password' => 'nullable|string',
            'password_confirmation' => 'nullable|string',
            'confirm_password' => 'nullable|string',
        ]);

        $currentPassword = $request->input('current_password');

        // Verify current password if admin is changing email
        if (!empty($validated['email']) && strtolower(trim($validated['email'])) !== strtolower(trim($user->email))) {
            if (empty($currentPassword)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Please enter your current administrator password to authorize changing your email address.'],
                ]);
            }

            if (!Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password you entered is incorrect.'],
                ]);
            }
        }

        // Verify current password if admin is changing password
        $newPassword = $request->input('password') ?? $request->input('new_password');

        if (!empty($newPassword)) {
            $confirmPassword = $request->input('password_confirmation') ?? $request->input('confirm_password');

            if (empty($currentPassword)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Please enter your current administrator password to set a new password.'],
                ]);
            }

            if (!Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password you entered is incorrect.'],
                ]);
            }

            if (strlen($newPassword) < 8) {
                throw ValidationException::withMessages([
                    'password' => ['New administrator password must be at least 8 characters long.'],
                ]);
            }

            if ($newPassword !== $confirmPassword) {
                throw ValidationException::withMessages([
                    'password_confirmation' => ['The new password confirmation does not match.'],
                ]);
            }

            $validated['password'] = Hash::make($newPassword);
        } else {
            unset($validated['password']);
        }

        unset($validated['current_password'], $validated['new_password'], $validated['password_confirmation'], $validated['confirm_password']);

        $user->fill($validated);
        $wasDirty = $user->isDirty();
        $user->save();

        if (!empty($newPassword)) {
            $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        }

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
