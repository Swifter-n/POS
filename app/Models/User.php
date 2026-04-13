<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nik',
        'name',
        'email',
        'phone',
        'password',
        'role_id',
        'outlet_id',
        'business_id',
        'locationable_id',
        'locationable_type',
        'status',
        'plant_id',
        'position_id',
        'supervisor_id',
        'fcm_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted()
    {
    static::creating(function ($model) {
        if (Auth::check() && Auth::user()->business_id) {
            $model->business_id = Auth::user()->business_id;
        }
    });
    }

    public function routeNotificationForFcm()
{
    return $this->fcm_token;
}

          /**
     * Get the business that owns the user.
     */
    public function business()
    {
        return $this->hasOne(Business::class, 'user_id');
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

        /**
     * Mendapatkan user yang menjadi atasan (supervisor) dari user ini.
     */
    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /**
     * Mendapatkan semua user yang menjadi bawahan dari user ini.
     */
    public function subordinates()
    {
        return $this->hasMany(User::class, 'supervisor_id');
    }

    /**
     * Get the role that owns the user.
     */

    // public function role()
    // {
    //     return $this->belongsTo(Role::class);
    // }

    /**
     * Get the outlet that owns the user.
     */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    /**
     * Get the orders for the user.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function salesTeams()
    {
        return $this->belongsToMany(SalesTeam::class, 'sales_team_user');
    }

    public function locationable()
    {
    return $this->morphTo();
    }

     /**
     * Seorang user bisa membuat banyak dokumen Stock Count.
     */
    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class, 'created_by_user_id');
    }

    /**
     * Seorang user bisa mem-posting banyak dokumen Stock Count.
     */
    public function postedStockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class, 'posted_by_user_id');
    }

    /**
     * Seorang user bisa membuat banyak entri hasil hitungan.
     */
    public function stockCountEntries(): HasMany
    {
        return $this->hasMany(StockCountEntry::class);
    }

    /**
     * Seorang user bisa membuat banyak dokumen Inventory Adjustment.
     */
    public function inventoryAdjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class, 'created_by_user_id');
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }

}
