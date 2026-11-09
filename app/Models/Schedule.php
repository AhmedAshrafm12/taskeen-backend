<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'request_hash'];

    public function dues()
    {
        return $this->hasMany(Due::class);
    }
}
