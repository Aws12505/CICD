<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * MySQL sized to what the deployed services can ask for, and watched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // How many MySQL connections one PHP process of this service can
            // hold at once: 1 for most; LC_PIZZA_DATA opens operational,
            // archive, aggregation and old.
            $table->unsignedTinyInteger('db_connections_per_process')->default(1)->after('database_name');
            // Scheduled tasks that run at the same time as separate processes
            // (runInBackground), each with its own connections.
            $table->unsignedSmallInteger('db_scheduler_burst')->default(0)->after('db_connections_per_process');
        });

        DB::table('services')->where('slug', 'data')->update(['db_connections_per_process' => 4, 'db_scheduler_burst' => 10]);

        // LC_PIZZA_DATA has no "mysql" connection; the catalog set it anyway.
        $data = DB::table('services')->where('slug', 'data')->value('id');
        DB::table('service_env_definitions')->where('service_id', $data)->where('key', 'DB_CONNECTION')->update(['default_value' => 'operational']);

        // Queue workers restart hourly: memory and any connection a long-lived
        // process forgot are released.
        foreach (DB::table('services')->whereNotNull('processes')->get(['id', 'processes']) as $service) {
            $processes = json_decode((string) $service->processes, true) ?: [];

            foreach ($processes as &$process) {
                if (str_contains($process['command'] ?? '', 'queue:work') && ! str_contains($process['command'], '--max-time')) {
                    $process['command'] .= ' --max-time=3600';
                }
            }

            unset($process);

            DB::table('services')->where('id', $service->id)->update(['processes' => json_encode($processes)]);
        }

        Schema::table('server_metrics', function (Blueprint $table) {
            // Threads_connected, Threads_running, Max_used_connections,
            // Aborted_connects and max_connections at the time of the sample.
            $table->json('mysql')->nullable()->after('containers');
        });
    }

    public function down(): void
    {
        Schema::table('server_metrics', fn (Blueprint $table) => $table->dropColumn('mysql'));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn(['db_connections_per_process', 'db_scheduler_burst']));
    }
};
