<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The VFA becomes a lobby with several theaters, each with its own
     * programme and house lights. Films already on the programme go to the
     * first theater. A premiere starts at its own time; the queue flows around it.
     */
    public function up(): void
    {
        Schema::table('screenings', function (Blueprint $table) {
            $table->string('theater', 64)->default('vfa-1')->after('id');
            $table->boolean('premiere')->default(false)->after('made_for_kids');

            $table->index(['theater', 'ends_at']);
        });

        Schema::create('theaters', function (Blueprint $table) {
            $table->id();
            // The theater's room slug, as in config('hushfield.cinema.theaters').
            $table->string('slug', 64)->unique();
            $table->string('house_lights', 16)->default('auto');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theaters');

        Schema::table('screenings', function (Blueprint $table) {
            $table->dropIndex(['theater', 'ends_at']);
            $table->dropColumn(['theater', 'premiere']);
        });
    }
};
