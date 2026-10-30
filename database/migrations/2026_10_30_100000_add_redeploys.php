<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Redeploying what a stack already runs, and rebuilding a per-stack image of
 * the same commit when its build-time values change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            // redeploy: the same digests again, to pick up changed settings.
            $table->enum('kind', ['normal', 'promotion', 'rollback', 'emergency', 'redeploy'])->default('normal')->change();
        });

        Schema::table('builds', function (Blueprint $table) {
            // sha256 of the build-time values a per-stack image was built
            // with, so a change to them shows the image is out of date.
            $table->string('build_args_hash', 64)->nullable()->after('digest');
            // 1 for a commit's first build for its stack, 2 for the first
            // rebuild, and so on; part of the tag, so a rebuild never
            // overwrites an image a release recorded.
            $table->unsignedSmallInteger('attempt')->default(1)->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('builds', fn (Blueprint $table) => $table->dropColumn(['build_args_hash', 'attempt']));

        Schema::table('releases', function (Blueprint $table) {
            $table->enum('kind', ['normal', 'promotion', 'rollback', 'emergency'])->default('normal')->change();
        });
    }
};
