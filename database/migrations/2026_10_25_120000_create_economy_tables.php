<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('slot');
            $table->string('rarity');
            $table->unsignedInteger('price');
            $table->json('sprite_layers');
            $table->boolean('in_shop')->default(false)->index();
            $table->boolean('members_only')->default(false);
            $table->timestamps();
        });

        // Append-only: a balance is the sum of a user's entries, never a stored column.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('reason');
            $table->string('idempotency_key')->unique();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'id']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Owned items keep their definition: retire an item by taking it out of the shop.
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('source');
            $table->foreignId('ledger_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['user_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('items');
    }
};
