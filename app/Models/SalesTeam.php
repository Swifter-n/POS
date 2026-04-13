<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class SalesTeam extends Model
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
     * Tim ini milik bisnis mana.
     */
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Pemimpin tim ini (seorang User).
     */
    public function teamLeader()
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }

    /**
     * Anggota tim ini (banyak User/Salesman).
     * Terhubung melalui tabel pivot 'sales_team_user'.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'sales_team_user');
    }

    /**
     * Customer yang di-handle oleh tim ini.
     */
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

        /**
     * Relasi Many-to-Many ke model Area.
     * Satu tim bisa meng-cover banyak area.
     */
    public function areas()
    {
        return $this->belongsToMany(Area::class, 'area_sales_team_pivot');
    }

}
