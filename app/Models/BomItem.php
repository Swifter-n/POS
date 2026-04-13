<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * (Berdasarkan migrasi create_boms_and_bom_items_tables)
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'bom_id',
        'product_id', // Produk KOMPONEN (input)
        'usage_type', // <-- Field 'usage_type' (RM, RM_STORE, dll)
        'quantity',
        'uom',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        // Sesuaikan '4' jika presisi desimal Anda berbeda
        'quantity' => 'decimal:4',
    ];

    // Asumsi tabel bom_items punya timestamps (created_at/updated_at)
    // Jika tidak, tambahkan: public $timestamps = false;

    /**
     * Relasi ke Header BOM (induknya).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    /**
     * Relasi ke Produk KOMPONEN (input).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

}
