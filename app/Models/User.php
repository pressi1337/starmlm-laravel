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
    public function userTrainingVideos()
    {
        return $this->hasMany(UserTrainingVideo::class);
    }
    public function bankDetail()
    {
        return $this->hasOne(UserBankDetail::class, 'user_id');
    }
}
