<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Furniture that switches on, such as a washer that tumbles: an uploaded
     * facing may carry the animation that plays over its picture while the
     * piece is on (a strip stored beside the picture, and where it lies and
     * how long each frame shows), and each placed piece remembers whether
     * it is on. New pieces start off.
     */
    public function up(): void
    {
        Schema::table('item_art', function (Blueprint $table) {
            $table->string('animation_path')->nullable()->after('height');
            $table->json('animation')->nullable()->after('animation_path');
        });

        Schema::table('home_furniture', function (Blueprint $table) {
            $table->boolean('switched_on')->default(false)->after('rotation');
        });
    }

    public function down(): void
    {
        Schema::table('home_furniture', function (Blueprint $table) {
            $table->dropColumn('switched_on');
        });

        Schema::table('item_art', function (Blueprint $table) {
            $table->dropColumn(['animation_path', 'animation']);
        });
    }
};
