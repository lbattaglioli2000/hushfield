<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avatars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('body_color', 7);
            $table->string('sprite_key')->default('resident');
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('map_path');
            $table->string('map_version')->nullable();
            $table->unsignedSmallInteger('capacity_per_instance')->default(40);
            $table->unsignedSmallInteger('spawn_x')->default(0);
            $table->unsignedSmallInteger('spawn_y')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('room_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->boolean('is_open')->default(true);
            $table->timestamp('empty_since')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'number']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('last_room_instance_id')->references('id')->on('room_instances')->nullOnDelete();
        });

        Schema::create('world_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('session_token', 64)->unique();
            $table->timestamp('connected_at');
            $table->timestamp('last_seen_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_sessions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['last_room_instance_id']);
        });
        Schema::dropIfExists('room_instances');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('avatars');
    }
};
