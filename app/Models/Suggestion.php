<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Suggestion extends Model
{
    public const MAX_WORDS = 500;
    public const MAX_UNREAD_PER_USER = 3;

    protected $fillable = [
        'user_id',
        'content',
        'is_read',
        'read_at',
        'read_by',
        'is_deleted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
