<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every browser terminal: who, where, when, and the full transcript.
        Schema::create('terminal_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('container')->nullable();
            // sha256 of the single-use token; the token itself is never stored.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('token_expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->string('recording_path')->nullable();
            $table->unsignedBigInteger('recording_bytes')->default(0);
            $table->boolean('recording_truncated')->default(false);
            $table->string('client_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_sessions');
    }
};
