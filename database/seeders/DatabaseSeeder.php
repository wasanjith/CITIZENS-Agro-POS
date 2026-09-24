<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            TerminalSeeder::class,
            DocumentSequenceSeeder::class,
            CatalogSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call([
                DevelopmentUserSeeder::class,
                DevelopmentCatalogSeeder::class,
            ]);
        }
    }
}
