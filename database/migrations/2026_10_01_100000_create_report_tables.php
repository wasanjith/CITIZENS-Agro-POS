<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sales per day and counter, rebuilt every night for the last weeks (reports over 12 months read it).
        Schema::create('daily_sales_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('invoices')->default(0);
            $table->decimal('gross', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('sales', 15, 2)->default(0);
            $table->decimal('returns', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('cost', 15, 2)->default(0);
            $table->unsignedInteger('voids')->default(0);
            $table->decimal('void_value', 15, 2)->default(0);
            $table->decimal('pay_cash', 15, 2)->default(0);
            $table->decimal('pay_card', 15, 2)->default(0);
            $table->decimal('pay_bank_transfer', 15, 2)->default(0);
            $table->decimal('pay_cheque', 15, 2)->default(0);
            $table->decimal('pay_credit', 15, 2)->default(0);
            $table->decimal('pay_split', 15, 2)->default(0);
            $table->timestamp('built_at')->nullable();

            $table->unique(['date', 'terminal_id']);
        });

        // Indexes for the report filters.
        Schema::table('sales', fn (Blueprint $table) => $table->index('voided_at'));
        Schema::table('payments', fn (Blueprint $table) => $table->index('created_at'));
        Schema::table('print_jobs', fn (Blueprint $table) => $table->index(['is_copy', 'created_at']));
        Schema::table('approval_requests', fn (Blueprint $table) => $table->index(['type', 'created_at']));
        Schema::table('goods_receipts', fn (Blueprint $table) => $table->index(['status', 'received_at']));
        Schema::table('customer_payments', fn (Blueprint $table) => $table->index('date'));
        Schema::table('stocktakes', fn (Blueprint $table) => $table->index('posted_at'));
        Schema::table('delegations', fn (Blueprint $table) => $table->index('starts_at'));
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['event', 'created_at']);
            $table->index(['subject_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['event', 'created_at']);
            $table->dropIndex(['subject_type', 'created_at']);
        });
        Schema::table('delegations', fn (Blueprint $table) => $table->dropIndex(['starts_at']));
        Schema::table('stocktakes', fn (Blueprint $table) => $table->dropIndex(['posted_at']));
        Schema::table('customer_payments', fn (Blueprint $table) => $table->dropIndex(['date']));
        Schema::table('goods_receipts', fn (Blueprint $table) => $table->dropIndex(['status', 'received_at']));
        Schema::table('approval_requests', fn (Blueprint $table) => $table->dropIndex(['type', 'created_at']));
        Schema::table('print_jobs', fn (Blueprint $table) => $table->dropIndex(['is_copy', 'created_at']));
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('sales', fn (Blueprint $table) => $table->dropIndex(['voided_at']));
        Schema::dropIfExists('daily_sales_summaries');
    }
};
