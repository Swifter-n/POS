<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegisterTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_register_id',
        'amount',
        'transaction_type', // 'sell', 'refund', 'add_cash', 'payout', 'opening'
        'pay_method', // 'cash', 'card'
        'type', // 'credit' (masuk), 'debit' (keluar)
        'order_id',
        'notes',
    ];

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }
}
