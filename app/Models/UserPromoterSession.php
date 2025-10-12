<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPromoterSession extends Model
{
    //

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function user_promoter()
    {
        return $this->belongsTo(UserPromoter::class);
    }
}
