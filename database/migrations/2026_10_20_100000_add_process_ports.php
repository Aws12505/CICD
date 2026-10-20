<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stack_services', function (Blueprint $table) {
            // Host ports for processes that listen (Reverb): {name: port}.
            // Allocated from the stack's block like host_port.
            $table->json('process_ports')->nullable()->after('host_port');
        });
    }

    public function down(): void
    {
        Schema::table('stack_services', fn (Blueprint $table) => $table->dropColumn('process_ports'));
    }
};
