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
    const PROMOTER_STATUS_APPROVED = 1;
    const PROMOTER_STATUS_ACTIVATED = 2;
    const PROMOTER_STATUS_REJECTED = 3;
    protected $fillable = [
        'first_name',
        'email',
        'mobile',
        'role',
        'mobile_verified',
        'password',
        'username'
    ];

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
        return [
            'id' => $this->id,
            'email' => $this->email,
            'username' => $this->username,
            'role' => $this->role,
        ];
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

    // Generate referral code (e.g., USER12345)
    public static function generateReferralCode()
    {
        return strtoupper('Star' . uniqid());
    }
    public function userTrainingVideos()
    {
        return $this->hasMany(UserTrainingVideo::class);
    }
}
