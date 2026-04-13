<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Schema;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Bersihkan data lama untuk menghindari duplikasi saat seeding ulang
        Schema::disableForeignKeyConstraints();
        Permission::truncate();
        Role::truncate();
        DB::table('role_has_permissions')->truncate();
        DB::table('model_has_roles')->truncate();
        Schema::enableForeignKeyConstraints();

        // ==========================================================
        // DEFINISI SEMUA PERMISSIONS (HAK AKSES)
        // ==========================================================
        $permissions = [
            // Users & Roles
            'manage users', 'manage roles',

            // Sales
            'create sales orders', 'approve sales orders', 'cancel sales orders',

            // POS
            'create pos orders',

            // Purchasing
            'view purchase orders', 'create purchase orders', 'approve purchase orders', 'cancel purchase orders',

            // purchasing return
            'view purchase return', 'create purchase return', 'approve purchase return', 'cancel purchase return',



            // Inventory & Gudang
            'view goods receipts', 'receive goods',
            'view stock transfers', 'create stock transfers', 'approve stock transfers', 'execute internal transfers',
            'view shipments', 'ship items', 'receive shipped items', 'cancel shipments', 'cancel stock transfers',
            'view production orders', 'create production orders', 'complete production orders', 'confirm sales order delivery',
            'view inventory', 'adjust inventory', 'pick items',
            'view stock counts', 'create stock counts', 'start stock counts', 'submit stock counts', 'post stock count adjustments', 'cancel stock counts',
            'view goods return', 'create goods return', 'approve goods return', 'cancel goods return',

            // Master Data
            'manage products', 'manage customers', 'manage suppliers', 'manage warehouses', 'manage outlets', 'manage business settings',
            'manage categories', 'manage brands', 'manage locations', 'manage zones', 'manage channels', 'manage price lists', 'manage priority levels',
            'manage regions', 'manage shipping rates', 'manage channel groups', 'manage vendors', 'manage fleets', 'manage shipment routes',
            'manage barcodes', 'manage promos', 'manage positions', 'manage discount rules', 'manage invoices', 'manage sales teams', 'manage picking lists',
            'manage debit notes', 'manage areas'
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // ==========================================================
        // DEFINISI SEMUA ROLES & PENUGASAN PERMISSION
        // ==========================================================

        // --- Level Operasional Lapangan ---

        $baristaRole = Role::create(['name' => 'Barista']);
        $baristaRole->givePermissionTo(['create pos orders']);

        $salesmanRole = Role::create(['name' => 'Salesman']);
        $salesmanRole->givePermissionTo(['create sales orders']);

        $staffGudangRole = Role::create(['name' => 'Staff Gudang']);
        $staffGudangRole->givePermissionTo([
            'receive goods', 'ship items', 'receive shipped items',
            'start stock counts', 'submit stock counts'
        ]);

        // --- Level Staf Kantor ---

        $staffOfficeRole = Role::create(['name' => 'Staff Office']);
        $staffOfficeRole->givePermissionTo([
            'create purchase orders', 'create stock transfers'
        ]);

        // --- Level Manajerial & Supervisi ---

        $kepalaTokoRole = Role::create(['name' => 'Kepala Toko']);
        $kepalaTokoRole->syncPermissions(array_merge(
            $baristaRole->permissions->pluck('name')->toArray(),
            ['view stock counts', 'create stock counts', 'start stock counts', 'submit stock counts']
        ));

        $supervisorSalesRole = Role::create(['name' => 'Supervisor Sales']);
        $supervisorSalesRole->syncPermissions(array_merge(
            $salesmanRole->permissions->pluck('name')->toArray(),
            ['approve sales orders', 'cancel sales orders']
        ));

        $managerGudangRole = Role::create(['name' => 'Manager Gudang']);
        $managerGudangRole->givePermissionTo([
            'view goods receipts', 'receive goods',
            'view stock transfers', 'create stock transfers', 'approve stock transfers', 'execute internal transfers',
            'view shipments', 'ship items', 'receive shipped items', 'cancel shipments',
            'view production orders', 'create production orders', 'complete production orders',
            'view inventory', 'adjust inventory',
            'view stock counts', 'create stock counts', 'post stock count adjustments',
        ]);

        // --- Level Manajerial Regional/Area ---
        // (ASM: Area Sales Manager, RSM: Regional Sales Manager)
        $asmRole = Role::create(['name' => 'ASM']);
        $asmRole->syncPermissions($supervisorSalesRole->permissions->pluck('name')); // Warisi hak akses Supervisor

        $rsmRole = Role::create(['name' => 'RSM']);
        $rsmRole->syncPermissions($asmRole->permissions->pluck('name')); // Warisi hak akses ASM

        // --- Level Eksekutif / Pimpinan ---

        $headRole = Role::create(['name' => 'Head Operations']); // Misal: Head of Operations
        $headRole->syncPermissions(array_merge(
            $managerGudangRole->permissions->pluck('name')->toArray(),
            $supervisorSalesRole->permissions->pluck('name')->toArray(),
            ['approve purchase orders']
        ));

        // Owner / Super Admin
        $ownerRole = Role::create(['name' => 'Owner']);
        $ownerRole->givePermissionTo(Permission::all());
    }
}
