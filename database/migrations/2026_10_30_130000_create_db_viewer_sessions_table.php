<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every time someone opens a server's database in the viewer: who, which
 * server, with what rights, and how it ended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('db_viewer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // write for admins, read for operators; enforced by the MySQL user.
            $table->enum('mode', ['read', 'write']);
            $table->string('ip', 45)->nullable();
            // Single use, one minute: the link the browser opens.
            $table->string('ticket_hash', 64)->unique();
            $table->timestamp('ticket_expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_viewer_sessions');
    }
};
