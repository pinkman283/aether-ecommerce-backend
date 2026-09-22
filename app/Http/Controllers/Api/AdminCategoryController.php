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
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'categories.manage', 'products.view', 'products.manage');

        $categories = Category::withCount('products')
            ->with(['parent.parent.parent', 'children.children.children'])
            ->orderBy('display_order')
            ->get();
        return response()->json($categories);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'categories.manage');

        $request->validate([
            'image' => 'required|file|image|mimes:jpeg,png,jpg,webp,gif,avif,jfif|max:10240',
        ], [
            'image.uploaded' => 'The image failed to upload. The file may exceed the server upload limit (' . ini_get('upload_max_filesize') . '). Please choose a smaller image.',
            'image.image' => 'The uploaded file must be a valid image (JPEG, PNG, WebP, GIF, AVIF).',
            'image.mimes' => 'The image must be a file of type: jpeg, png, jpg, webp, gif, avif.',
            'image.max' => 'The image size cannot exceed 10MB.',
            'image.required' => 'An image file is required for upload.',
        ]);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'jpg');
        $filename = 'cat_' . Str::random(16) . '_' . time() . '.' . strtolower($ext);
        $path = $file->storeAs('categories', $filename, 'public');
        $fullUrl = url('storage/' . $path);

        return response()->json([
            'message' => 'Category image uploaded successfully',
            'image_url' => $fullUrl,
            'path' => $path,
            'filename' => $filename,
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'categories.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'badge' => 'nullable|string|max:50',
            'is_featured' => 'boolean',
            'display_order' => 'integer',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'image_alt' => 'nullable|string|max:255',
        ]);

        $slug = Str::slug($validated['name']);
        if (Category::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $category = Category::create([
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'image' => $validated['image'] ?? null,
            'icon' => $validated['icon'] ?? 'Sparkles',
            'badge' => $validated['badge'] ?? null,
            'is_featured' => $validated['is_featured'] ?? false,
            'display_order' => $validated['display_order'] ?? 0,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'image_alt' => $validated['image_alt'] ?? null,
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
            'category' => $category->loadCount('products')->load('parent'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'categories.manage');

        $category = Category::findOrFail($id);
        $oldValues = $category->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'badge' => 'nullable|string|max:50',
            'is_featured' => 'boolean',
            'display_order' => 'integer',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'image_alt' => 'nullable|string|max:255',
        ]);

        if (array_key_exists('parent_id', $validated) && $validated['parent_id']) {
            if ($validated['parent_id'] == $category->id) {
                return response()->json([
                    'message' => 'A category cannot be its own parent.',
                ], 422);
            }
            $descendants = $category->getAllChildrenIds();
            if (in_array($validated['parent_id'], $descendants)) {
                return response()->json([
                    'message' => 'Cannot set a descendant category as parent.',
                ], 422);
            }
        }

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
            'category' => $category->loadCount('products')->load('parent'),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'categories.manage');

        $category = Category::withCount('products')->findOrFail($id);

        if ($category->products_count > 0) {
            $productCountText = $category->products_count === 1 ? '1 product assigned' : "{$category->products_count} products assigned";
            return response()->json([
                'message' => "Cannot delete category '{$category->name}' because there are {$productCountText} to it. Reassign or delete those products first.",
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

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'categories.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:categories,id',
        ]);

        $deletedCount = 0;
        $skippedCount = 0;
        $skippedNames = [];

        foreach ($validated['ids'] as $id) {
            $category = Category::withCount('products')->find($id);
            if (!$category) continue;

            if ($category->products_count > 0) {
                $skippedCount++;
                if (count($skippedNames) < 3) {
                    $skippedNames[] = "'{$category->name}'";
                }
                continue;
            }

            $name = $category->name;
            $category->delete();
            $deletedCount++;

            AuditLog::log(
                $request->user(),
                'category.deleted',
                'Category',
                $id,
                "Bulk deleted category '{$name}'"
            );
        }

        if ($deletedCount === 0 && $skippedCount > 0) {
            $nameStr = !empty($skippedNames) ? ' (' . implode(', ', $skippedNames) . ')' : '';
            $message = $skippedCount === 1
                ? "Cannot delete category{$nameStr} because there are products assigned to it. Reassign or delete those products first."
                : "Cannot delete {$skippedCount} categories because there are products assigned to them. Reassign or delete those products first.";
        } elseif ($deletedCount > 0 && $skippedCount > 0) {
            $message = "Deleted {$deletedCount} category/categories. Cannot delete {$skippedCount} category/categories because there are products assigned to them.";
        } else {
            $message = "Successfully deleted {$deletedCount} category/categories.";
        }

        return response()->json([
            'message' => $message,
            'deleted_count' => $deletedCount,
            'skipped_count' => $skippedCount,
        ]);
    }
}
