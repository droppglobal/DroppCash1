<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiDeposit extends Model
{
    use HasFactory;

    public function merchant() {
        return $this->belongsTo(Merchant::class,'user_id');
    }

    public function currency() {
        return $this->belongsTo(Currency::class,'currency_id');
    }

    
}
