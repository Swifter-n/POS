<?php

namespace App\Policies;

use App\Models\Barcode;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class BarcodePolicy
{
    use HandlesAuthorization, HasPermissionChecks;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage barcodes');
    }
    public function view(User $user, Barcode $r): bool
    {
         return $this->check($user, 'manage barcodes') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage barcodes');
    }
    public function update(User $user, Barcode $r): bool
    {
         return $this->check($user, 'manage barcodes') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Barcode $r): bool
    {
         return $this->check($user, 'manage barcodes') && $user->business_id === $r->business_id;
    }
}
