<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * One row per successful login. See the create migration for column notes.
 *
 * User-Agent parsing is done here with plain string matching rather than a
 * package — it only needs to answer "what phone / browser was this", and
 * keeping it dependency-free means nothing extra to install on the server.
 * The raw user_agent is always stored, so an unrecognised device is never
 * lost — it just shows as "Unknown".
 */
class LoginLog extends Model
{
    protected $fillable = [
        'user_id',
        'username',
        'customer_id',
        'role',
        'ip_address',
        'device',
        'os',
        'browser',
        'device_id',
        'device_model',
        'device_brand',
        'screen',
        'user_agent',
        'city',
        'region',
        'country',
        'isp',
        'location_checked_at',
        'logged_in_at',
    ];

    protected $casts = [
        'logged_in_at'        => 'datetime',
        'location_checked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Record a successful login. Never throws — a logging failure must not
     * stop somebody signing in.
     */
    public static function record(User $user, Request $request): void
    {
        try {
            $agent = (string) $request->userAgent();
            $parsed = self::parseUserAgent($agent);

            // Sent by the app; absent for older clients, which is fine.
            $deviceId = $request->input('device_id');
            $screen = $request->input('screen');
            $model = self::parseDeviceModel($agent);

            self::create([
                'user_id'      => $user->id,
                'username'     => $user->username,
                'customer_id'  => $user->customer_id,
                'role'         => $user->role,
                'ip_address'   => $request->ip(),
                'device'       => $parsed['device'],
                'os'           => $parsed['os'],
                'browser'      => $parsed['browser'],
                'device_id'    => is_string($deviceId) ? substr($deviceId, 0, 64) : null,
                'device_model' => $model,
                'device_brand' => self::parseDeviceBrand($model, $agent),
                'screen'       => is_string($screen) ? substr($screen, 0, 30) : null,
                'user_agent'   => $agent,
                'logged_in_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('LoginLog record failed', [
                'user_id' => $user->id ?? null,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** @return array{device:string, os:string, browser:string} */
    public static function parseUserAgent(?string $agent): array
    {
        $ua = (string) $agent;

        if ($ua === '') {
            return ['device' => 'Unknown', 'os' => 'Unknown', 'browser' => 'Unknown'];
        }

        // ── OS ────────────────────────────────────────────────────────────
        $os = 'Unknown';
        if (preg_match('/Windows NT/i', $ua))            $os = 'Windows';
        elseif (preg_match('/Android/i', $ua))           $os = 'Android';
        elseif (preg_match('/(iPhone|iPad|iPod)/i', $ua)) $os = 'iOS';
        elseif (preg_match('/Mac OS X/i', $ua))          $os = 'macOS';
        elseif (preg_match('/(Linux|X11)/i', $ua))       $os = 'Linux';

        // ── Device class ──────────────────────────────────────────────────
        // Order matters: iPad/Tablet must be checked before the generic
        // Mobile test, since tablet UAs often contain "Mobile" too.
        if (preg_match('/(iPad|Tablet)/i', $ua)
            || (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua))) {
            $device = 'Tablet';
        } elseif (preg_match('/(Mobile|iPhone|iPod|Android)/i', $ua)) {
            $device = 'Mobile';
        } else {
            $device = 'Desktop';
        }

        // ── Browser ───────────────────────────────────────────────────────
        // Also order-sensitive: Edge/Opera/Samsung all include "Chrome", and
        // Chrome includes "Safari", so the specific ones come first.
        $browser = 'Unknown';
        if (preg_match('/(Edg|Edge)\//i', $ua))          $browser = 'Edge';
        elseif (preg_match('/(OPR|Opera)\//i', $ua))     $browser = 'Opera';
        elseif (preg_match('/SamsungBrowser\//i', $ua))  $browser = 'Samsung Internet';
        elseif (preg_match('/(FBAN|FBAV|Instagram)/i', $ua)) $browser = 'In-App Browser';
        elseif (preg_match('/Chrome\//i', $ua))          $browser = 'Chrome';
        elseif (preg_match('/Firefox\//i', $ua))         $browser = 'Firefox';
        elseif (preg_match('/Safari\//i', $ua))          $browser = 'Safari';

        return ['device' => $device, 'os' => $os, 'browser' => $browser];
    }

    /**
     * Pull the phone model out of the user agent, e.g. "SM-G991B" (Samsung
     * S21) or "Redmi Note 12". Android exposes it; iOS deliberately does not,
     * so iPhones/iPads report only the family name.
     */
    public static function parseDeviceModel(?string $agent): ?string
    {
        $ua = (string) $agent;
        if ($ua === '') {
            return null;
        }

        // Android: "... (Linux; Android 13; SM-G991B Build/...)" — the model is
        // the segment after the Android version.
        if (preg_match('/Android\s+[\d.]+;\s*([^;)]+?)(?:\s+Build\/[^;)]*)?[;)]/i', $ua, $m)) {
            $model = trim($m[1]);
            // Some UAs carry a locale ("en-us") in that slot instead.
            if ($model !== '' && !preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $model)) {
                return substr($model, 0, 100);
            }
        }

        if (preg_match('/\biPad\b/i', $ua))   return 'iPad';
        if (preg_match('/\biPhone\b/i', $ua)) return 'iPhone';
        if (preg_match('/\biPod\b/i', $ua))   return 'iPod';

        return null;
    }

    /**
     * Work out the manufacturer — "Samsung", "Xiaomi", "Oppo" — from the model
     * code. Android phones report a code, not a brand: "SM-G991B" means nothing
     * to somebody reading the admin list, but every maker uses its own code
     * prefix, so the brand can be recovered from it.
     *
     * Brand words are checked before code prefixes because some agents already
     * spell it out ("Redmi Note 12", "vivo 1904").
     *
     * Known limit: OnePlus shifted to Oppo's "CPH" codes after the two merged,
     * so a recent OnePlus Nord reads as Oppo. The model code is always shown
     * next to the brand, so nothing is hidden by that.
     */
    public static function parseDeviceBrand(?string $model, ?string $agent = null): ?string
    {
        $haystack = trim((string) $model);
        if ($haystack === '') {
            $haystack = (string) $agent;
        }
        if ($haystack === '') {
            return null;
        }

        // Spelled-out brand names first — an exact word beats a code guess.
        $names = [
            '/\b(Samsung|Galaxy)\b/i'  => 'Samsung',
            '/\b(Xiaomi|Redmi|POCO|POCOPHONE)\b/i' => 'Xiaomi',
            '/\brealme\b/i'            => 'Realme',
            '/\bOnePlus\b/i'           => 'OnePlus',
            '/\bOPPO\b/i'              => 'Oppo',
            '/\bvivo\b/i'              => 'Vivo',
            '/\biQOO\b/i'              => 'iQOO',
            '/\b(Motorola|moto)\b/i'   => 'Motorola',
            '/\bPixel\b/i'             => 'Google',
            '/\b(iPhone|iPad|iPod|Macintosh)\b/i' => 'Apple',
            '/\bNokia\b/i'             => 'Nokia',
            '/\bInfinix\b/i'           => 'Infinix',
            '/\bTECNO\b/i'             => 'Tecno',
            '/\bitel\b/i'              => 'itel',
            '/\bLava\b/i'              => 'Lava',
            '/\bMicromax\b/i'          => 'Micromax',
            '/\bHonor\b/i'             => 'Honor',
            '/\bHUAWEI\b/i'            => 'Huawei',
            '/\b(ASUS|ZenFone)\b/i'    => 'Asus',
            '/\bLenovo\b/i'            => 'Lenovo',
            '/\bNothing\b/i'           => 'Nothing',
            '/\bJioPhone\b/i'          => 'Jio',
        ];
        foreach ($names as $pattern => $brand) {
            if (preg_match($pattern, $haystack)) {
                return $brand;
            }
        }

        // Then the maker-specific model code prefixes.
        $codes = [
            '/^(SM-|GT-|SGH-|SCH-|SPH-)/i'   => 'Samsung',   // SM-G991B
            '/^(M\d{4}[A-Z]|\d{4}[A-Z0-9]{5,}|MI\s)/i' => 'Xiaomi', // M2101K6G / 22011119UY
            '/^RMX\d/i'                      => 'Realme',    // RMX3231
            '/^CPH\d/i'                      => 'Oppo',      // CPH2185
            '/^(V\d{4}|vivo)/i'              => 'Vivo',      // V2027
            '/^(I\d{4})/i'                   => 'iQOO',      // I2201
            '/^XT\d{4}/i'                    => 'Motorola',  // XT2041-1
            '/^TA-\d{4}/i'                   => 'Nokia',     // TA-1234
            '/^(KB2|IN2|HD19|GM19|AC2|LE2|BE2|DN2|EB2|NE2)\d/i' => 'OnePlus',
            '/^(ANE|VOG|ELE|MAR|POT|JNY|STK)-/i' => 'Huawei',
            '/^(CMA|RMO|NTH|LLY|ANY|WDY)-/i' => 'Honor',
        ];
        foreach ($codes as $pattern => $brand) {
            if (preg_match($pattern, $haystack)) {
                return $brand;
            }
        }

        return null;
    }

    /**
     * The stored brand, working it out on the fly for rows logged before the
     * column existed — so old history reads the same as new history.
     */
    public function brand(): ?string
    {
        if (is_string($this->device_brand) && trim($this->device_brand) !== '') {
            return $this->device_brand;
        }
        return self::parseDeviceBrand($this->device_model, $this->user_agent);
    }

    /**
     * What the admin actually reads, e.g. "Samsung SM-G991B", "Xiaomi Redmi
     * Note 12 Pro", "Apple iPhone". Falls back to the device class when the
     * agent gives no model (desktops, and iOS beyond the family name).
     */
    public function deviceName(): string
    {
        $model = trim((string) $this->device_model);
        $brand = trim((string) $this->brand());

        if ($model === '') {
            if ($this->os === 'Windows') return 'Windows PC';
            if ($this->os === 'macOS')   return 'Mac';
            return $this->device ?: 'Unknown';
        }

        // Don't print "Xiaomi Xiaomi Redmi …" when the model already names it.
        if ($brand === '' || stripos($model, $brand) === 0) {
            return $model;
        }

        return $brand . ' ' . $model;
    }

    /** "Chennai, Tamil Nadu, India" — whichever parts resolved. */
    public function locationLabel(): ?string
    {
        $parts = array_filter([$this->city, $this->region, $this->country], function ($p) {
            return is_string($p) && trim($p) !== '';
        });
        return $parts ? implode(', ', $parts) : null;
    }

    /** e.g. "Mobile · Android · Chrome" for the admin list. */
    public function deviceLabel(): string
    {
        $parts = array_filter([$this->device, $this->os, $this->browser], function ($p) {
            return $p && $p !== 'Unknown';
        });
        return $parts ? implode(' · ', $parts) : 'Unknown';
    }

    public function roleLabel(): string
    {
        return [
            User::ROLE_SUPER_ADMIN => 'Admin',
            User::ROLE_SUB_ADMIN   => 'Sub Admin',
            User::ROLE_USER        => 'User',
        ][(int) $this->role] ?? 'Unknown';
    }
}
