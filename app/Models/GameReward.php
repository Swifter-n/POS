<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'name', 'type', 'discount_rule_id',
        'point_reward', 'probability', 'color_code', 'is_active'
    ];

    public function discountRule()
    {
        return $this->belongsTo(DiscountRule::class);
    }
}
