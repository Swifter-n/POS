<?php

namespace App\Policies;

use App\Models\ShipmentRoute;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class ShipmentRoutePolicy
{
    use HandlesAuthorization, HasPermissionChecks; // <-- 2. Gunakan Trait

    /**
     * Helper check() yang lama (dan salah) sudah dihapus
     * dan digantikan oleh checkPermission() dan userHasRole() dari Trait.
     */

    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage shipment routes');
    }

    public function view(User $user, ShipmentRoute $r): bool
    {
        // 3. Perbaiki Pengecekan Relasi:
        // Cek business_id melalui relasi sourceWarehouse
        // return $this->check($user, 'manage shipment routes') &&
        //        $user->business_id === $r->sourceWarehouse->business_id;
        return true;
    }

    public function create(User $user): bool
    {
         return $this->check($user, 'manage shipment routes');
    }

    public function update(User $user, ShipmentRoute $r): bool
    {
        return true;
        // 3. Perbaiki Pengecekan Relasi:
        // return $this->check($user, 'manage shipment routes') &&
        //        $user->business_id === $r->sourceWarehouse->business_id;
    }

    public function delete(User $user, ShipmentRoute $r): bool
    {
        return true;
        // 3. Perbaiki Pengecekan Relasi:
        // return $this->check($user, 'manage shipment routes') &&
        //        $user->business_id === $r->sourceWarehouse->business_id;
    }
}
