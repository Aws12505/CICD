<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anything long-running the control plane does on someone's behalf: setting
 * up a server, building an image, running a release, restoring a backup.
 *
 * One table so that every one of them streams to the browser the same way and
 * leaves the same record behind. Pipeline runs predate this and keep their own
 * tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('title');
            $table->nullableMorphs('subject');
            $table->enum('status', ['queued', 'running', 'succeeded', 'failed'])->default('queued');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('log_path');
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
