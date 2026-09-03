<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Simple key/value settings store.
 *
 * Currently backs the user-menu visibility toggles: the admin decides whether
 * a menu shows in the PWA at all. Adding another toggle = add a key to
 * MENU_KEYS; no new table, endpoint or migration needed.
 */
class AppSetting extends Model
{
    protected $fillable = ['setting_key', 'setting_value', 'updated_by'];

    /**
     * menu key => [label for admin UI, default visible?]
     * The key is what the PWA receives; the label is what the admin sees.
     */
    const MENU_KEYS = [
        'company_docs' => ['label' => 'Company Docs', 'default' => true],
        // Whether a user may download the invoice for a delivered plan
        // product. Off hides the button AND refuses the endpoint.
        'invoice_download' => ['label' => 'Invoice Download (Plan Product)', 'default' => true],
    ];

    /** Raw value for a key, or $default when unset. */
    public static function get(string $key, $default = null)
    {
        $row = self::where('setting_key', $key)->first();
        return $row ? $row->setting_value : $default;
    }

    public static function set(string $key, $value): void
    {
        self::updateOrCreate(
            ['setting_key' => $key],
            [
                'setting_value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                'updated_by'    => Auth::id(),
            ]
        );
    }

    /** Boolean helper — treats an unset key as $default. */
    public static function getBool(string $key, bool $default = true): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return (int) $value === 1;
    }

    /** Storage key for a menu flag. */
    public static function menuKey(string $menu): string
    {
        return 'menu_visible_' . $menu;
    }

    /**
     * Current visibility of every known menu, e.g. ['company_docs' => true].
     * The PWA hides a menu whose value is false.
     */
    public static function menuVisibility(): array
    {
        $out = [];
        foreach (self::MENU_KEYS as $menu => $meta) {
            $out[$menu] = self::getBool(self::menuKey($menu), (bool) $meta['default']);
        }
        return $out;
    }
}
