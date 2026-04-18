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
        'customer_name',
        'upcoming_reservation',
        'reserved_customer_name',
        'reservation_status',
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

public function getReservationStatusAttribute()
{
    // 1. Cek Order Aktif (Sama seperti sebelumnya)
    $activeOrder = \App\Models\Order::where('table_number', $this->code)
        ->where('outlet_id', $this->outlet_id)
        ->whereIn('status', ['pending', 'unpaid', 'processing'])
        ->latest()->first();

    if ($activeOrder && strtolower($activeOrder->type_order) !== 'reservasi') {
        return null; 
    }

    // 2. PERBAIKAN: Ambil reservasi paling awal yang statusnya belum selesai
    // Kita hapus filter 'whereDate' hari ini agar reservasi besok pun bisa terlihat jika diinginkan,
    // atau gunakan '>=' today()
    $reservation = \App\Models\Reservation::where('table_id', $this->id)
        ->whereIn('status', ['booked', 'seated']) 
        ->where('reservation_time', '>=', now()->startOfDay()) // Ambil mulai dr jam 00:00 hari ini
        ->orderByRaw("CASE WHEN status = 'seated' THEN 1 WHEN status = 'booked' THEN 2 ELSE 3 END")
        ->orderBy('reservation_time', 'asc')
        ->first();

    return $reservation ? $reservation->status : null;
}

public function getReservedCustomerNameAttribute()
    {
        $reservation = \App\Models\Reservation::where('table_id', $this->id)
            ->whereDate('reservation_time', \Carbon\Carbon::today())
            ->whereIn('status', ['booked', 'seated']) 
            // 🔥 PERBAIKAN POSTGRESQL: Gunakan CASE WHEN sebagai pengganti FIELD()
            ->orderByRaw("CASE WHEN status = 'seated' THEN 1 WHEN status = 'booked' THEN 2 ELSE 3 END")
            ->orderBy('reservation_time', 'asc')
            ->first();

        return $reservation ? $reservation->customer_name : null;
    }
    

    public function getUpcomingReservationAttribute()
    {
        // Hanya cari reservasi hari ini yang statusnya masih 'booked' (belum seated)
        $reservation = \App\Models\Reservation::where('table_id', $this->id)
            ->whereDate('reservation_time', \Carbon\Carbon::today())
            ->where('status', 'booked') 
            ->orderBy('reservation_time', 'asc')
            ->first();

        if ($reservation) {
            // Mengembalikan jamnya saja (contoh: "19:00")
            return \Carbon\Carbon::parse($reservation->reservation_time)->format('H:i');
        }

        return null;
    }

    public function getCustomerNameAttribute()
    {
        if (!$this->is_occupied) {
            return null;
        }

        $activeOrder = \App\Models\Order::where('table_number', $this->code)
            ->where('outlet_id', $this->outlet_id)
            ->whereIn('status', ['pending', 'unpaid', 'processing'])
            ->latest()->first();
        if ($activeOrder) return $activeOrder->customer_name;

        $seatedReservation = \App\Models\Reservation::where('table_id', $this->id)
            ->whereDate('reservation_time', \Carbon\Carbon::today())
            ->where('status', 'seated')
            ->first();
            
        // 🔥 PERBAIKAN: Gunakan customer_name, bukan name
        if ($seatedReservation) return $seatedReservation->customer_name;

        $lastOrder = \App\Models\Order::where('table_number', $this->code)
            ->whereDate('created_at', \Carbon\Carbon::today())
            ->latest()->first();
            
        return $lastOrder ? $lastOrder->customer_name : null;
    }

    // === LOGIKA STATUS MEJA (HYBRID) ===
    // Menggabungkan Status Fisik (Quick Cart) + Logic Order/Reservasi (Open Bill)
    public function getIsOccupiedAttribute()
    {
        // 1. Cek dari Kolom Fisik
        if ($this->status === 'occupied') {
            return true;
        }

        // 2. Cek dari Order yang sedang aktif di meja ini (Open Bill)
        $hasActiveOrder = \App\Models\Order::where('table_number', $this->code)
            ->where('outlet_id', $this->outlet_id)
            ->whereIn('status', ['pending', 'unpaid', 'processing'])
            ->exists();

        if ($hasActiveOrder) {
            return true;
        }

        // 3. Cek dari Reservasi
        // 🔥 PERBAIKAN FATAL: Meja hanya 'occupied' fisik jika tamu SUDAH DATANG ('seated').
        // Status 'booked' (belum datang) TIDAK BOLEH membuat meja jadi occupied!
        $hasActiveReservation = \App\Models\Reservation::where('table_id', $this->id)
            ->whereDate('reservation_time', \Carbon\Carbon::today()) 
            ->where('status', 'seated') // <--- HAPUS 'booked' dari sini!
            ->exists();

        return $hasActiveReservation;
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
