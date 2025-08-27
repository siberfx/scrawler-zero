<?php

namespace Database\Seeders;

use App\Models\OrganizationCategory;
use Illuminate\Database\Seeder;

class OrganizationCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Ministerie'],
            ['name' => 'Gemeente'],
            ['name' => 'Provincie'],
            ['name' => 'Waterschap'],
            ['name' => 'Zelfstandig Bestuursorgaan'],
            ['name' => 'Rechtspersoon met Wettelijke Taak'],
            ['name' => 'Openbaar Lichaam'],
            ['name' => 'Overig'],
        ];

        foreach ($categories as $category) {
            OrganizationCategory::firstOrCreate(['name' => $category['name']]);
        }
    }
}
