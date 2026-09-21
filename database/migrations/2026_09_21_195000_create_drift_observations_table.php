<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drift_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            // Digests, not tags. A tag can be repointed at different bytes,
            // so comparing tags would miss exactly the case this exists for.
            $table->string('expected_digest')->nullable();
            $table->string('observed_digest')->nullable();

            $table->timestamp('observed_at');

            // Converge puts back what we said should be there. Adopt accepts
            // what is running as the new truth — sometimes the fix made in
            // the field was the right one.
            $table->enum('resolution', ['converge', 'adopt'])->nullable();
            $table->text('resolution_reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['stack_id', 'resolution']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drift_observations');
    }
};
