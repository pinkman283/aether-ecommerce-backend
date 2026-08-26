<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::withCount('products')->orderBy('display_order')->get();
        return response()->json($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string|url',
            'icon' => 'nullable|string|max:50',
            'badge' => 'nullable|string|max:50',
            'is_featured' => 'boolean',
            'display_order' => 'integer',
        ]);

        $slug = Str::slug($validated['name']);
        if (Category::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $category = Category::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'image' => $validated['image'] ?? null,
            'icon' => $validated['icon'] ?? 'Sparkles',
            'badge' => $validated['badge'] ?? null,
            'is_featured' => $validated['is_featured'] ?? false,
            'display_order' => $validated['display_order'] ?? 0,
        ]);

        AuditLog::log(
            $request->user(),
            'category.created',
            'Category',
            $category->id,
            "Created category '{$category->name}'"
        );

        return response()->json([
            'message' => 'Category created successfully',
            'category' => $category->loadCount('products'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $oldValues = $category->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string|url',
            'icon' => 'nullable|string|max:50',
            'badge' => 'nullable|string|max:50',
            'is_featured' => 'boolean',
            'display_order' => 'integer',
        ]);

        $category->fill($validated);
        $wasDirty = $category->isDirty();
        $category->save();

        if ($wasDirty) {
            AuditLog::log(
                $request->user(),
                'category.updated',
                'Category',
                $category->id,
                "Updated category '{$category->name}'",
                $oldValues,
                $category->toArray()
            );
        }

        return response()->json([
            'message' => 'Category updated successfully',
            'category' => $category->loadCount('products'),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = Category::withCount('products')->findOrFail($id);

        if ($category->products_count > 0) {
            return response()->json([
                'message' => "Cannot delete category '{$category->name}'. There are {$category->products_count} hardware products associated with it. Reassign or delete those products first.",
            ], 422);
        }

        $name = $category->name;
        $category->delete();

        AuditLog::log(
            $request->user(),
            'category.deleted',
            'Category',
            $id,
            "Deleted category '{$name}'"
        );

        return response()->json([
            'message' => "Category '{$name}' deleted successfully",
        ]);
    }
}
