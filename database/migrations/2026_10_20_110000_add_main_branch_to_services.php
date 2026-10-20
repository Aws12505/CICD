<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // The branch fast-forwarded to whatever is live for any client
            // (decision 19). Usually `main`; pizzasys and AuditApp call it
            // `events`.
            $table->string('main_branch', 128)->default('main')->after('default_branch');
        });
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('main_branch'));
    }
};
