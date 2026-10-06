<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real-shop pricing: loose goods with a "under 1 kg" and a "1 kg and above" rate,
     * optional prices (wholesale falls back to retail), and sealed packs that are
     * opened into a loose product.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Weighed out at the counter; prices may have quantity tiers.
            $table->boolean('sold_loose')->default(false)->after('base_unit_id');
            // A sealed pack (Urea 50kg bag) that can be opened into a loose product, and how
            // many of the loose product's base units one pack holds (50 kg).
            $table->foreignId('opens_into_product_id')->nullable()->after('sold_loose')->constrained('products')->nullOnDelete();
            $table->decimal('opens_into_qty', 14, 3)->nullable()->after('opens_into_product_id');
        });

        Schema::table('product_prices', function (Blueprint $table) {
            // Quantity tier in base units: the price applies from this quantity up (0 = any quantity).
            $table->decimal('min_qty', 14, 3)->default(0)->after('unit_id');
            // Null = the price was removed from this moment on (history is kept).
            $table->decimal('price', 15, 2)->nullable()->change();

            // Add the new lookup index first: the product_id foreign key needs one to exist.
            $table->index(['product_id', 'price_list_id', 'unit_id', 'min_qty', 'effective_from'], 'product_prices_tier_lookup');
            $table->dropIndex('product_prices_lookup');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            // The list the price really came from (wholesale, or retail when wholesale has none).
            $table->foreignId('price_list_id')->nullable()->after('unit_id')->constrained()->nullOnDelete();
        });

        Schema::table('grn_lines', function (Blueprint $table) {
            // Packs opened into the loose product when the GRN is posted (base units of this product).
            $table->decimal('open_packs', 14, 3)->default(0)->after('free_qty');
            // What the opened packs weighed (loose product's base units). Null = the nominal weight.
            $table->decimal('open_weighed_qty', 14, 3)->nullable()->after('open_packs');
        });

        Schema::create('pack_openings', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('sealed_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('packs', 14, 3);
            $table->foreignId('loose_product_id')->constrained('products')->restrictOnDelete();
            // packs × opens_into_qty, and what was actually weighed into loose stock.
            $table->decimal('expected_qty', 14, 3);
            $table->decimal('weighed_qty', 14, 3);
            // Cost of the packs taken out; it moves in full to the loose stock.
            $table->decimal('cost_total', 15, 2)->default(0);
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_openings');

        Schema::table('grn_lines', function (Blueprint $table) {
            $table->dropColumn(['open_packs', 'open_weighed_qty']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->index(['product_id', 'price_list_id', 'unit_id', 'effective_from'], 'product_prices_lookup');
            $table->dropIndex('product_prices_tier_lookup');
            $table->dropColumn('min_qty');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opens_into_product_id');
            $table->dropColumn(['sold_loose', 'opens_into_qty']);
        });
    }
};
