<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            // The image's size on the build host, uncompressed; shown on the
            // Builds page and warned about above the budget.
            $table->unsignedBigInteger('size_bytes')->nullable()->after('digest');
        });
    }

    public function down(): void
    {
        Schema::table('builds', fn (Blueprint $table) => $table->dropColumn('size_bytes'));
    }
};
