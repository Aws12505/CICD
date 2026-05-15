<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['queued', 'running', 'success', 'failed', 'cancelled'])->default('queued');
            $table->string('commit_sha')->nullable();
            $table->text('commit_message')->nullable();
            $table->string('pusher')->nullable();
            $table->enum('triggered_by', ['webhook', 'manual', 'rerun'])->default('webhook');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('log_path');
            $table->timestamps();

            $table->index(['pipeline_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_runs');
    }
};
