<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Show / hide user-facing menus.
 *
 * Admin flips a menu on or off; the PWA reads the same map on load and drops
 * any menu marked false. Adding a new toggle only needs a key in
 * AppSetting::MENU_KEYS — this controller adapts automatically.
 */
class MenuSettingController extends Controller
{
    /** Admin: current flags + labels to render the toggles. */
    public function index()
    {
        try {
            $visibility = AppSetting::menuVisibility();

            $menus = [];
            foreach (AppSetting::MENU_KEYS as $key => $meta) {
                $menus[] = [
                    'key'        => $key,
                    'label'      => $meta['label'],
                    'is_visible' => (bool) ($visibility[$key] ?? $meta['default']),
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => $menus,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('MenuSetting index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Admin: set one menu's visibility.
     * Body: { key: "company_docs", is_visible: 0|1 }
     */
    public function update(Request $request)
    {
        try {
            $key = (string) $request->input('key');
            if (!array_key_exists($key, AppSetting::MENU_KEYS)) {
                return response()->json(['message' => 'Unknown menu', 'status' => 400], 400);
            }

            $visible = (int) $request->input('is_visible') ? 1 : 0;
            AppSetting::set(AppSetting::menuKey($key), $visible);

            return response()->json(['message' => 'Menu visibility updated', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('MenuSetting update failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * User (PWA): flat map the layout uses to filter its menu, e.g.
     * { "company_docs": true }.
     */
    public function userMenus()
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => AppSetting::menuVisibility(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('MenuSetting userMenus failed', ['error' => $e->getMessage()]);
            // Never hide menus because of an error — fail open to the defaults.
            $defaults = [];
            foreach (AppSetting::MENU_KEYS as $menu => $meta) {
                $defaults[$menu] = (bool) $meta['default'];
            }
            return response()->json(['success' => true, 'data' => $defaults], 200);
        }
    }
}
