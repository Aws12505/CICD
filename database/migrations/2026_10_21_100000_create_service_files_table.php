<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per build/run file a service overrides. No row means the
        // control plane's default for that file (decision 27).
        Schema::create('service_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->enum('source', ['default', 'custom', 'repo'])->default('default');
            $table->longText('contents')->nullable();
            $table->string('repo_path')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'name']);
        });

        // "Use the repository's Dockerfile at <path>" becomes a file row.
        // Query builder on purpose: the model no longer knows this column.
        $now = now();

        foreach (DB::table('services')->whereNotNull('dockerfile_path')->where('dockerfile_path', '!=', '')->get(['id', 'dockerfile_path']) as $row) {
            DB::table('service_files')->insert([
                'service_id' => $row->id,
                'name' => 'Dockerfile',
                'source' => 'repo',
                'repo_path' => ltrim($row->dockerfile_path, '/'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // The worker is no longer a recipe: every process is its own
        // container from the one image (decisions 25, 26). The enum keeps the
        // old value so nothing rebuilds the table; it simply never recurs.
        DB::table('services')->where('build_recipe', 'laravel-worker')->update(['build_recipe' => 'laravel-api']);

        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('dockerfile_path'));
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $table) => $table->string('dockerfile_path')->nullable()->after('build_recipe'));

        foreach (DB::table('service_files')->where('name', 'Dockerfile')->where('source', 'repo')->get(['service_id', 'repo_path']) as $row) {
            DB::table('services')->where('id', $row->service_id)->update(['dockerfile_path' => $row->repo_path]);
        }

        Schema::dropIfExists('service_files');
    }
};
