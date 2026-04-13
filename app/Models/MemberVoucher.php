<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemberVoucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'discount_rule_id',
        'code',
        'is_used',
        'used_at',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'is_used' => 'boolean',
        'used_at' => 'datetime',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    // Relasi ke "Otak Diskon"
    public function discountRule()
    {
        return $this->belongsTo(DiscountRule::class);
    }

    // Helper: Apakah voucher valid hari ini?
    public function isValid()
    {
        if ($this->is_used) return false;

        $now = now();
        if ($this->valid_from && $now->lt($this->valid_from)) return false;
        if ($this->valid_until && $now->gt($this->valid_until)) return false;

        // Cek juga validitas rule induknya
        if ($this->discountRule && !$this->discountRule->is_active) return false;

        return true;
    }
}
