<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which tiles of its wide × deep block a piece covers, as a Footprint mask
     * (['##', '#.'] for an L). Null, as for every existing item, covers them all.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->json('shape')->nullable()->after('floor');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('shape');
        });
    }
};
