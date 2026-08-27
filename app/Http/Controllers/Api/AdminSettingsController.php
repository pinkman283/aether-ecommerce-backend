<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'settings.manage');

        $settings = Setting::all()->keyBy('key');
        return response()->json($settings);
    }

    public function update(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'settings.manage');

        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'nullable',
        ]);

        $oldValues = [];
        $newValues = [];

        foreach ($validated['settings'] as $key => $val) {
            $setting = Setting::where('key', $key)->first();
            if ($setting) {
                $oldValues[$key] = $setting->value;
                $setting->update(['value' => is_array($val) ? json_encode($val) : (string) $val]);
                $newValues[$key] = $val;
            } else {
                Setting::create([
                    'key' => $key,
                    'value' => is_array($val) ? json_encode($val) : (string) $val,
                    'group' => 'general',
                    'label' => ucwords(str_replace('_', ' ', $key)),
                ]);
                $newValues[$key] = $val;
            }
        }

        AuditLog::log(
            $request->user(),
            'settings.updated',
            'Setting',
            null,
            "Updated system store and operational settings.",
            $oldValues,
            $newValues
        );

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => Setting::all()->keyBy('key'),
        ]);
    }
}
