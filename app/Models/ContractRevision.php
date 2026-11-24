<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractRevision extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'request_hash'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'options' => 'array'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
}
