<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Farmers and other regular customers. Walk-in customers need no record.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('name_si', 150)->nullable();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('nic', 20)->nullable()->index();
            $table->string('address', 255)->nullable();
            $table->string('area', 100)->nullable()->index();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->unsignedSmallInteger('credit_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        // Debit = the customer owes more (credit sale, opening balance).
        // Credit = owes less (payment, return to account).
        Schema::create('customer_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('type', 20);
            $table->string('reference', 40);
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'date']);
            $table->index(['reference_type', 'reference_id']);
        });

        // Money a customer pays towards credit invoices, at the main cashier.
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            // cheques and bank accounts arrive in Phase 5; no foreign keys yet.
            $table->unsignedBigInteger('cheque_id')->nullable();
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->foreignId('drawer_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['drawer_session_id', 'method']);
        });

        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);

            $table->index('sale_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            // Credit invoices: balance_due is what the customer still owes on it.
            $table->date('due_date')->nullable()->after('balance_due');
            $table->unsignedBigInteger('quotation_id')->nullable()->after('customer_id');
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id', 'status']);
            $table->dropColumn(['due_date', 'quotation_id']);
        });
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('customer_ledger');
        Schema::dropIfExists('customers');
    }
};
