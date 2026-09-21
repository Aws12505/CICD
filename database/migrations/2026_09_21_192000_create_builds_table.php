<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            // Null means the template. Otherwise the edition branch this was
            // built from — named for an operating model, never for a client.
            $table->string('edition')->nullable();

            // Only set for services whose client-specific values are frozen at
            // build time and so cannot share one image. The tag then carries
            // the stack id, still never the client's name.
            $table->foreignId('stack_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('branch');
            $table->string('commit_sha', 40);
            $table->string('version')->nullable();
            $table->string('image_tag');
            // What everything else compares on. A tag can be repointed; this
            // cannot.
            $table->string('digest')->nullable();

            $table->enum('migration_kind', ['none', 'additive', 'destructive'])->default('none');
            $table->json('affected_tables')->nullable();
            $table->json('migration_reasons')->nullable();

            $table->enum('status', ['pending', 'running', 'success', 'failed'])->default('pending');
            $table->text('error')->nullable();
            $table->string('log_path')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'status']);
            $table->index('commit_sha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builds');
    }
};
