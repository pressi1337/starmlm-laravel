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

            self::create([
                'user_id'      => $user->id,
                'username'     => $user->username,
                'customer_id'  => $user->customer_id,
                'role'         => $user->role,
                'ip_address'   => $request->ip(),
                'device'       => $parsed['device'],
                'os'           => $parsed['os'],
                'browser'      => $parsed['browser'],
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
