<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A lot of one product (or variant) with its own cost and expiry. Products without batch
        // tracking keep all their stock in one "default" batch (default_key = "product-variant").
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('lot_no', 50)->nullable();
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            // Cost per base unit. The default batch keeps a moving average.
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->dateTime('received_at');
            $table->unsignedBigInteger('grn_line_id')->nullable()->index();
            $table->string('default_key', 30)->nullable()->unique();
            $table->timestamps();

            $table->index(['product_id', 'variant_id', 'expiry_date']);
            $table->index('expiry_date');
        });

        // Cached balance per batch, changed only by StockService in the same transaction as the movement.
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            // A batch belongs to exactly one product/variant, so batch_id alone is unique.
            $table->foreignId('batch_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('qty_on_hand', 14, 3)->default(0);
            $table->decimal('qty_reserved', 14, 3)->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->index(['product_id', 'variant_id']);
        });

        // Append-only stock ledger.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('qty', 14, 3);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'variant_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['type', 'created_at']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('reason', 20);
            $table->string('status', 20);
            $table->decimal('total_value', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            // Null = the product's default batch (created when the adjustment is posted).
            $table->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            // Signed, in base units: + adds stock, − removes it.
            $table->decimal('qty', 14, 3);
            $table->decimal('unit_cost', 15, 4)->default(0);
        });

        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            // Category ids counted; null = whole shop.
            $table->json('scope')->nullable();
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            // Null = product had no stock when counting started; counted stock goes to its default batch.
            $table->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('system_qty', 14, 3);
            $table->decimal('counted_qty', 14, 3)->nullable();
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('counted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktakes');
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('batches');
    }
};
