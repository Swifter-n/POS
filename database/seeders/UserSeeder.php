<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role; // <-- 1. Import model Role dari Spatie

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 2. Cari role 'Owner' yang sudah dibuat oleh RolesAndPermissionsSeeder
        $ownerRole = Role::where('name', 'Owner')->first();

        if (!$ownerRole) {
            $this->command->error('Role "Owner" not found. Please run RolesAndPermissionsSeeder first.');
            return;
        }

        // 3. Buat user dan simpan ke dalam variabel
        $user = User::create([
            'name' => 'Admin Taiyo',
            'nik' => '123456',
            'email' => 'admin@taiyo.com',
            'phone' => '082111148073',
            'password' => bcrypt('password'),
            'business_id' => '1',
            'status' => true
        ]);

        // 4. Berikan role 'Owner' kepada user yang baru dibuat
        $user->assignRole($ownerRole);

        $this->command->info('Admin Taiyo user created and assigned the Owner role successfully.');
    }
}
