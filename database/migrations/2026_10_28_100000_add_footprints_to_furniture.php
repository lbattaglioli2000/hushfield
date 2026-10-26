<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing items, and so every existing placement, stand on one tile until staff size them.
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedTinyInteger('wide')->default(1)->after('sprite_layers');
            $table->unsignedTinyInteger('deep')->default(1)->after('wide');
            $table->unsignedSmallInteger('height')->default(18)->after('deep');
            $table->boolean('floor')->default(false)->after('height');
        });

        Schema::table('home_furniture', function (Blueprint $table) {
            $table->unsignedTinyInteger('rotation')->default(0)->after('y');
            // A piece may stand on a rug, anchored on the same tile. PlaceFurniture checks overlaps under a lock.
            $table->dropUnique(['home_id', 'x', 'y']);
            $table->index('home_id');
        });
    }

    public function down(): void
    {
        Schema::table('home_furniture', function (Blueprint $table) {
            $table->dropIndex(['home_id']);
            $table->unique(['home_id', 'x', 'y']);
            $table->dropColumn('rotation');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['wide', 'deep', 'height', 'floor']);
        });
    }
};
