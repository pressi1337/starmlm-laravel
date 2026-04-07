<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSuggestion extends Model
{
    const STATUS_PENDING = 0;
    const STATUS_REACTED = 1;

    protected $fillable = [
        'user_id',
        'suggestion_text',
        'admin_response_text',
        'admin_reaction_emoji',
        'admin_reacted_at',
        'admin_reacted_by',
        'status',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
