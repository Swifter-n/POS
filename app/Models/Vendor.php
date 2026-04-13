<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Vendor extends Model
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
                $model->business_id = Auth::user()->business_id;
            }
        });
    }


     /**
     * Dapatkan semua Purchase Order yang ditujukan ke vendor ini (jika tipenya Supplier).
     */
    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function shippingRates()
{
    return $this->hasMany(ShippingRate::class);
}

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'supplier_id');
    }

    /**
     * Seorang Supplier bisa memiliki banyak Debit Note.
     */
    public function debitNotes(): HasMany
    {
        return $this->hasMany(DebitNote::class, 'supplier_id');
    }

    /**
     * Seorang Supplier bisa memiliki banyak tagihan hutang (Supplier Invoices).
     */
    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class, 'supplier_id');
    }

     /**
     * Mendefinisikan relasi bahwa setiap Vendor/Supplier dimiliki
     * oleh satu Purchasing Group.
     */
    public function purchasingGroup(): BelongsTo
    {
        return $this->belongsTo(PurchasingGroup::class);
    }

     /**
     * Relasi ke Business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

       public function area()
    {
        return $this->belongsTo(Area::class);
    }

}
