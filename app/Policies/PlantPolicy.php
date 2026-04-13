<?php

namespace App\Policies;

use App\Models\Plant;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class PlantPolicy
{
    use HandlesAuthorization, HasPermissionChecks;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->check($user, 'view plants');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Plant $plant): bool
    {
        // User bisa melihat jika punya izin DAN Plant ada di bisnis yang sama
        return $this->check($user, 'view plants') && $user->business_id === $plant->business_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->check($user, 'create plants');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Plant $plant): bool
    {
        // User bisa update jika punya izin DAN Plant ada di bisnis yang sama
        return $this->check($user, 'edit plants') && $user->business_id === $plant->business_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Plant $plant): bool
    {
        // User bisa delete jika punya izin DAN Plant ada di bisnis yang sama
        // Tambahkan logika lain jika perlu (misal, tidak boleh hapus jika masih punya Warehouse)
        return $this->check($user, 'delete plants') && $user->business_id === $plant->business_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Plant $plant): bool
    {
        // Biasanya sama dengan delete
        return $this->check($user, 'delete plants') && $user->business_id === $plant->business_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Plant $plant): bool
    {
         // Biasanya sama dengan delete, mungkin hanya Owner?
         if ($this->userHasRole($user, 'Owner') && $user->business_id === $plant->business_id) {
             return true;
         }
         // Atau izin terpisah
         return $this->check($user, 'force delete plants') && $user->business_id === $plant->business_id;
    }
}
