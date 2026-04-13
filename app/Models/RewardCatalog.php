<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RewardCatalog extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'name', 'description', 'image',
        'points_required', 'discount_rule_id', 'is_active'
    ];

    public function discountRule()
    {
        return $this->belongsTo(DiscountRule::class);
    }
}
