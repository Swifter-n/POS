<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'owner',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'manager',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'staff',
                'created_at' => now(),
                'updated_at' => now()
            ]

        ];

        // Bulk insert
        DB::table('roles')->insert($roles);
    }
}
