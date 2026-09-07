<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Color;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminColorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.view', 'products.manage');

        $query = Color::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('hex_code', 'like', "%{$search}%");
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $colors = $query->orderBy('name')->get();

        return response()->json($colors);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:colors,name',
            'hex_code' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/i'],
            'status' => 'nullable|in:active,inactive',
        ]);

        $color = Color::create([
            'name' => trim($validated['name']),
            'hex_code' => strtoupper(trim($validated['hex_code'])),
            'status' => $validated['status'] ?? 'active',
        ]);

        AuditLog::log(
            $request->user(),
            'color.created',
            'Color',
            $color->id,
            "Created color swatch: {$color->name} ({$color->hex_code})",
            null,
            $color->toArray()
        );

        return response()->json([
            'message' => 'Color swatch created successfully.',
            'color' => $color,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $color = Color::findOrFail($id);
        $oldValues = $color->toArray();

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:colors,name,' . $id,
            'hex_code' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/i'],
            'status' => 'nullable|in:active,inactive',
        ]);

        $color->update([
            'name' => trim($validated['name']),
            'hex_code' => strtoupper(trim($validated['hex_code'])),
            'status' => $validated['status'] ?? $color->status,
        ]);

        AuditLog::log(
            $request->user(),
            'color.updated',
            'Color',
            $color->id,
            "Updated color swatch: {$color->name} ({$color->hex_code})",
            $oldValues,
            $color->toArray()
        );

        return response()->json([
            'message' => 'Color swatch updated successfully.',
            'color' => $color,
        ]);
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $color = Color::findOrFail($id);
        $newStatus = $color->status === 'active' ? 'inactive' : 'active';
        $color->update(['status' => $newStatus]);

        return response()->json([
            'message' => "Color swatch status updated to {$newStatus}.",
            'color' => $color,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $color = Color::findOrFail($id);
        $name = $color->name;
        $color->delete();

        AuditLog::log(
            $request->user(),
            'color.deleted',
            'Color',
            $id,
            "Deleted color swatch: {$name}"
        );

        return response()->json([
            'message' => 'Color swatch deleted successfully.',
        ]);
    }
}
