<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nothing ever drew from sprite_layers: item art is found by slug (furniture/{slug}.png).
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('sprite_layers');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->json('sprite_layers')->nullable()->after('price');
        });
    }
};
