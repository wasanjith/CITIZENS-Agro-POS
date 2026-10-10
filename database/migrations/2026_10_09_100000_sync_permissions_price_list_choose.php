<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds pos.price_list.choose (config/pos.php) to databases that already exist, by
 * running the permission sync again. The seeder is safe to run any number of times.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        // Nothing to undo: removing the line from config/pos.php and running the seeder
        // again removes the permission.
    }
};
