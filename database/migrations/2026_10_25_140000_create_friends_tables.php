<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friend_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->unique(['sender_id', 'recipient_id']);
            $table->index('recipient_id');
        });

        // One row per direction, so "my friends" is a single indexed lookup.
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->unique(['user_id', 'friend_id']);
        });

        // Homes go from open/closed to who may visit: closed, friends, or everyone.
        Schema::table('homes', function (Blueprint $table) {
            $table->string('visitors')->default('closed')->after('room_instance_id')->index();
        });
        DB::table('homes')->where('is_open', true)->update(['visitors' => 'everyone']);
        Schema::table('homes', function (Blueprint $table) {
            $table->dropIndex(['is_open']);
            $table->dropColumn('is_open');
        });
    }

    public function down(): void
    {
        Schema::table('homes', function (Blueprint $table) {
            $table->boolean('is_open')->default(false)->index();
        });
        DB::table('homes')->where('visitors', 'everyone')->update(['is_open' => true]);
        Schema::table('homes', function (Blueprint $table) {
            $table->dropIndex(['visitors']);
            $table->dropColumn('visitors');
        });

        Schema::dropIfExists('friendships');
        Schema::dropIfExists('friend_requests');
    }
};
