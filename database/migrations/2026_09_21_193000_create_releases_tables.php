<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One model covers four cases that are really flags on the same thing:
 * normal, promotion, rollback and emergency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stack_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['normal', 'promotion', 'rollback', 'emergency'])->default('normal');
            $table->enum('status', [
                'draft', 'scheduled', 'running', 'succeeded', 'failed', 'cancelled',
            ])->default('draft');

            // Required for an emergency, and kept forever. An emergency
            // release is permanently marked as one.
            $table->text('reason')->nullable();

            // What this was promoted or rolled back from.
            $table->foreignId('source_release_id')->nullable()->constrained('releases')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // A destructive migration is agreed with the client first.
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('window_started_at')->nullable();
            $table->timestamp('window_ended_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('log_path')->nullable();
            $table->timestamps();

            $table->index(['stack_id', 'status']);
        });

        Schema::create('release_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('build_id')->nullable()->constrained()->nullOnDelete();

            // Written before the deploy runs. This is what makes revert
            // possible at all — without the "from" recorded up front there is
            // nothing to go back to.
            $table->string('from_digest')->nullable();
            $table->string('to_digest')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->enum('status', [
                'pending', 'running', 'succeeded', 'failed', 'skipped', 'reverted',
            ])->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['release_id', 'service_id']);
            $table->index(['release_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_items');
        Schema::dropIfExists('releases');
    }
};
