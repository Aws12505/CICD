<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per server per minute, kept seven days (decision 22).
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->float('cpu_percent');
            $table->float('load1');
            $table->float('load5');
            $table->float('load15');
            $table->unsignedBigInteger('mem_total');
            $table->unsignedBigInteger('mem_used');
            $table->unsignedBigInteger('swap_total')->default(0);
            $table->unsignedBigInteger('swap_used')->default(0);
            $table->unsignedBigInteger('disk_total');
            $table->unsignedBigInteger('disk_used');
            // Cumulative counters as read; rates come from consecutive rows.
            $table->unsignedBigInteger('net_rx')->default(0);
            $table->unsignedBigInteger('net_tx')->default(0);
            $table->unsignedBigInteger('uptime_seconds')->default(0);
            // [{name, image, status, running, cpu, mem_bytes, mem_percent}]
            $table->json('containers')->nullable();

            $table->index(['server_id', 'recorded_at']);
        });

        Schema::table('servers', function (Blueprint $table) {
            // [{domain, expires_at}] from /etc/letsencrypt, refreshed with
            // every sample.
            $table->json('certificates')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('certificates'));
        Schema::dropIfExists('server_metrics');
    }
};
