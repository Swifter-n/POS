<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Member extends Model
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'business_id',
        'name',
        'email',
        'phone',
        'current_points',
        'tier',
        'qr_token',
        'password',
        'dob',
        'fcm_token',
        'last_transaction_at',
    ];

    protected $hidden = [
        'password',
        'fcm_token',
    ];

    protected $casts = [
        'dob' => 'date',
        'last_transaction_at' => 'datetime',
    ];

    public function vouchers()
    {
        return $this->hasMany(MemberVoucher::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function activeVouchers()
    {
        return $this->vouchers()
            ->where('is_used', false)
            ->where(function($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            });
    }
}
