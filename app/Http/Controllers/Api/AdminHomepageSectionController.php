<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HomepageSection;
use App\Services\HomepageSectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminHomepageSectionController extends Controller
{
    protected HomepageSectionService $sectionService;

    public function __construct(HomepageSectionService $sectionService)
    {
        $this->sectionService = $sectionService;
    }

    /**
     * List all homepage sections for administrative management.
     */
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $sections = HomepageSection::ordered()
            ->with(['category:id,name,slug', 'brand:id,name,slug', 'viewAllCategory:id,name,slug', 'viewAllBrand:id,name,slug'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sections,
        ]);
    }

    /**
     * Store a newly created homepage section.
     */
    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $validated = $request->validate([
            'title' => 'required|string|max:150',
            'slug' => 'nullable|string|max:150|unique:homepage_sections,slug',
            'subtitle' => 'nullable|string|max:1000',
            'badge_text' => 'nullable|string|max:100',
            'badge_icon' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'product_count' => 'nullable|integer|min:2|max:30',
            'view_all_label' => 'nullable|string|max:80',
            'view_all_type' => 'nullable|string|in:category,brand,all_products,custom',
            'view_all_category_id' => 'nullable|exists:categories,id',
            'view_all_brand_id' => 'nullable|exists:brands,id',
            'view_all_url' => 'nullable|string|max:255',
            'has_tabs' => 'nullable|boolean',
            'tabs' => 'nullable|array',
            'source_type' => 'nullable|string|in:category,brand,manual,dynamic',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'sort_by' => 'nullable|string|in:featured,newest,best_selling,price_asc,price_desc,rating',
            'product_ids' => 'nullable|array',
        ]);

        if (empty($validated['slug'])) {
            $baseSlug = Str::slug($validated['title']);
            $slug = $baseSlug;
            $counter = 1;
            while (HomepageSection::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validated['slug'] = $slug;
        }

        // Determine sort order if not given
        if (!isset($validated['sort_order'])) {
            $maxOrder = HomepageSection::max('sort_order') ?? -1;
            $validated['sort_order'] = $maxOrder + 1;
        }

        $this->resolveViewAllUrl($validated);

        $section = HomepageSection::create($validated);
        $this->sectionService->clearCache();

        AuditLog::log(
            $request->user(),
            'homepage_section.created',
            'HomepageSection',
            $section->id,
            "Created homepage section '{$section->title}' (Order: {$section->sort_order})"
        );

        return response()->json([
            'success' => true,
            'message' => 'Homepage section created successfully.',
            'data' => $section->load(['category', 'brand']),
        ], 201);
    }

    /**
     * Show a section and preview its resolved products.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $section = HomepageSection::with(['category', 'brand'])->findOrFail($id);
        $previewData = $this->sectionService->formatSectionForPublic($section);

        return response()->json([
            'success' => true,
            'data' => $section,
            'preview' => $previewData,
        ]);
    }

    /**
     * Update an existing homepage section.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $section = HomepageSection::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:150',
            'slug' => 'nullable|string|max:150|unique:homepage_sections,slug,' . $id,
            'subtitle' => 'nullable|string|max:1000',
            'badge_text' => 'nullable|string|max:100',
            'badge_icon' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'product_count' => 'nullable|integer|min:2|max:30',
            'view_all_label' => 'nullable|string|max:80',
            'view_all_type' => 'nullable|string|in:category,brand,all_products,custom',
            'view_all_category_id' => 'nullable|exists:categories,id',
            'view_all_brand_id' => 'nullable|exists:brands,id',
            'view_all_url' => 'nullable|string|max:255',
            'has_tabs' => 'nullable|boolean',
            'tabs' => 'nullable|array',
            'source_type' => 'nullable|string|in:category,brand,manual,dynamic',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'sort_by' => 'nullable|string|in:featured,newest,best_selling,price_asc,price_desc,rating',
            'product_ids' => 'nullable|array',
        ]);

        $this->resolveViewAllUrl($validated, $section);

        $oldValues = $section->toArray();
        $section->update($validated);
        $this->sectionService->clearCache();

        AuditLog::log(
            $request->user(),
            'homepage_section.updated',
            'HomepageSection',
            $section->id,
            "Updated homepage section '{$section->title}'",
            $oldValues,
            $section->toArray()
        );

        return response()->json([
            'success' => true,
            'message' => 'Homepage section updated successfully.',
            'data' => $section->fresh(['category', 'brand', 'viewAllCategory', 'viewAllBrand']),
        ]);
    }

    /**
     * Delete a homepage section.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $section = HomepageSection::findOrFail($id);
        $title = $section->title;
        $section->delete();
        $this->sectionService->clearCache();

        AuditLog::log(
            $request->user(),
            'homepage_section.deleted',
            'HomepageSection',
            $id,
            "Deleted homepage section '{$title}'"
        );

        return response()->json([
            'success' => true,
            'message' => "Homepage section '{$title}' deleted successfully.",
        ]);
    }

    /**
     * Batch reorder homepage sections.
     */
    public function reorder(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $validated = $request->validate([
            'sections' => 'required|array',
            'sections.*.id' => 'required|integer|exists:homepage_sections,id',
            'sections.*.sort_order' => 'required|integer',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['sections'] as $item) {
                HomepageSection::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
            }
        });

        $this->sectionService->clearCache();

        AuditLog::log(
            $request->user(),
            'homepage_section.reordered',
            'HomepageSection',
            null,
            "Reordered " . count($validated['sections']) . " homepage sections"
        );

        $sections = HomepageSection::ordered()->get();

        return response()->json([
            'success' => true,
            'message' => 'Sections reordered successfully.',
            'data' => $sections,
        ]);
    }

    /**
     * Toggle active/inactive state of a homepage section.
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $section = HomepageSection::findOrFail($id);
        $section->is_active = !$section->is_active;
        $section->save();
        $this->sectionService->clearCache();

        $status = $section->is_active ? 'enabled' : 'disabled';

        AuditLog::log(
            $request->user(),
            'homepage_section.toggled',
            'HomepageSection',
            $section->id,
            "Section '{$section->title}' was {$status}"
        );

        return response()->json([
            'success' => true,
            'message' => "Section '{$section->title}' is now {$status}.",
            'data' => $section,
        ]);
    }

    /**
     * Duplicate a section as a new inactive draft.
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $original = HomepageSection::findOrFail($id);
        $clone = $original->replicate();

        $clone->title = $original->title . ' (Copy)';
        $baseSlug = Str::slug($clone->title);
        $slug = $baseSlug;
        $counter = 1;
        while (HomepageSection::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }
        $clone->slug = $slug;
        $clone->is_active = false;
        $clone->sort_order = (HomepageSection::max('sort_order') ?? 0) + 1;
        $clone->save();

        $this->sectionService->clearCache();

        AuditLog::log(
            $request->user(),
            'homepage_section.duplicated',
            'HomepageSection',
            $clone->id,
            "Duplicated section '{$original->title}' to '{$clone->title}'"
        );

        return response()->json([
            'success' => true,
            'message' => "Duplicated '{$original->title}' successfully.",
            'data' => $clone->load(['category', 'brand']),
        ], 201);
    }

    /**
     * Resolve view all URL from category or brand selection.
     */
    protected function resolveViewAllUrl(array &$data, ?HomepageSection $existing = null): void
    {
        $type = $data['view_all_type'] ?? ($existing?->view_all_type ?? 'category');
        $categoryId = $data['view_all_category_id'] ?? ($data['category_id'] ?? $existing?->view_all_category_id ?? $existing?->category_id);
        $brandId = $data['view_all_brand_id'] ?? ($data['brand_id'] ?? $existing?->view_all_brand_id ?? $existing?->brand_id);

        if ($type === 'category') {
            if ($categoryId) {
                $category = \App\Models\Category::find($categoryId);
                if ($category) {
                    $data['view_all_url'] = "/products?category={$category->slug}";
                    $data['view_all_category_id'] = $categoryId;
                }
            }
        } elseif ($type === 'brand') {
            if ($brandId) {
                $brand = \App\Models\Brand::find($brandId);
                if ($brand) {
                    $data['view_all_url'] = "/products?brand={$brand->slug}";
                    $data['view_all_brand_id'] = $brandId;
                }
            }
        } elseif ($type === 'all_products') {
            $data['view_all_url'] = "/products";
        }
        // If type is custom, keep $data['view_all_url']
    }
}
