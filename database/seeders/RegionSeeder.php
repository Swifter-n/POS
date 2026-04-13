<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RegionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Region::create(['region_code' => 'JABO', 'name' => 'Jakarta']);
        Region::create(['region_code' => 'JABAR', 'name' => 'Bandung']);
        Region::create(['region_code' => 'JATIM', 'name' => 'Surabaya']);
    }
}
