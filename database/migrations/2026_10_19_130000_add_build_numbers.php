<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            // "Hiring #143": a running number per service and edition, which
            // is what people say out loud. The sha stays the identity.
            $table->unsignedInteger('number')->nullable()->after('edition');
            // Commits since the previous build: [{sha, author, subject}].
            $table->json('changelog')->nullable()->after('migration_reasons');
            $table->string('trigger', 16)->default('manual')->after('status');
            $table->foreignId('user_id')->nullable()->after('trigger')->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->after('user_id')->constrained()->nullOnDelete();

            // "Build now" on a branch learns its commit after fetching.
            $table->string('commit_sha', 40)->nullable()->change();
            $table->string('image_tag')->nullable()->change();

            $table->index(['service_id', 'edition', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->dropIndex(['service_id', 'edition', 'number']);
            $table->dropConstrainedForeignId('task_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['number', 'changelog', 'trigger']);
        });
    }
};
