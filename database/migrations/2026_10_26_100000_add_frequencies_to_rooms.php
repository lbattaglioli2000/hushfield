<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each room sits on the radio's tuning dial, from its map. A residence
 * room's homes share home_frequency; the room itself keeps frequency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->decimal('frequency', 4, 1)->nullable()->after('kind');
            $table->decimal('home_frequency', 4, 1)->nullable()->after('frequency');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['frequency', 'home_frequency']);
        });
    }
};
