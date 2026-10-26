<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A resident may own several of one item: each inventory row is one piece,
     * placed at most once (home_furniture.inventory_item_id stays unique).
     *
     * A colour variant is an item of its own, grouped under the item it is a
     * colour of (`variant_of`), with the parent's size and shape. The parent
     * is a colour too: `variant_label` and `swatch` name and show each one.
     */
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->index(['user_id', 'item_id']);
            $table->dropUnique(['user_id', 'item_id']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('variant_of')->nullable()->after('slug')->constrained('items')->restrictOnDelete();
            $table->string('variant_label', 30)->nullable()->after('variant_of');
            $table->char('swatch', 7)->nullable()->after('variant_label');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_of');
            $table->dropColumn(['variant_label', 'swatch']);
        });

        // Fails while anyone owns two of one item, rather than throwing pieces away.
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->unique(['user_id', 'item_id']);
            $table->dropIndex(['user_id', 'item_id']);
        });
    }
};
