<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $query = Coupon::latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('code', 'like', "%{$search}%");
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->input('per_page', 15);
        $coupons = $query->paginate($perPage);

        return response()->json($coupons);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        if ($request->has('code')) {
            $request->merge([
                'code' => strtoupper(trim((string) $request->input('code'))),
            ]);
        }

        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:coupons,code',
            'type' => 'required|in:percentage,fixed',
            'value' => [
                'required',
                'numeric',
                'min:0.01',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->input('type') === 'percentage' && (float) $value > 100) {
                        $fail('Percentage discount cannot exceed 100%.');
                    }
                },
            ],
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
            'is_active' => 'boolean',
        ]);

        $coupon = Coupon::create($validated);

        AuditLog::log(
            $request->user(),
            'coupon.created',
            'Coupon',
            $coupon->id,
            "Created coupon '{$coupon->code}' ({$coupon->type}: {$coupon->value})"
        );

        return response()->json([
            'message' => 'Coupon created successfully',
            'coupon' => $coupon,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $coupon = Coupon::findOrFail($id);
        $oldValues = $coupon->toArray();

        if ($request->has('code')) {
            $request->merge([
                'code' => strtoupper(trim((string) $request->input('code'))),
            ]);
        }

        $effectiveType = $request->input('type', $coupon->type);
        $effectiveStartsAt = $request->input('starts_at', $coupon->starts_at?->toIso8601String());

        $validated = $request->validate([
            'code' => "sometimes|required|string|max:50|unique:coupons,code,{$id}",
            'type' => 'sometimes|required|in:percentage,fixed',
            'value' => [
                'sometimes',
                'required',
                'numeric',
                'min:0.01',
                function ($attribute, $value, $fail) use ($effectiveType) {
                    if ($effectiveType === 'percentage' && (float) $value > 100) {
                        $fail('Percentage discount cannot exceed 100%.');
                    }
                },
            ],
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => [
                'nullable',
                'date',
                function ($attribute, $value, $fail) use ($effectiveStartsAt) {
                    if ($value && $effectiveStartsAt && strtotime($value) < strtotime($effectiveStartsAt)) {
                        $fail('The expires at must be a date after or equal to starts at.');
                    }
                },
            ],
            'is_active' => 'boolean',
        ]);

        $coupon->fill($validated);
        $wasDirty = $coupon->isDirty();
        $coupon->save();

        if ($wasDirty) {
            AuditLog::log(
                $request->user(),
                'coupon.updated',
                'Coupon',
                $coupon->id,
                "Updated coupon '{$coupon->code}'",
                $oldValues,
                $coupon->toArray()
            );
        }

        return response()->json([
            'message' => 'Coupon updated successfully',
            'coupon' => $coupon,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $coupon = Coupon::findOrFail($id);
        $code = $coupon->code;
        $coupon->delete();

        AuditLog::log(
            $request->user(),
            'coupon.deleted',
            'Coupon',
            $id,
            "Deleted coupon '{$code}'"
        );

        return response()->json([
            'message' => "Coupon '{$code}' deleted successfully",
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:coupons,id',
        ]);

        $count = 0;

        foreach ($validated['ids'] as $id) {
            $coupon = Coupon::find($id);
            if ($coupon) {
                $code = $coupon->code;
                $coupon->delete();
                $count++;

                AuditLog::log(
                    $request->user(),
                    'coupon.deleted',
                    'Coupon',
                    $id,
                    "Bulk deleted coupon '{$code}'"
                );
            }
        }

        return response()->json([
            'message' => "Successfully deleted {$count} coupon(s).",
            'deleted_count' => $count,
        ]);
    }
}
