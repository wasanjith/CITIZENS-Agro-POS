<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chart of accounts. System accounts (system_key set) are created by the app and
        // cannot be deleted or change type; the owner can add more (expense heads …).
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('system_key', 40)->nullable()->unique();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['type', 'code']);
        });

        // Double-entry journal. Entries are never edited or deleted: a mistake is undone
        // with a reversal entry (reverses_id). source + event say what posted it.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('date');
            $table->string('description', 255);
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('event', 40)->nullable();
            $table->foreignId('reverses_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id', 'event']);
            $table->index('date');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('memo', 255)->nullable();

            $table->index(['account_id', 'journal_entry_id']);
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('bank_name', 100);
            $table->string('branch', 100)->nullable();
            $table->string('account_no', 40);
            $table->string('account_name', 150);
            $table->string('type', 20);
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->date('opening_date');
            // Card and bank-transfer payments at the cashier land in this account.
            $table->boolean('receives_card_payments')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 15, 2);
            $table->decimal('cleared_balance', 15, 2);
            $table->decimal('difference', 15, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Money in (deposit, transfer in, interest) or out (withdrawal, transfer out,
        // charge) of a bank account. amount is always positive; the type gives the sign.
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('reference', 100)->nullable();
            $table->string('description', 255);
            $table->string('related_type', 60)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->foreignId('bank_reconciliation_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('reconciled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bank_account_id', 'date']);
            $table->index(['related_type', 'related_id']);
        });

        // Cheques received from customers and issued to suppliers.
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10);
            $table->string('number', 30);
            $table->string('bank_name', 100)->nullable();
            $table->string('branch', 100)->nullable();
            $table->date('cheque_date');
            $table->decimal('amount', 15, 2);
            $table->string('party_type', 30)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('party_name', 150)->nullable();
            // Received: the account it was deposited into. Issued: the account it is drawn on.
            $table->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->json('status_history')->nullable();
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('deposited_on')->nullable();
            $table->date('cleared_on')->nullable();
            $table->date('bounced_on')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'cheque_date']);
            $table->index(['direction', 'status']);
            $table->index(['party_type', 'party_id']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('date');
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('paid_from', 20);
            $table->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('drawer_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payee', 150)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('date');
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('paid_from', 20);
            $table->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cheque_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('drawer_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('note', 255)->nullable();
            // Set when the cheque bounced or was cancelled: the payment no longer counts.
            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'date']);
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);

            $table->index('goods_receipt_id');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->decimal('amount_paid', 15, 2)->default(0)->after('total');
        });

        // Drawer cash that left for a finance document (expense, supplier payment, bank deposit).
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('reference_type', 60)->nullable()->after('reason');
            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->foreign('cheque_id')->references('id')->on('cheques')->nullOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete();
            $table->dateTime('reversed_at')->nullable()->after('note');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('cheque_id')->references('id')->on('cheques')->nullOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cheque_id']);
            $table->dropForeign(['bank_account_id']);
        });
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropForeign(['cheque_id']);
            $table->dropForeign(['bank_account_id']);
            $table->dropColumn('reversed_at');
        });
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropColumn(['reference_type', 'reference_id']);
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('cheques');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
