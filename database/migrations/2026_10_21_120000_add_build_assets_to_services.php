<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Whether a Laravel service's image builds its Vite front end. Off for a
 * service that is an API only: a front end left over in the repository is
 * then never built, and cannot fail the build.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('build_assets')->default(true)->after('identity_provider');
        });

        // QA (AuditApp) is an API; its leftover Inertia pages do not build on Linux.
        DB::table('services')->where('slug', 'qa')->update(['build_assets' => false]);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('build_assets');
        });
    }
};
