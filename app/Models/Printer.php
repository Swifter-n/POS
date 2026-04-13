<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Printer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
            'name',
            'connection_type',
            'mac_address',
            'ip_address',
            'paper_width',
            'default',
            'outlet_id',
        ];

        public function outlet()
        {
            return $this->belongsTo(Outlet::class);
        }
}
