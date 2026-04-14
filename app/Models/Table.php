<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    use HasFactory;

    // Tambahkan semua field yang bisa diisi
    protected $fillable = [
        'outlet_id',
        'name',
        'code',
        'x_position',
        'y_position',
        'qr_content',
        'capacity',
        'status', // Field fisik untuk Quick Cart (available/occupied)
    ];

    // Field ini yang akan ditambahkan/dimapping saat konversi ke JSON
    protected $appends = [
        'is_occupied',
        'x',
        'y',
        'value',
        'capacity',
        'active_order_id',
    ];

    // Field yang disembunyikan (tidak perlu dikirim)
    protected $hidden = [
        'x_position',
        'y_position',
    ];

    // --- Accessor Mapping ---

    public function getXAttribute() {
        return (double) $this->x_position;
    }

    public function getYAttribute() {
        return (double) $this->y_position;
    }

    public function getValueAttribute() {
        return $this->qr_content;
    }

    // === LOGIKA STATUS MEJA (HYBRID) ===
    // Menggabungkan Status Fisik (Quick Cart) + Logic Order/Reservasi (Open Bill)
    public function getIsOccupiedAttribute()
    {
        // 1. CEK STATUS FISIK (Khusus Quick Cart / Manual Clear)
        // Jika database bilang 'occupied' (karena baru saja ada transaksi quick), maka occupied.
        if ($this->status === 'occupied') {
            return true;
        }

        // 2. CEK ORDER AKTIF (Logic Open Bill / Dine-in belum bayar)
        // Menggunakan namespace lengkap untuk keamanan
        $hasActiveOrder = \App\Models\Order::where('table_number', $this->code)
            ->where('outlet_id', $this->outlet_id)
            ->whereIn('status', ['pending', 'unpaid', 'processing'])
            ->exists();

        if ($hasActiveOrder) return true;

        // 3. CEK RESERVASI AKTIF (Logic Reservasi)
        $now = now();
        $startWindow = $now->copy()->subMinutes(30); // 30 menit yang lalu
        $endWindow = $now->copy()->addMinutes(60);   // Sampai 60 menit ke depan

        $hasActiveReservation = \App\Models\Reservation::where('table_id', $this->id)
        ->whereDate('reservation_time', now()->toDateString()) // Fokus ke hari ini
        ->whereIn('status', ['seated', 'booked'])
        ->exists();

    return $hasActiveReservation;
        // $hasActiveReservation = \App\Models\Reservation::where('table_id', $this->id)
        //     ->where(function($q) use ($startWindow, $endWindow) {
        //         // Grouping OR agar query aman
        //         $q->where('status', 'seated') // Jika seated, pasti occupied
        //           ->orWhere(function($query) use ($startWindow, $endWindow) {
        //               $query->where('status', 'booked')
        //                     ->whereBetween('reservation_time', [$startWindow, $endWindow]);
        //           });
        //     })
        //     ->exists();

        // return $hasActiveReservation;
    }

    // === LOGIKA MENDAPATKAN ID ORDER AKTIF ===
    public function getActiveOrderIdAttribute()
    {
        // Hanya cari order jika meja sedang terisi
        if (!$this->is_occupied) {
            return null;
        }

        $activeOrder = \App\Models\Order::where('table_number', $this->code)
            ->where('outlet_id', $this->outlet_id)
            ->whereIn('status', ['pending', 'unpaid', 'processing'])
            ->first();

        return $activeOrder ? $activeOrder->id : null;
    }

    // Relasi Outlet
    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    // Relasi Kapasitas
    public function getCapacityAttribute()
    {
        return $this->attributes['capacity'] ?? 4;
    }
    
}
