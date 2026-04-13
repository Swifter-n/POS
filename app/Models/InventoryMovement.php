<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryMovement extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = ['id'];
    /**
     * Beritahu Eloquent untuk secara otomatis mengubah kolom ini
     * dari array ke JSON saat menyimpan, dan sebaliknya saat membaca.
     */
    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'quantity_change' => 'float',
        'stock_after_move' => 'float',
    ];

    public function inventory()
    {
         return $this->belongsTo(Inventory::class);
    }
    public function user()
    {
         return $this->belongsTo(User::class);
    }
    public function reference()
    {
         return $this->morphTo();
    }
}
