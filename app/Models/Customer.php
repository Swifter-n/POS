<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Customer extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = ['id'];

    protected static function booted()
    {
        // Event "saving" berjalan untuk CREATE dan UPDATE
        static::saving(function ($model) {
            // Pastikan ada user yang login untuk menghindari error
            if (Auth::check()) {
                $model->business_id = Auth::user()->business_id;
            }
        });
    }

     /**
     * Secara dinamis menentukan level prioritas customer.
     */
    public function getPriorityLevelAttribute()
    {
        // Ambil semua level, diurutkan dari yang paling tinggi
        $levels = PriorityLevel::where('business_id', $this->business_id)
                                ->orderBy('level_order', 'desc')
                                ->get();

        // Cari level pertama yang memenuhi kriteria
        foreach ($levels as $level) {
            if ($this->total_order_count >= $level->min_orders && $this->total_spend >= $level->min_spend) {
                return $level; // Kembalikan objek PriorityLevel
            }
        }

        return null; // Atau level default jika tidak ada yang cocok
    }

    public function business()
    {
         return $this->belongsTo(Business::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

        /**
     * Tim sales yang menangani customer ini.
     */
    public function salesTeam()
    {
        return $this->belongsTo(SalesTeam::class);
    }

    /**
     * (Opsional) Jika ingin tahu salesman utama,
     * bisa membuat relasi tidak langsung melalui tim.
     */
    public function mainSalesman()
    {
        // Contoh: Mengambil team leader dari sales team sebagai salesman utama
        return $this->salesTeam->teamLeader();
    }

    public function village()
{
    return $this->belongsTo(Village::class);
}

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function channel()
    {
    return $this->belongsTo(Channel::class);
    }

    public function customerServiceLevel()
    {
    return $this->belongsTo(CustomerServiceLevel::class);
    }

    public function termsOfPayment()
    {
    return $this->belongsTo(TermsOfPayment::class);
    }
}
