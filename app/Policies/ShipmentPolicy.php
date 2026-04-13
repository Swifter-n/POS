<?php

namespace App\Policies;

use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class ShipmentPolicy
{
    use HandlesAuthorization, HasPermissionChecks;

     /**
     * Perform pre-authorization checks.
     * Owner bisa melakukan apa saja.
     */
    public function before(User $user, string $ability): bool|null
    {
        if ($this->userHasRole($user, 'Owner')) {
            return true;
        }
        return null; // Lanjutkan ke pengecekan permission
    }

    /**
     * Determine whether the user can view any models.
     * (Mengontrol visibilitas menu)
     */
    public function viewAny(User $user): bool
    {
        // Izinkan jika user punya izin UMUM untuk melihat shipment
        return true;
        //return $this->check($user, 'view shipments');
    }

    /**
     * Determine whether the user can view the model.
     * (Mengontrol siapa yang bisa membuka detail shipment)
     */
    public function view(User $user, Shipment $shipment): bool
    {
        // Cek izin dasar DAN apakah user bertugas di lokasi yang relevan
        return true;
        //return $this->check($user, 'view shipments') && $this->isUserRelatedToShipment($user, $shipment);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false; // Dibuat dari SO/ST
    }

    /**
     * Determine whether the user can update the model.
     * (Mengontrol siapa yang bisa mengakses halaman 'edit' dan 'view')
     */
    public function update(User $user, ?Shipment $shipment = null): bool
    {
        // Pengecekan umum untuk mendaftarkan rute 'edit'
        if ($shipment === null) {
            return $this->check($user, 'ship items') ||
                   $this->check($user, 'receive shipped items') ||
                   $this->check($user, 'confirm sales order delivery'); // <-- 2. Tambahkan permission SO
        }

        // Pengecekan spesifik: user harus punya izin DAN terkait dengan shipment
        $hasPermission = $this->check($user, 'ship items') ||
                         $this->check($user, 'receive shipped items') ||
                         $this->check($user, 'confirm sales order delivery');

        return $hasPermission && $this->isUserRelatedToShipment($user, $shipment);
    }

    /**
     * Determine whether the user can delete the model.
     * (Ini sama dengan 'cancel')
     */
    public function delete(User $user, Shipment $shipment): bool
    {
        return $this->check($user, 'cancel shipments') && $this->isUserRelatedToShipment($user, $shipment);
    }

    // ==========================================================
    // HELPER FUNCTIONS
    // ==========================================================

    /**
     * Helper untuk mengecek apakah user adalah PENGIRIM atau PENERIMA/PENANGGUNG JAWAB.
     */
    private function isUserRelatedToShipment(User $user, Shipment $shipment): bool
    {
        $userLocation = $user->locationable;

        // 1. Cek apakah user adalah PENGIRIM (Staff Gudang)
        $isSender = ($user->locationable_type === Warehouse::class &&
                     $user->locationable_id === $shipment->source_warehouse_id);

        if ($isSender) {
            return true;
        }

        // 2. Cek apakah user adalah PENERIMA (Internal/Eksternal)
        $sourceable = $shipment->sourceable;

        // 2a. Jika ini Stock Transfer, cek apakah user bertugas di lokasi tujuan
        if ($sourceable instanceof StockTransfer) {
            if ($userLocation) {
                $destinationParent = $sourceable->destinationLocation?->locatable;
                if ($destinationParent && $destinationParent->is($userLocation)) {
                    return true;
                }
            }
        }

        // 2b. === LOGIKA BARU UNTUK SALES ORDER ===
        // Jika ini Sales Order, cek apakah user adalah Salesman yang bertanggung jawab
        if ($sourceable instanceof SalesOrder) {
            if ($user->id === $sourceable->salesman_id) {
                return true;
            }
        }

        return false;
    }
}
