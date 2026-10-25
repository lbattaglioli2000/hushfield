<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('kind')->default('public')->after('name');
        });

        // A home is one instance of the residence room, owned by one resident.
        Schema::create('homes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('room_instance_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_open')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('home_furniture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('home_id')->constrained()->cascadeOnDelete();
            // Each owned piece is placed at most once.
            $table->foreignId('inventory_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('x');
            $table->unsignedSmallInteger('y');
            $table->timestamps();

            $table->unique(['home_id', 'x', 'y']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_furniture');
        Schema::dropIfExists('homes');
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
