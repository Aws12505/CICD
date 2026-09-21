<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();

            // Null means the control plane's own data. It is the one thing
            // that cannot be rebuilt from anywhere else.
            $table->foreignId('stack_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('kind', ['database', 'files', 'control_plane']);
            $table->enum('status', ['running', 'success', 'failed'])->default('running');
            $table->enum('triggered_by', ['schedule', 'manual', 'pre_release'])->default('schedule');
            $table->foreignId('release_id')->nullable()->constrained()->nullOnDelete();

            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256')->nullable();

            // For a targeted pre-release dump: which tables it actually holds.
            // Restoring without knowing this is guesswork.
            $table->json('tables')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('retained_until')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['stack_id', 'kind', 'status']);
            $table->index('retained_until');
        });

        Schema::create('backup_restores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stack_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            // A restore discards everything written since the backup, so the
            // reason is required and kept.
            $table->text('reason');
            $table->enum('status', ['running', 'success', 'failed'])->default('running');
            $table->text('error')->nullable();
            $table->string('log_path')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restores');
        Schema::dropIfExists('backups');
    }
};
