<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Brand extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'logo',
        'business_id',
        'status',
    ];

    /**
     * The "booted" method of the model.
     * Cara modern untuk mendaftarkan model event.
     */
    protected static function booted()
    {

    static::creating(function ($model) {
        if (Auth::check() && Auth::user()->business_id) {
            $model->business_id = Auth::user()->business_id;
        }
    });

    /**
     * Event ini berjalan untuk create dan update.
     * Cocok untuk nilai yang perlu diperbarui, seperti slug.
     */
    static::saving(function ($model) {
        // Hanya generate ulang slug jika nama berubah, lebih efisien.
        if ($model->isDirty('name')) {
            $model->slug = Str::slug($model->name);
        }
    });
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
