<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The dashboard appends a path to each backend address it is given
 * (/auth/login to pizzasys's, which therefore has to end in /api/v1, and so
 * on), exactly as its own .env did before the control plane. The catalog had
 * bare hosts, so login went to /auth/login and every call 404ed (and failed
 * its CORS preflight, which is how it showed). The seeder only adds missing
 * keys, so a catalog that was already seeded gets the corrected values here.
 */
return new class extends Migration
{
    /** key => the service (and path) it points at */
    private const BASES = [
        'NEXT_PUBLIC_API_URL' => 'pizzasys:/api/v1',
        'AUTH_API_URL' => 'pizzasys:/api/v1',
        'HIRING_API_URL' => 'hiring:/api',
        'DATA_API_URL' => 'data:/api',
        'QA_API_URL' => 'qa:/api',
        'MAINTENANCE_API_URL' => 'maintenance:/api',
        'NOTIFICATIONS_API_URL' => 'notifications:/api',
        'OPERATIONS_API_URL' => 'operations:/api',
        'TOOLBOX_API_URL' => 'toolbox:/api/v1',
        'INVENTORY_API_URL' => 'inventory:/api',
        'SCREEN_PROJECT_BASE_URL' => 'screens:/api',
        'NEXT_PUBLIC_HIRING_API_URL' => 'hiring:/api',
        'NEXT_PUBLIC_DATA_API_URL' => 'data:/api',
        'NEXT_PUBLIC_QA_API_URL' => 'qa:/api',
        'NEXT_PUBLIC_MAINTENANCE_API_URL' => 'maintenance:/api',
        'NEW_MAINTENANCE_API_URL' => 'maintenance:/api',
        'NEXT_PUBLIC_NEW_MAINTENANCE_API_URL' => 'maintenance:/api',
        'NEXT_PUBLIC_REVERB_AUTH_ENDPOINT' => 'notifications:/api/broadcasting/auth',
        'NEXT_PUBLIC_NOTIFICATIONS_API_URL' => 'notifications:/api',
        'NEXT_PUBLIC_OPERATIONS_API_URL' => 'operations:/api',
        'NEXT_PUBLIC_TOOLBOX_API_URL' => 'toolbox:/api/v1',
        'NEXT_PUBLIC_INVENTORY_API_URL' => 'inventory:/api',
        'NEXT_PUBLIC_SCREEN_PROJECT_BASE_URL' => 'screens:/api',
    ];

    public function up(): void
    {
        $dashboard = DB::table('services')->where('slug', 'dashboard')->value('id');

        if ($dashboard === null) {
            return;
        }

        $definitions = DB::table('service_env_definitions')->where('service_id', $dashboard);

        // Left for the client to type in before; they are the Data service's report endpoint.
        (clone $definitions)->whereIn('key', ['DSPR_API_URL', 'NEXT_PUBLIC_DSPR_API_URL'])->update([
            'source' => 'service_url', 'default_value' => 'data:/api/reports/dspr', 'required' => true,
        ]);

        foreach (self::BASES as $key => $target) {
            (clone $definitions)->where('key', $key)->where('source', 'service_url')->update(['default_value' => $target]);
        }
    }

    public function down(): void
    {
        // The old values were wrong; there is nothing worth going back to.
    }
};
