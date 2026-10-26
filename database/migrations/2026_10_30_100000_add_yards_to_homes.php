<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A home is now a house and a yard: each instance of either room says whose home it is.
        Schema::table('room_instances', function (Blueprint $table) {
            $table->foreignId('home_id')->nullable()->after('room_id')->constrained()->nullOnDelete();
        });

        // Each placed piece stands in one room of the home, the house or the yard.
        Schema::table('home_furniture', function (Blueprint $table) {
            $table->foreignId('room_instance_id')->nullable()->after('home_id')->constrained()->cascadeOnDelete();
        });

        // Every home so far is only a house, and every piece stands in it.
        DB::table('room_instances')->update(['home_id' => DB::table('homes')->select('id')->whereColumn('homes.room_instance_id', 'room_instances.id')]);
        DB::table('home_furniture')->update(['room_instance_id' => DB::table('homes')->select('room_instance_id')->whereColumn('homes.id', 'home_furniture.home_id')]);

        Schema::table('home_furniture', function (Blueprint $table) {
            $table->foreignId('room_instance_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Without yards, pieces out in one go back to their owner's belongings.
        DB::table('home_furniture')
            ->whereNotExists(fn ($query) => $query->from('homes')->whereColumn('homes.room_instance_id', 'home_furniture.room_instance_id'))
            ->delete();

        Schema::table('home_furniture', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_instance_id');
        });

        Schema::table('room_instances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('home_id');
        });
    }
};
