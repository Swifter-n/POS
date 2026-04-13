<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Channel extends Model
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

    public function channelGroup()
    {
    return $this->belongsTo(ChannelGroup::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}
