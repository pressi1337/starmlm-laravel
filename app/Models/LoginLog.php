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
        'screen',
        'user_agent',
        'logged_in_at',
    ];

    protected $casts = [
        'logged_in_at' => 'datetime',
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
                'device_model' => self::parseDeviceModel($agent),
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
