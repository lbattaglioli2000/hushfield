<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The VFA's programme: one row per YouTube film staff put on, with the
     * moment it starts and ends for everyone. A film cut short keeps its row,
     * with `ends_at` moved to when it was stopped.
     */
    public function up(): void
    {
        Schema::create('screenings', function (Blueprint $table) {
            $table->id();
            $table->string('video_id', 16);
            $table->string('title');
            $table->string('channel')->nullable();
            $table->unsignedInteger('duration_seconds');
            $table->boolean('made_for_kids');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenings');
    }
};
