<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    use HasFactory;

        protected $fillable = [
        'business_id', 'outlet_id', 'user_id', 'status',
        'opening_amount', 'closing_amount',
        'total_cash_sales', 'total_card_sales', 'total_points_redeemed',
        'opened_at', 'closed_at', 'closing_note'
    ];

    public function transactions() {
        return $this->hasMany(CashRegisterTransaction::class);
    }

}
