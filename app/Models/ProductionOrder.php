<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ProductionOrder extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected static function booted()
    {
        // Event "saving" berjalan untuk CREATE dan UPDATE
        static::saving(function ($model) {
            // Pastikan ada user yang login untuk menghindari error
            if (Auth::check()) {
                // Set user_id dengan ID user yang sedang melakukan aksi
                $model->production_order_number = $model->production_order_number ?? 'PROD-' . date('Ym') . '-' . random_int(1000, 9999);
                $model->business_id = Auth::user()->business_id;
                $model->created_by_user_id = Auth::user()->id;
            }
        });
    }

    public function finishedGood()
    {
         return $this->belongsTo(Product::class, 'finished_good_id');
    }
    public function pickingList()
    {
    return $this->morphOne(PickingList::class, 'sourceable');
    }
    public function plant()
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }

    public function items()
    {
        return $this->hasManyThrough(
            BomItem::class, // Model akhir yang ingin diakses
            Bom::class,     // Model perantara
            'product_id', // Foreign key di tabel 'boms' (menghubungkan Bom ke Product)
            'bom_id',     // Foreign key di tabel 'bom_items' (menghubungkan BomItem ke Bom)
            'finished_good_id', // Local key di tabel 'production_orders' (menghubungkan PO ke Product)
            'id'          // Local key di tabel 'boms' (menghubungkan Bom ke BomItem)
        );
    }

}
