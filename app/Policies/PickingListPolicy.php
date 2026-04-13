<?php

namespace App\Policies;

use App\Models\PickingList;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class PickingListPolicy
{
        use HandlesAuthorization, HasPermissionChecks;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->check($user, 'view picking lists');
    }

    /**
     * Determine whether the user can view the model.
     * Ini adalah pengaman untuk URL, memastikan picker tidak bisa membuka tugas orang lain.
     */
    public function view(User $user, PickingList $pickingList): bool
    {
        if ($this->userHasRole($user, 'Owner') || $this->userHasRole($user, 'Manager Gudang')) {
            return true;
        }
        return $this->check($user, 'execute picking tasks') && $user->id === $pickingList->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     * INI ADALAH PERBAIKAN UTAMA:
     * Argumen kedua sekarang nullable (?PickingList)
     */
    public function update(User $user, ?PickingList $pickingList = null): bool
    {
        // Cek izin umum (untuk registrasi rute)
        if ($pickingList === null) {
            return $this->check($user, 'execute picking tasks') ||
                   $this->userHasRole($user, 'Manager Gudang');
        }

        // Cek izin spesifik (untuk melihat tombol di rekaman)
        if ($this->userHasRole($user, 'Owner') || $this->userHasRole($user, 'Manager Gudang')) {
            return true; // Manajer bisa mengedit/melihat
        }

        // Picker hanya bisa mengupdate tugasnya sendiri
        return $this->check($user, 'execute picking tasks') && $user->id === $pickingList->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PickingList $pickingList): bool
    {
        return $this->check($user, 'cancel picking lists') && $pickingList->status !== 'completed';
    }
}
