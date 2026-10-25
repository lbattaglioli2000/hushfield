<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body', 255);
            $table->string('filtered_body', 255);
            $table->boolean('was_filtered')->default(false);
            $table->timestamp('created_at')->index();

            $table->index(['room_instance_id', 'id']);
        });

        Schema::create('chat_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->string('status')->default('open')->index();
            $table->string('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('reason');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('chat_report_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        Schema::create('blocked_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term');
            $table->string('match_type');
            $table->string('severity');
            $table->timestamps();

            $table->unique(['term', 'match_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_terms');
        Schema::dropIfExists('sanctions');
        Schema::dropIfExists('chat_reports');
        Schema::dropIfExists('chat_messages');
    }
};
