<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PickingListItemSource extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $guarded = ['id'];
    public function item()
    {
         return $this->belongsTo(PickingListItem::class);
    }
    public function inventory()
    {
         return $this->belongsTo(Inventory::class);
    }
}
