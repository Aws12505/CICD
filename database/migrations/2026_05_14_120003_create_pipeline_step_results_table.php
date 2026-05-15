<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_step_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('pipeline_runs')->cascadeOnDelete();
            $table->unsignedInteger('step_index');
            $table->string('name');
            $table->text('command');
            $table->enum('status', ['pending', 'running', 'success', 'failed', 'skipped'])->default('pending');
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'step_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_step_results');
    }
};
