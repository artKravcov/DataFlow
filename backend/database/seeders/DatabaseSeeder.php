<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Organization::factory()->create([
            'name' => 'Test Organization 1',
        ]);

        Organization::factory()->create([
            'name' => 'Test Organization 2',
        ]);
    }
}
