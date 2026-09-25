<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('contact_person', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            // What the shop owed this supplier when the system went live.
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_code', 50)->nullable();
            // Cost per base unit on the last goods receipt.
            $table->decimal('last_cost', 15, 4)->nullable();
            $table->unsignedSmallInteger('lead_days')->nullable();
            $table->timestamps();

            $table->primary(['supplier_id', 'product_id']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('rejected_reason', 255)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'order_date']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('po_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->decimal('base_qty', 14, 3);
            // Cost per purchase unit. Null until a Manager/Owner fills it (Sales Staff never see it).
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('received_base_qty', 14, 3)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_no', 50)->nullable();
            $table->dateTime('received_at');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
        });

        Schema::create('grn_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('po_line_id')->nullable()->constrained('po_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->decimal('base_qty', 14, 3);
            // Cost per purchase unit (unit_id).
            $table->decimal('unit_cost', 15, 2);
            $table->string('lot_no', 50)->nullable();
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            // Extra units the supplier gave for free, in the same unit as qty.
            $table->decimal('free_qty', 14, 3)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->string('reason', 255)->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('supplier_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            // In base units.
            $table->decimal('qty', 14, 3);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('line_total', 15, 2);
        });

        // Credit = the shop owes more (goods received). Debit = owes less (returns, payments).
        Schema::create('supplier_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('type', 20);
            $table->string('reference', 40);
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['supplier_id', 'date']);
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->foreign('grn_line_id')->references('id')->on('grn_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['grn_line_id']);
        });

        Schema::dropIfExists('supplier_ledger');
        Schema::dropIfExists('supplier_return_lines');
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('grn_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('po_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('suppliers');
    }
};
