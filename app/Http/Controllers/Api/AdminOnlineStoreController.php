<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CmsPage;
use App\Models\FooterLink;
use App\Models\SocialLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminOnlineStoreController extends Controller
{
    // ==========================================
    // 1. CMS PAGES
    // ==========================================

    public function pages(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $query = CmsPage::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $pages = $query->orderBy('title')->get();

        return response()->json($pages);
    }

    public function showPage(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $page = CmsPage::findOrFail($id);
        return response()->json($page);
    }

    public function storePage(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:cms_pages,slug',
            'content' => 'required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);
        if (CmsPage::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $page = CmsPage::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'content' => $validated['content'],
            'meta_title' => $validated['meta_title'] ?? $validated['title'],
            'meta_description' => $validated['meta_description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLog::log(
            $request->user(),
            'cms.page_created',
            'CmsPage',
            $page->id,
            "Created CMS page: {$page->title}",
            null,
            $page->toArray()
        );

        return response()->json([
            'message' => 'CMS page created successfully.',
            'page' => $page,
        ], 201);
    }

    public function updatePage(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $page = CmsPage::findOrFail($id);
        $oldValues = $page->toArray();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:cms_pages,slug,' . $id,
            'content' => 'required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);
        if (CmsPage::where('slug', $slug)->where('id', '!=', $id)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $page->update([
            'title' => $validated['title'],
            'slug' => $slug,
            'content' => $validated['content'],
            'meta_title' => $validated['meta_title'] ?? $validated['title'],
            'meta_description' => $validated['meta_description'] ?? null,
            'is_active' => $validated['is_active'] ?? $page->is_active,
        ]);

        AuditLog::log(
            $request->user(),
            'cms.page_updated',
            'CmsPage',
            $page->id,
            "Updated CMS page: {$page->title}",
            $oldValues,
            $page->toArray()
        );

        return response()->json([
            'message' => 'CMS page updated successfully.',
            'page' => $page,
        ]);
    }

    public function togglePageStatus(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $page = CmsPage::findOrFail($id);
        $page->update(['is_active' => !$page->is_active]);

        return response()->json([
            'message' => 'Page status updated.',
            'page' => $page,
        ]);
    }

    public function destroyPage(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $page = CmsPage::findOrFail($id);
        $title = $page->title;
        $page->delete();

        AuditLog::log(
            $request->user(),
            'cms.page_deleted',
            'CmsPage',
            $id,
            "Deleted CMS page: {$title}"
        );

        return response()->json([
            'message' => 'CMS page deleted successfully.',
        ]);
    }

    // ==========================================
    // 2. FOOTER LINKS
    // ==========================================

    public function footerLinks(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $links = FooterLink::orderBy('column_group')
            ->orderBy('sort_order')
            ->get();

        return response()->json($links);
    }

    public function storeFooterLink(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'column_group' => 'required|string|max:50',
            'title' => 'required|string|max:100',
            'url' => 'required|string|max:255',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $link = FooterLink::create([
            'column_group' => $validated['column_group'],
            'title' => $validated['title'],
            'url' => $validated['url'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Footer link created.',
            'footer_link' => $link,
        ], 201);
    }

    public function updateFooterLink(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $link = FooterLink::findOrFail($id);

        $validated = $request->validate([
            'column_group' => 'required|string|max:50',
            'title' => 'required|string|max:100',
            'url' => 'required|string|max:255',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $link->update($validated);

        return response()->json([
            'message' => 'Footer link updated.',
            'footer_link' => $link,
        ]);
    }

    public function destroyFooterLink(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $link = FooterLink::findOrFail($id);
        $link->delete();

        return response()->json([
            'message' => 'Footer link deleted.',
        ]);
    }

    // ==========================================
    // 3. SOCIAL LINKS
    // ==========================================

    public function socialLinks(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $links = SocialLink::orderBy('sort_order')->get();
        return response()->json($links);
    }

    public function storeSocialLink(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'platform' => 'required|string|max:50',
            'url' => 'required|string|max:255',
            'icon' => 'nullable|string|max:50',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $link = SocialLink::create([
            'platform' => $validated['platform'],
            'url' => $validated['url'],
            'icon' => $validated['icon'] ?? strtolower($validated['platform']),
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Social link created.',
            'social_link' => $link,
        ], 201);
    }

    public function updateSocialLink(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $link = SocialLink::findOrFail($id);

        $validated = $request->validate([
            'platform' => 'required|string|max:50',
            'url' => 'required|string|max:255',
            'icon' => 'nullable|string|max:50',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $link->update($validated);

        return response()->json([
            'message' => 'Social link updated.',
            'social_link' => $link,
        ]);
    }

    public function destroySocialLink(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $link = SocialLink::findOrFail($id);
        $link->delete();

        return response()->json([
            'message' => 'Social link deleted.',
        ]);
    }
}
