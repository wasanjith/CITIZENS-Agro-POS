<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A bill. Numbered when a counter prints it (INVOICED); money and stock move when the
        // cashier settles it (SETTLED). A held bill (ON_HOLD) has no number and reserves nothing.
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 30)->nullable()->unique();
            $table->string('status', 20);
            // customers arrive in Phase 4; no foreign key yet.
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->foreignId('price_list_id')->constrained()->restrictOnDelete();
            $table->uuid('cart_uuid')->index();

            $table->foreignId('invoiced_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('invoiced_terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->dateTime('invoiced_at')->nullable();

            $table->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('settled_terminal_id')->nullable()->constrained('terminals')->restrictOnDelete();
            $table->dateTime('settled_at')->nullable();
            $table->foreignId('drawer_session_id')->nullable()->constrained()->restrictOnDelete();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('line_discount_total', 15, 2)->default(0);
            $table->decimal('bill_discount', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('payment_method_intent', 20);
            $table->decimal('tendered_amount', 15, 2)->nullable();
            $table->decimal('change_due', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->nullable();
            $table->string('note', 255)->nullable();

            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('voided_at')->nullable();

            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->string('settle_idempotency_key', 64)->nullable()->unique();
            $table->unsignedSmallInteger('print_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'invoiced_terminal_id']);
            $table->index('invoiced_at');
            $table->index('settled_at');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->decimal('factor', 14, 3);
            $table->decimal('base_qty', 14, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->decimal('cost_total', 15, 2)->nullable();
            $table->string('short_code_snapshot', 30);
            $table->string('name_snapshot', 191);
            $table->string('name_si_snapshot', 191)->nullable();
            $table->string('unit_snapshot', 30);
            $table->string('unit_si_snapshot', 30)->nullable();
            // Batches holding stock for this line while the invoice waits for settlement.
            $table->json('reservations')->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();

            $table->index(['product_id', 'variant_id']);
        });

        // Batches a settled line was issued from, with their cost (COGS).
        Schema::create('sale_item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->decimal('base_qty', 14, 3);
            $table->decimal('unit_cost', 15, 4);
        });

        // Money received for a sale (negative amount = refund from the drawer).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('method', 20);
            $table->decimal('amount', 15, 2);
            $table->decimal('tendered', 15, 2)->nullable();
            $table->string('reference', 100)->nullable();
            // cheques and bank accounts arrive in Phase 5; no foreign keys yet.
            $table->unsignedBigInteger('cheque_id')->nullable();
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('drawer_session_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['drawer_session_id', 'method']);
        });

        // Discount / price override / void / refund requests from a counter to the cashier.
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('cart_uuid')->nullable()->index();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->json('payload');
            $table->string('status', 20);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // Live Billing activity and loss-prevention trail. Pruned after 90 days.
        Schema::create('counter_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('cart_uuid')->nullable();
            $table->string('type', 20);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['terminal_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        // Every print and reprint on a thermal printer.
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 20);
            $table->unsignedBigInteger('document_id')->nullable();
            $table->boolean('is_copy')->default(false);
            // Set when the terminal reports that the browser sent the page to the printer.
            $table->dateTime('printed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_type', 'document_id']);
            $table->index(['terminal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('counter_events');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_item_batches');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
