<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'request_hash'];
}
