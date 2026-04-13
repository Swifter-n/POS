<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PickingListItem extends Model
{
    use HasFactory;
    public $timestamps = false;
     protected $guarded = ['id'];

    public function pickingList()
    {
        return $this->belongsTo(PickingList::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function sources()
    {
         return $this->hasMany(PickingListItemSource::class);
    }
}
