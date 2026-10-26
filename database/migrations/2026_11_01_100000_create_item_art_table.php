<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Furniture art uploaded in the admin catalog, one row per upload of one
     * facing (docs/assets-plan.md §8). Rows are never overwritten: publishing
     * an older one rolls back. At most one row per item and facing is
     * published; stale rows were drawn for a size or shape the item no longer has.
     */
    public function up(): void
    {
        Schema::create('item_art', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained();
            $table->unsignedTinyInteger('facing');
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('stale_at')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'facing']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_art');
    }
};
