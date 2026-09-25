<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who holds the cash drawer of a terminal, from opening float to count.
        Schema::create('drawer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->foreignId('holder_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('opened_at');
            $table->decimal('opening_float', 15, 2)->default(0);
            $table->json('opening_denominations')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('variance', 15, 2)->nullable();
            $table->json('denominations')->nullable();
            $table->string('close_reason', 20)->nullable();
            $table->string('close_note', 255)->nullable();
            $table->foreignId('previous_session_id')->nullable()->constrained('drawer_sessions')->nullOnDelete();
            // true while open, NULL once closed: the unique index allows one open session per terminal.
            $table->boolean('is_open')->nullable()->default(true);
            $table->timestamps();

            $table->unique(['terminal_id', 'is_open']);
            $table->index(['holder_user_id', 'is_open']);
            $table->index('opened_at');
        });

        // Pay in / pay out / safe drop / bank deposit during a drawer session.
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drawer_session_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        // Delegations already point at the drawer session they were created for;
        // expiry_processed_at marks expired ones the every-minute job has announced.
        Schema::table('delegations', function (Blueprint $table) {
            $table->timestamp('expiry_processed_at')->nullable()->after('revoked_by');
        });
    }

    public function down(): void
    {
        Schema::table('delegations', function (Blueprint $table) {
            $table->dropColumn('expiry_processed_at');
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('drawer_sessions');
    }
};
