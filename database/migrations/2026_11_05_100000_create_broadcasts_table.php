<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WHSH's broadcasts: one row per MP3 staff put on the air, stored on the
     * public disk, with the moment it starts and ends for everyone. One
     * taken off the air keeps its row, with `ends_at` moved to when it was.
     */
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('path');
            $table->unsignedInteger('bytes');
            $table->unsignedInteger('duration_seconds');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
