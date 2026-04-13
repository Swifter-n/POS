<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\Fluent\Concerns\Has;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'material_code',
        'sku',
        'name',
        'description',
        'product_type',
        'min_sled_days',
        'base_uom',
        'category_id',
        'brand_id',
        'business_id',
        'thumbnail',
        'is_popular',
        'color',
        'price',
        'cost',
        'barcode',
        'status',
        'rating',
        'weight_kg',
        'volume_cbm',
        'is_sellable_pos',
        'calories',
    ];

    protected $casts = [
        'price' => 'float',
        'cost' => 'float',
        'weight_kg' => 'float',
        'volume_cbm' => 'float',
        'is_popular' => 'boolean',
        'status' => 'boolean',
    ];

    protected static function booted()
    {
    static::creating(function ($model) {
        if (Auth::check() && Auth::user()->business_id) {
            $model->business_id = Auth::user()->business_id;
        }
    });
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function productsizes()
    {
        return $this->hasMany(ProductSize::class);
    }

    public function productphotos()
    {
        return $this->hasMany(ProductPhoto::class);
    }

    public function productIngredients()
    {
        return $this->hasMany(ProductIngredient::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function uoms()
    {
    return $this->hasMany(ProductUom::class);
    }

    public function recipes()
    {
         return $this->hasMany(Recipe::class, 'finished_good_id');
    }

    public function priceListItems()
    {
        return $this->hasMany(PriceListItem::class);
    }

        /**
     * Mendefinisikan relasi bahwa sebuah Produk bisa ada di banyak
     * baris item Purchase Order.
     */
    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

     public function bom(): HasOne
    {
        // Merujuk ke 'product_id' di tabel 'boms'
        return $this->hasOne(Bom::class, 'product_id');
    }

    /**
     * Relasi ke BOM (Items) di mana produk ini adalah KOMPONEN (input).
     * (Contoh: "Biji Kopi Sangrai" bisa jadi komponen di banyak BOM Minuman)
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function componentOfBoms(): HasMany
    {
        // Merujuk ke 'product_id' di tabel 'bom_items'
        return $this->hasMany(BomItem::class, 'product_id');
    }

    /**
     * Mendapatkan data UoM lengkap untuk Base UoM produk.
     */
    public function baseUomModel(): BelongsTo
    {
        // Menghubungkan field 'base_uom' (di tabel products)
        // ke field 'uom_name' (di tabel product_uoms)
        return $this->belongsTo(ProductUom::class, 'base_uom', 'uom_name');
    }

 public function barcode()
{
    // 1 Produk punya 1 Barcode
    return $this->morphOne(Barcode::class, 'barcodeable');
}

    public function targetZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'target_zone_id');
    }

    public function addons()
    {
        return $this->belongsToMany(Product::class, 'product_addons', 'product_id', 'addon_id')
                    ->withPivot('id', 'price', 'is_active')
                    ->withTimestamps();
    }

    public function parentProducts()
    {
        return $this->belongsToMany(Product::class, 'product_addons', 'addon_id', 'product_id')
                    ->withPivot('id', 'price', 'is_active')
                    ->withTimestamps();
    }
}
