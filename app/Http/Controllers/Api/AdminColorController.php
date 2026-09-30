<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Color;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminColorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.view', 'products.manage');

        $query = Color::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('hex_code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $colors = $query->orderBy('sort_order')->orderBy('name')->get();

        // Calculate variants_count for each color in 1 efficient query
        if ($colors->isNotEmpty()) {
            $names = $colors->pluck('name')->map(fn($n) => strtolower(trim($n)))->all();
            $hexes = $colors->pluck('hex_code')->map(fn($h) => strtolower(trim($h)))->all();

            $variants = DB::table('product_variants')
                ->select('color_name', 'color_hex')
                ->where(function ($q) use ($names, $hexes) {
                    $q->whereIn(DB::raw('LOWER(color_name)'), $names)
                      ->orWhereIn(DB::raw('LOWER(color_hex)'), $hexes);
                })
                ->get();

            foreach ($colors as $color) {
                $cName = strtolower(trim($color->name));
                $cHex = strtolower(trim($color->hex_code));

                $color->variants_count = $variants->filter(function ($v) use ($cName, $cHex) {
                    $vName = strtolower(trim((string)$v->color_name));
                    $vHex = strtolower(trim((string)$v->color_hex));
                    return ($vName !== '' && $vName === $cName) || ($vHex !== '' && $vHex === $cHex);
                })->count();
            }
        }

        return response()->json($colors);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'products.view', 'products.manage');

        $color = Color::findOrFail($id);
        $name = strtolower(trim($color->name));
        $hex = strtolower(trim($color->hex_code));

        $color->variants_count = DB::table('product_variants')
            ->where(function ($q) use ($name, $hex) {
                $q->where(DB::raw('LOWER(color_name)'), $name)
                  ->orWhere(DB::raw('LOWER(color_hex)'), $hex);
            })
            ->count();

        return response()->json($color);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:colors,name',
            'hex_code' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/i'],
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);

        $color = Color::create([
            'name' => trim($validated['name']),
            'hex_code' => strtoupper(trim($validated['hex_code'])),
            'status' => $validated['status'] ?? 'active',
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        $color->variants_count = 0;

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
            'sort_order' => 'nullable|integer',
        ]);

        $color->update([
            'name' => trim($validated['name']),
            'hex_code' => strtoupper(trim($validated['hex_code'])),
            'status' => $validated['status'] ?? $color->status,
            'sort_order' => $validated['sort_order'] ?? $color->sort_order,
        ]);

        $name = strtolower(trim($color->name));
        $hex = strtolower(trim($color->hex_code));
        $color->variants_count = DB::table('product_variants')
            ->where(function ($q) use ($name, $hex) {
                $q->where(DB::raw('LOWER(color_name)'), $name)
                  ->orWhere(DB::raw('LOWER(color_hex)'), $hex);
            })
            ->count();

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
        $name = trim($color->name);
        $hex = trim($color->hex_code);

        // Check whether any ProductVariant references this color by color_name or color_hex
        $variantCount = DB::table('product_variants')
            ->where(function ($q) use ($name, $hex) {
                $q->where(DB::raw('LOWER(color_name)'), strtolower($name))
                  ->orWhere(DB::raw('LOWER(color_hex)'), strtolower($hex));
            })
            ->count();

        if ($variantCount > 0) {
            return response()->json([
                'message' => "Color '{$color->name}' cannot be deleted because it is currently used by {$variantCount} product variant(s). Reassign or remove these variants first.",
                'conflicts' => [
                    'product_variants' => $variantCount,
                ],
            ], 422);
        }

        $colorName = $color->name;
        $color->delete();

        AuditLog::log(
            $request->user(),
            'color.deleted',
            'Color',
            $id,
            "Deleted color swatch: {$colorName}"
        );

        return response()->json([
            'message' => "Color swatch '{$colorName}' deleted successfully.",
        ]);
    }
}
