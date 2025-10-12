<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserReferral extends Model
{
    
    // Parent user (the one who referred someone)
    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    // Child user (the one who was referred)
    public function child()
    {
        return $this->belongsTo(User::class, 'child_id');
    }

}
