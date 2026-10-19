<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // The `service` name pizzasys auth rules are written against
            // ("Hiring", "QA"…). Becomes AUTH_SERVER_SERVICE_NAME and the
            // service-client name, so the two can never disagree.
            $table->string('auth_service_name')->nullable()->after('database_name');
            // Databases beyond the main one, e.g. a separate analytics schema.
            $table->json('extra_databases')->nullable()->after('auth_service_name');
            // Long-running processes beside the web container, one container
            // each: [{name: "queue", command: "php artisan queue:work"}].
            $table->json('processes')->nullable()->after('extra_databases');
            // Run once, the first time the service is deployed on a stack.
            $table->json('first_run_commands')->nullable()->after('processes');
            // Run on every deploy, after migrations. Must be idempotent.
            $table->json('every_deploy_commands')->nullable()->after('first_run_commands');
        });

        // Client settings become the "both tiers" layer of a client's
        // environment, and most of what goes in them is a vendor credential.
        Schema::table('client_settings', function (Blueprint $table) {
            $table->dropColumn('value');
        });

        Schema::table('client_settings', function (Blueprint $table) {
            $table->text('value')->nullable()->after('key');
            $table->boolean('is_secret')->default(false)->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('client_settings', function (Blueprint $table) {
            $table->dropColumn(['value', 'is_secret']);
        });

        Schema::table('client_settings', function (Blueprint $table) {
            $table->json('value')->nullable()->after('key');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'auth_service_name',
                'extra_databases',
                'processes',
                'first_run_commands',
                'every_deploy_commands',
            ]);
        });
    }
};
