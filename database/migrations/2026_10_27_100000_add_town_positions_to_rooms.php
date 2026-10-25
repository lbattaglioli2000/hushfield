<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each room sits on the town map, in grid cells, from its map. Null
 * leaves the room off the map's grid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedSmallInteger('town_x')->nullable()->after('home_frequency');
            $table->unsignedSmallInteger('town_y')->nullable()->after('town_x');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['town_x', 'town_y']);
        });
    }
};
