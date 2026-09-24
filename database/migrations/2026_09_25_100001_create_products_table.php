<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('short_code', 20)->unique();
            $table->string('sku', 50)->nullable()->unique();
            $table->string('name', 150);
            $table->string('name_si', 150)->nullable();
            $table->string('name_ta', 150)->nullable();
            $table->text('aliases')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('has_variants')->default(false);
            $table->boolean('track_batches')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->decimal('reorder_qty', 14, 3)->default(0);
            $table->decimal('min_selling_margin_pct', 5, 2)->nullable();
            // Cost per base unit for margin checks until batch costs exist (Phase 2 keeps it at the last GRN cost).
            $table->decimal('reference_cost', 15, 4)->nullable();
            $table->json('attributes')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('sales_velocity_30d', 14, 3)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active']);
        });

        // Fallback search when Meilisearch is down. The ngram parser handles Sinhala and partial words.
        DB::statement('ALTER TABLE products ADD FULLTEXT ft_products (name, name_si, aliases, short_code) WITH PARSER ngram');

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('short_code', 20)->unique();
            $table->string('sku', 50)->nullable();
            $table->string('name', 150);
            $table->json('attributes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            // How many base units one of this unit holds (1 Bag = 50 kg: factor 50).
            $table->decimal('factor', 14, 3);
            $table->boolean('is_default_sale')->default(false);
            $table->boolean('is_default_purchase')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('price_list_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 15, 2);
            $table->dateTime('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'price_list_id', 'unit_id', 'effective_from'], 'product_prices_lookup');
        });

        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 100)->unique();
            $table->json('synonyms');
            $table->timestamps();
        });

        Schema::create('favorite_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Opening stock captured by the product import; posted as OPENING stock movements in Phase 2.
        Schema::create('opening_stock_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('qty', 14, 3);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->string('lot_no', 50)->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_stock_entries');
        Schema::dropIfExists('favorite_products');
        Schema::dropIfExists('search_synonyms');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
