<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Area::create(['area_code' => 'JKT', 'name' => 'Jakarta']);
        Area::create(['area_code' => 'BDG', 'name' => 'Bandung']);
        Area::create(['area_code' => 'SBY', 'name' => 'Surabaya']);
    }
}
