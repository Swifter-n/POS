<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    protected $fillable = [
        'outlet_id', 'table_id', 'customer_name', 'customer_phone',
        'guest_count', 'reservation_time', 'status', 'notes'
    ];

    protected $casts = [
        'reservation_time' => 'datetime',
    ];

    public function table()
    {
        return $this->belongsTo(Table::class);
    }
}
