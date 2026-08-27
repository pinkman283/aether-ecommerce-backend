<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Check if the authenticated administrator / staff user has the required permission(s).
     * Super Admin and Admin have universal root bypass.
     * Staff must have the explicit permission code in their permissions array.
     */
    protected function checkPermission(Request $request, string ...$permissions): void
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated access.');
        }

        if (!$user->isActive()) {
            abort(403, 'Account is suspended. Please contact executive administration.');
        }

        // Super Admin and Admin have root access
        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return;
        }

        // Verify staff permissions
        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return;
            }
        }

        abort(403, 'Unauthorized access. Missing required permission: ' . implode(', ', $permissions));
    }

    /**
     * Require Super Administrator role strictly.
     */
    protected function requireSuperAdmin(Request $request): void
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated access.');
        }

        if (!$user->isSuperAdmin()) {
            abort(403, 'Action requires Super Administrator privileges.');
        }
    }
}
