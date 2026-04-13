<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphedByMany;

class SalesOrder extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = ['id'];
    protected $casts = ['order_date' => 'date'];

    public function business() {
        return $this->belongsTo(Business::class);
    }

    public function customer() {
        return $this->belongsTo(Customer::class);
    }

    public function termsOfPayment(): BelongsTo
    {
        return $this->belongsTo(TermsOfPayment::class, 'terms_of_payment_id');
    }

    public function salesman() {
        return $this->belongsTo(User::class, 'salesman_id');
    }

    public function items(){
        return $this->hasMany(SalesOrderItem::class);
    }

    // Relasi ke modul Shipment (Satu SO bisa punya banyak pengiriman)
   public function shipments()
    {
        return $this->morphedByMany(
            Shipment::class, // Model target
            'sourceable', // Nama 'name' polimorfik
            'shipment_sourceables', // Nama tabel pivot
            'sourceable_id', // Foreign key di pivot untuk model INI
            'shipment_id' // Foreign key di pivot untuk model TARGET
        )
        ->using(ShipmentSourceable::class) // Gunakan model pivot kustom
        ->withPivot('business_id');
    }

    // Relasi ke modul Picking List (Satu SO bisa punya banyak picking list)
    public function pickingLists() {
        return $this->morphMany(PickingList::class, 'sourceable');
    }

    // Relasi ke pergerakan stok
    public function stockMovements() {
        return $this->morphMany(InventoryMovement::class, 'reference');
    }

     /**
     * Sebuah Sales Order bisa menghasilkan satu atau lebih Supplier Invoices
     * (jika item konsinyasi terjual).
     */
    public function supplierInvoices(): MorphMany
    {
        return $this->morphMany(SupplierInvoice::class, 'sourceable');
    }

    public function invoice(): HasOne
    {
        // Asumsi tabel 'invoices' memiliki 'sales_order_id'
        return $this->hasOne(Invoice::class, 'sales_order_id');
    }

    public function supplyingPlant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'supplying_plant_id');
    }
}
