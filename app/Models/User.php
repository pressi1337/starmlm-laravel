<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    const TRAINING_STATUS_PENDING = 0;
    const TRAINING_STATUS_IN_PROGRESS = 1;
    const TRAINING_STATUS_COMPLETED = 2;
    const PROMOTER_STATUS_PENDING = 0;
    const PROMOTER_STATUS_SHOW_TERM = 1;
    const PROMOTER_STATUS_ACCEPTED_TERM = 2;
    const PROMOTER_STATUS_APPROVED = 3;
    const PROMOTER_STATUS_ACTIVATED = 4;
    const PROMOTER_STATUS_REJECTED = 5;


    // Role 0 is the full-access admin. ROLE_ADMIN is kept as a backwards-compat
    // alias; ROLE_SUPER_ADMIN is the canonical name going forward.
    const ROLE_ADMIN = 0;
    const ROLE_SUPER_ADMIN = 0;
    const ROLE_SUB_ADMIN = 1;
    const ROLE_USER = 2;
    protected $fillable = [
        'first_name',
        'email',
        'mobile',
        'role',
        'mobile_verified',
        'password',
        'username',
        'customer_id',
        'can_daily_videos',
        'can_promotion_videos',
        'can_pin_requests',
    ];

    // Permission keys map to the boolean columns added for sub-admins.
    public const PERMISSION_COLUMNS = [
        'daily_videos'     => 'can_daily_videos',
        'promotion_videos' => 'can_promotion_videos',
        'pin_requests'     => 'can_pin_requests',
    ];

    // Promoter daily earning model (single source of truth).
    // `default` = base earning per quiz video (no referral bonus applied yet).
    // `max`     = hard cap per quiz video; bonuses can never push past this.
    // Daily potential = `max` * videosPerDay($level) when fully eligible.
    public const PROMOTER_EARNING_TABLE = [
        0 => ['default' => 2.5,  'max' => 2.5],
        1 => ['default' => 5,    'max' => 35],
        2 => ['default' => 50,   'max' => 230],
        3 => ['default' => 62.5, 'max' => 182.5],
        4 => ['default' => 92.5, 'max' => 265],
    ];

    // Bonus added per video for each *activated* referred user, keyed by that
    // referred user's current_promoter_level. Capped against the level's
    // per-video max in PROMOTER_EARNING_TABLE during computation.
    public const REFERRAL_BONUS_PER_LEVEL = [
        0 => 0,
        1 => 2.5,
        2 => 25,
        3 => 17.5,
        4 => 25,
    ];

    /**
     * Number of quiz videos a promoter is eligible to watch per day at the
     * given level. Mirrors the session/set gating in PromotionVideoController:
     *   • Levels 0, 1, 2 → set1 only per session × 2 sessions  → 2 videos/day
     *   • Levels 3, 4    → set1 + set2 per session × 2 sessions → 4 videos/day
     * Returns 0 for trainees / unknown levels.
     */
    public static function videosPerDay($level): int
    {
        if ($level === null) {
            return 0;
        }
        $lvl = (int) $level;
        if ($lvl < 0 || $lvl > 4) {
            return 0;
        }
        return $lvl >= 3 ? 4 : 2;
    }

    /** Look up the default/max per-video earning for a given promoter level. */
    public static function getLevelEarningInfo($level): array
    {
        if ($level === null || !isset(self::PROMOTER_EARNING_TABLE[$level])) {
            return ['default' => 0, 'max' => 0];
        }
        return self::PROMOTER_EARNING_TABLE[$level];
    }

    /**
     * Current per-video earning potential for THIS user, given:
     *   • their current_promoter_level (provides default + max)
     *   • their activated referred users adding REFERRAL_BONUS_PER_LEVEL,
     *     capped at the per-video max.
     * Returns 0 for trainees (null level). Mirrors the live bonus loop in
     * PromotionVideoController::userPromoterQuizResult so the eligibility
     * surface always matches what the quiz engine would compute.
     */
    public function computeCurrentPerVideoPotential(): float
    {
        $level = $this->current_promoter_level;
        if ($level === null) {
            return 0.0;
        }
        $info = self::getLevelEarningInfo($level);
        $current = (float) $info['default'];
        if ((int) $level === 0) {
            return $current; // L0 has no referral bonus path
        }
        $maxPerVideo = (float) $info['max'];
        $referred = User::where('referred_by', $this->id)
            ->where('is_deleted', 0)
            ->where('is_active', 1)
            ->where('promoter_status', self::PROMOTER_STATUS_ACTIVATED)
            ->where('current_promoter_level', '<=', $level)
            ->where('current_promoter_level', '!=', 0)
            ->get();
        foreach ($referred as $r) {
            $add = self::REFERRAL_BONUS_PER_LEVEL[(int) $r->current_promoter_level] ?? 0;
            $remaining = $maxPerVideo - $current;
            if ($remaining <= 0) {
                break;
            }
            if ($add > $remaining) {
                $current += $remaining;
                break;
            }
            $current += $add;
        }
        return $current;
    }

    /**
     * Returns true if the user can act on the given admin surface.
     * Super-admin always wins; sub-admin must have the explicit flag.
     */
    public function hasAdminPermission(string $key): bool
    {
        if ((int) $this->role === self::ROLE_SUPER_ADMIN) {
            return true;
        }
        if ((int) $this->role !== self::ROLE_SUB_ADMIN) {
            return false;
        }
        $col = self::PERMISSION_COLUMNS[$key] ?? null;
        return $col !== null && (int) ($this->{$col} ?? 0) === 1;
    }

    /** Flat map of all admin permissions for a sub-admin (for JWT / API). */
    public function adminPermissionsMap(): array
    {
        return [
            'daily_videos'     => (int) ($this->can_daily_videos ?? 0) === 1,
            'promotion_videos' => (int) ($this->can_promotion_videos ?? 0) === 1,
            'pin_requests'     => (int) ($this->can_pin_requests ?? 0) === 1,
        ];
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mobile_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        $claims = [
            'id'       => $this->id,
            'email'    => $this->email,
            'username' => $this->username,
            'role'     => $this->role,
        ];

        // Embed sub-admin permissions so the frontend can gate menus from the
        // token without an extra fetch. Super-admin always has full access so
        // the frontend treats missing/all-true equivalently.
        if ((int) $this->role === self::ROLE_SUB_ADMIN) {
            $claims['permissions'] = $this->adminPermissionsMap();
        }

        return $claims;
    }
    // Relationship: who referred this user
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    // Relationship: users referred by this user
    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    /**
     * Users that this user has referred (children)
     */
    public function referredUsers()
    {
        return $this->hasMany(UserReferral::class, 'parent_id');
    }

    /**
     * The referral record showing who referred this user
     */
    public function referral()
    {
        return $this->hasOne(UserReferral::class, 'child_id');
    }

    // Generate referral code (e.g., USER12345)
    public static function generateReferralCode()
    {
        return strtoupper('Star' . uniqid());
    }

    /**
     * Generate the next sequential customer_id for a ROLE_USER registration.
     * Format: STARUP001, STARUP002, ... STARUP999, STARUP1000, ...
     *
     * Race-safe: takes a `FOR UPDATE` lock on the customer_id range while
     * computing MAX, so two concurrent registrations serialize. The unique
     * index on the column is the backstop. MUST be called inside a DB
     * transaction so the lock is held until the new row is written.
     */
    public static function nextCustomerId(): string
    {
        $maxSuffix = (int) \Illuminate\Support\Facades\DB::table('users')
            ->where('customer_id', 'LIKE', 'STARUP%')
            ->lockForUpdate()
            ->selectRaw("MAX(CAST(SUBSTRING(customer_id, 7) AS UNSIGNED)) as n")
            ->value('n');

        $next = $maxSuffix + 1;
        return 'STARUP' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
    public function userTrainingVideos()
    {
        return $this->hasMany(UserTrainingVideo::class);
    }
    public function bankDetail()
    {
        return $this->hasOne(UserBankDetail::class, 'user_id');
    }
}
