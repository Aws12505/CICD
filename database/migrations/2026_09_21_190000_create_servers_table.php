<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname');
            $table->string('ip_address')->nullable();
            $table->string('ssh_user')->default('root');
            $table->unsignedSmallInteger('ssh_port')->default(22);

            // Encrypted by the model cast. Losing APP_KEY makes every one of
            // these unrecoverable, which is why the entrypoint no longer
            // regenerates it on boot.
            $table->text('ssh_private_key');

            // The server's known_hosts line, captured on first connect and
            // pinned thereafter. Null means nothing has been verified yet.
            $table->text('host_key')->nullable();

            $table->enum('status', ['pending', 'online', 'unreachable'])->default('pending');
            $table->timestamp('last_seen_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['hostname', 'ssh_port']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
