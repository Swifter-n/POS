<?php

namespace App\Models;

use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'outlet_id',
        'type_order',
        'sub_total',
        'total_price',
        'total_items',
        'tax',
        'discount',
        'payment_method',
        'table_number',
        'customer_name',
        'customer_phone',
        'email_customer',
        'address_customer',
        'city_customer',
        'post_code_customer',
        'promo_code',
        'status',
        'proof',
        'cashier_id',
        'guest_count',
        'member_id',
        'points_earned',
        'points_redeemed',
        'cash_register_id',
        'applied_rules',
    ];

    protected $casts = [
        'applied_rules' => 'array',
    ];

        protected static function booted()
    {
        // Event "saving" berjalan untuk CREATE dan UPDATE
        static::saving(function ($model) {
            // Pastikan ada user yang login untuk menghindari error
            if (Auth::check()) {
                // Set user_id dengan ID user yang sedang melakukan aksi
                $model->cashier_id = Auth::id();
            }
        });
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // public function promoCode()
    // {
    //     return $this->belongsTo(Promo::class, 'promo_code_id');
    // }

    public function stockMovements()
    {
        return $this->morphMany(InventoryMovement::class, 'reference');
    }

    /**
     * Sebuah Order (POS) bisa menghasilkan satu atau lebih Supplier Invoices
     * (jika bahan baku konsinyasi digunakan).
     */
    public function supplierInvoices(): MorphMany
    {
        return $this->morphMany(SupplierInvoice::class, 'sourceable');
    }

    public function promoCode()
{
    // Ubah 'promo_code_id' menjadi 'promo_code'
    return $this->belongsTo(Promo::class, 'promo_code');
}

public function member() {
    return $this->belongsTo(Member::class);
}

    // Relasi ke Shift
    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

}
