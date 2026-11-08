<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each resident wears, one owned item per slot, and their hair, which
     * replaces the one placeholder sprite (docs/assets-plan.md §14.6).
     */
    public function up(): void
    {
        Schema::create('avatar_wearables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('avatar_id')->constrained()->cascadeOnDelete();
            $table->string('slot');
            $table->foreignId('inventory_item_id')->unique()->constrained()->cascadeOnDelete();

            $table->unique(['avatar_id', 'slot']);
        });

        Schema::table('avatars', function (Blueprint $table) {
            $table->renameColumn('sprite_key', 'hair');
        });

        DB::table('avatars')->update(['hair' => 'short']);

        Schema::table('avatars', function (Blueprint $table) {
            $table->string('hair')->default('short')->change();
        });
    }

    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table) {
            $table->renameColumn('hair', 'sprite_key');
        });

        DB::table('avatars')->update(['sprite_key' => 'resident']);

        Schema::table('avatars', function (Blueprint $table) {
            $table->string('sprite_key')->default('resident')->change();
        });

        Schema::dropIfExists('avatar_wearables');
    }
};
