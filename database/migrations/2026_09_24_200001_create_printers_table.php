<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->foreignId('terminal_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('windows_name', 120)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedSmallInteger('paper_width_mm')->default(80);
            $table->unsignedSmallInteger('dpi')->default(203);
            $table->boolean('has_cash_drawer')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
