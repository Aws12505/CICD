<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('repo_owner')->nullable()->after('name');
            $table->string('repo_full_name')->nullable()->after('repo_name');
            $table->string('github_secret')->nullable()->after('repo_full_name');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_repo_name_unique');
            $table->unique('repo_full_name');
        });

        DB::table('projects')
            ->whereNull('repo_full_name')
            ->update(['repo_full_name' => DB::raw('repo_name')]);

        $secrets = DB::table('pipelines')
            ->orderBy('id')
            ->get(['project_id', 'github_secret']);

        foreach ($secrets as $row) {
            DB::table('projects')
                ->where('id', $row->project_id)
                ->whereNull('github_secret')
                ->update(['github_secret' => $row->github_secret]);
        }

        Schema::table('pipelines', function (Blueprint $table) {
            $table->dropColumn('github_secret');
        });
    }

    public function down(): void
    {
        Schema::table('pipelines', function (Blueprint $table) {
            $table->string('github_secret')->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['repo_full_name']);
            $table->unique('repo_name');
            $table->dropColumn(['repo_owner', 'repo_full_name', 'github_secret']);
        });
    }
};
