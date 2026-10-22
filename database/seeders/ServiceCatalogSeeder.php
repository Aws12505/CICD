<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use Illuminate\Database\Seeder;

/**
 * The eleven services as they are in their repositories today: env keys, the
 * name pizzasys's auth rules use, NATS streams and durables, worker processes
 * and the commands a deploy runs.
 *
 * Safe to run again: services are matched by slug and updated; env keys are
 * only added, never changed, so edits made in the UI survive.
 *
 * Sources: each repo's config/ (env() calls and NATS defaults), its
 * routes/console.php schedules and Console commands, and the hostnames the
 * dashboard's .env.local points at.
 */
class ServiceCatalogSeeder extends Seeder
{
    public const ORG = 'RnD-Experts-Team';

    public function run(): void
    {
        foreach ($this->services() as $position => $definition) {
            $env = $definition['env'];
            unset($definition['env']);

            $service = Service::updateOrCreate(
                ['slug' => $definition['slug']],
                $definition + ['position' => $position],
            );

            foreach ($env as $key => $spec) {
                ServiceEnvDefinition::firstOrCreate(
                    ['service_id' => $service->id, 'key' => $key],
                    $spec + ['required' => false, 'is_build_arg' => false],
                );
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function services(): array
    {
        return [
            $this->laravel([
                'slug' => 'pizzasys',
                'name' => 'Auth (pizzasys)',
                'repo_full_name' => self::ORG.'/pizzasys',
                // This repository calls its branches events / events_testing.
                'default_branch' => 'events_testing',
                'main_branch' => 'events',
                'subdomain' => 'auth',
                'database_name' => 'pizzasys',
                'identity_provider' => true,
                'auth_service_name' => null,
                'nats_publishes' => ['AUTH', 'NOTIFICATIONS'],
                'nats_consumes' => [['stream' => 'HIRING', 'durable' => 'AUTH_HIRING_CONSUMER']],
                'processes' => $this->processes(scheduler: true, queue: true, nats: true),
                // How auth-rule and role changes reach every client.
                'every_deploy_commands' => [
                    'php artisan db:seed --class=RolesAndPermissionsSeeder --force',
                    'php artisan db:seed --class=AuthRulesSeeder --force',
                ],
            ], auth: false, nats: true, env: [
                'TENANT_ALLOWED_DOMAINS' => $this->literal(null, 'Comma-separated email domains allowed to sign in. Empty allows all.'),
                'EMPLOYEE_DEFAULT_PASSWORD' => $this->secret('Initial password given to employees created from Hiring.'),
                'APPLE_TEAM_ID' => $this->literal(null, 'For the mobile app\'s universal links.'),
                'ANDROID_SIGNING_CERT_SHA256' => $this->literal(null, 'For the mobile app\'s app links.'),
            ]),

            $this->laravel([
                'slug' => 'hiring',
                'name' => 'Hiring',
                'repo_full_name' => self::ORG.'/HiringPizza',
                'subdomain' => 'hiring',
                'database_name' => 'hiringpizza',
                'auth_service_name' => 'Hiring',
                'nats_publishes' => ['HIRING', 'NOTIFICATIONS'],
                'nats_consumes' => [
                    ['stream' => 'AUTH', 'durable' => 'HIRING_AUTH_CONSUMER'],
                    ['stream' => 'OPERATIONS', 'durable' => 'HIRING_OPERATIONS_CONSUMER'],
                ],
                'processes' => $this->processes(scheduler: true, queue: true, nats: true),
            ], env: [
                'X_SECRET_KEY' => $this->generated('shared:x_secret_hiring', 'Shared with the dashboard, which sends it as X-Secret-Key.', required: true),
                ...$this->tcp(),
                'EXTERNAL_WRITE_ALLOWED_STORES' => $this->literal(null, 'Store ids external writes may touch, comma-separated.'),
            ]),

            $this->laravel([
                'slug' => 'inventory',
                'name' => 'Inventory',
                'repo_full_name' => self::ORG.'/InventoryPizza',
                'subdomain' => 'inventory',
                'database_name' => 'inventory',
                'auth_service_name' => 'Inventory',
                'nats_publishes' => [],
                'nats_consumes' => [
                    ['stream' => 'AUTH', 'durable' => 'INVENTORY_AUTH_CONSUMER'],
                    ['stream' => 'HIRING', 'durable' => 'INVENTORY_HIRING_CONSUMER'],
                ],
                'processes' => $this->processes(nats: true),
            ]),

            $this->laravel([
                'slug' => 'maintenance',
                'name' => 'Maintenance',
                'repo_full_name' => self::ORG.'/MaintenancePizza',
                'subdomain' => 'maintenance',
                'database_name' => 'maintenancepizza',
                'auth_service_name' => 'Maintenance',
                'nats_publishes' => ['MAINTENANCE', 'NOTIFICATIONS'],
                'nats_consumes' => [['stream' => 'AUTH', 'durable' => 'MAINTENANCE_AUTH_CONSUMER']],
                'processes' => $this->processes(scheduler: true, queue: true, nats: true),
            ], env: [
                'X_SECRET_KEY' => $this->generated('shared:x_secret_maintenance', 'Shared with the dashboard, which sends it as X-Secret-Key.', required: true),
            ]),

            $this->laravel([
                'slug' => 'notifications',
                'name' => 'Notifications',
                'repo_full_name' => self::ORG.'/NotificationsPizza',
                'subdomain' => 'notifications',
                'database_name' => 'notificationspizza',
                'auth_service_name' => 'Notifications',
                'nats_publishes' => ['NOTIFICATIONS'],
                'nats_consumes' => [
                    ['stream' => 'AUTH', 'durable' => 'NOTIFICATIONS_AUTH_CONSUMER'],
                    ['stream' => 'NOTIFICATIONS', 'durable' => 'NOTIFICATIONS_NOTIFICATIONS_CONSUMER'],
                ],
                'processes' => [
                    ...$this->processes(queue: true, nats: true),
                    // Websockets for the dashboard, reached at /app on this
                    // service's own domain.
                    ['name' => 'reverb', 'command' => 'php artisan reverb:start --host=0.0.0.0 --port=8080', 'port' => 8080, 'path' => '/app'],
                ],
            ], env: [
                'BROADCAST_CONNECTION' => $this->literal('reverb'),
                'REVERB_APP_ID' => $this->generated('hex:12', required: true),
                'REVERB_APP_KEY' => $this->generated('shared:reverb_key', 'The same key the dashboard connects with.', required: true),
                'REVERB_APP_SECRET' => $this->generated('random:40', required: true),
                // Laravel reaches its own Reverb container by name.
                'REVERB_HOST' => $this->stack('container:reverb'),
                'REVERB_PORT' => $this->literal('8080'),
                'REVERB_SCHEME' => $this->literal('http'),
                'REVERB_SERVER_HOST' => $this->literal('0.0.0.0'),
                'REVERB_SERVER_PORT' => $this->literal('8080'),
                'FIREBASE_SERVER_KEY' => $this->secret('Firebase Cloud Messaging server key, for push notifications.'),
            ]),

            $this->laravel([
                'slug' => 'operations',
                'name' => 'Operations',
                'repo_full_name' => self::ORG.'/OperationsPizza',
                'subdomain' => 'operations',
                'database_name' => 'operationspizza',
                'auth_service_name' => 'Operations',
                'nats_publishes' => ['OPERATIONS', 'NOTIFICATIONS'],
                'nats_consumes' => [
                    ['stream' => 'AUTH', 'durable' => 'OPERATIONS_AUTH_CONSUMER'],
                    ['stream' => 'HIRING', 'durable' => 'OPERATIONS_HIRING_CONSUMER'],
                ],
                'processes' => $this->processes(scheduler: true, queue: 'php artisan queue:work --queue=humanity,default --sleep=3 --tries=1 --timeout=600', nats: true),
            ], env: [
                'OPERATIONS_DEFAULT_TIMEZONE' => $this->literal(null, 'IANA zone for stores without one, e.g. America/New_York.'),
                'HUMANITY_DRIVER' => $this->literal('fake', 'fake or humanity. Leave on fake until the client gives Humanity access.'),
                'HUMANITY_WRITES_ENABLED' => $this->literal('false'),
                'HUMANITY_CLIENT_ID' => $this->secret(),
                'HUMANITY_CLIENT_SECRET' => $this->secret(),
                'HUMANITY_USERNAME' => $this->secret(),
                'HUMANITY_PASSWORD' => $this->secret(),
                ...$this->tcp(),
                'EXTERNAL_WRITE_ALLOWED_STORES' => $this->literal(null, 'Store ids external writes may touch, comma-separated.'),
            ]),

            $this->laravel([
                'slug' => 'screens',
                'name' => 'Screens',
                'repo_full_name' => self::ORG.'/ScreensPizza',
                'subdomain' => 'screens',
                'database_name' => 'screenspizza',
                'auth_service_name' => 'Screens',
                'nats_publishes' => [],
                'nats_consumes' => [],
                'processes' => [],
            ], nats: false, env: [
                'LIVEKIT_URL' => $this->literal(null, 'wss://… of the client\'s LiveKit project.', required: true),
                'LIVEKIT_API_KEY' => $this->secret(required: true),
                'LIVEKIT_API_SECRET' => $this->secret(required: true),
            ]),

            $this->laravel([
                'slug' => 'toolbox',
                'name' => 'Toolbox',
                'repo_full_name' => self::ORG.'/ToolboxPizza',
                'subdomain' => 'toolbox',
                'database_name' => 'toolboxpizza',
                'auth_service_name' => 'Toolbox',
                'nats_publishes' => ['TOOLBOX', 'NOTIFICATIONS'],
                'nats_consumes' => [['stream' => 'AUTH', 'durable' => 'TOOLBOX_AUTH_CONSUMER']],
                'processes' => $this->processes(scheduler: true, queue: true, nats: true),
                'first_run_commands' => [
                    'php artisan db:seed --class=BreakTypeSeeder --force',
                    'php artisan db:seed --class=TicketSectionSeeder --force',
                ],
            ], env: [
                'TOOLBOX_WORK_DAY_TIMEZONE' => $this->literal(null, 'IANA zone the work day is cut at.'),
                'TOOLBOX_WORK_DAY_CUTOFF_HOUR' => $this->literal(null),
                'TOOLBOX_DEFAULT_ALLOWANCE_MINUTES' => $this->literal(null),
                'TOOLBOX_NOTIFICATIONS_ENABLED' => $this->literal('true'),
                'TOOLBOX_TICKET_NOTIFICATIONS_ENABLED' => $this->literal('true'),
            ]),

            $this->laravel([
                'slug' => 'qa',
                'name' => 'QA (AuditApp)',
                'repo_full_name' => self::ORG.'/AuditApp',
                // This repository calls its branches events / events_testing.
                'default_branch' => 'events_testing',
                'main_branch' => 'events',
                // An API: the Inertia pages left in the repository are not served.
                'build_assets' => false,
                'subdomain' => 'qa',
                'database_name' => 'auditapp',
                'auth_service_name' => 'QA',
                'nats_publishes' => ['QA', 'NOTIFICATIONS'],
                'nats_consumes' => [
                    ['stream' => 'AUTH', 'durable' => 'QA_AUTH_CONSUMER'],
                    ['stream' => 'HIRING', 'durable' => 'QA_HIRING_CONSUMER'],
                ],
                'processes' => $this->processes(scheduler: true, nats: true),
                'first_run_commands' => ['php artisan db:seed --class=CameraFormTablesSeeder --force'],
            ], env: [
                'CLEANING_AUDITOR_ROLES' => $this->literal(null),
                'CLEANING_MANAGER_ROLES' => $this->literal(null),
                'DS_MANAGER_ROLE' => $this->literal(null),
                'DS_SPECIALIST_ROLE' => $this->literal(null),
            ]),

            $this->laravel([
                'slug' => 'data',
                'name' => 'Data (LC_PIZZA_DATA)',
                'repo_full_name' => self::ORG.'/LC_PIZZA_DATA',
                'subdomain' => 'data',
                'database_name' => 'lc_pizza_data',
                'extra_databases' => ['lc_pizza_data_operational', 'lc_pizza_data_aggregation', 'lc_pizza_data_archive'],
                'auth_service_name' => 'Data',
                'nats_publishes' => ['DATA', 'NOTIFICATIONS'],
                'nats_consumes' => [
                    ['stream' => 'AUTH', 'durable' => 'DATA_AUTH_CONSUMER'],
                    ['stream' => 'HIRING', 'durable' => 'DATA_HIRING_CONSUMER'],
                ],
                'processes' => $this->processes(scheduler: true, queue: true, nats: true),
            ], env: [
                'X_SECRET_KEY' => $this->generated('shared:x_secret_data', 'Shared with the dashboard, which sends it as X-Secret-Key.', required: true),
                ...$this->extraDatabase('OPERATIONAL', 'lc_pizza_data_operational'),
                ...$this->extraDatabase('AGGREGATION', 'lc_pizza_data_aggregation'),
                ...$this->extraDatabase('ARCHIVE', 'lc_pizza_data_archive'),
                'LC_GATEWAY_PORTAL_SERVER' => $this->literal(null, 'The LC gateway the client\'s stores report to.'),
                'LC_GATEWAY_USERNAME' => $this->secret(),
                'LC_GATEWAY_PASSWORD' => $this->secret(),
                'LC_GATEWAY_HMAC_USER' => $this->secret(),
                'LC_GATEWAY_HMAC_KEY' => $this->secret(),
            ]),

            [
                'slug' => 'dashboard',
                'name' => 'Dashboard',
                'repo_full_name' => self::ORG.'/b-dashboard-pizza',
                'default_branch' => 'testing',
                'main_branch' => 'main',
                'build_recipe' => Service::RECIPE_NEXTJS,
                'subdomain' => 'app',
                'database_name' => null,
                'auth_service_name' => null,
                'identity_provider' => false,
                'health_path' => '/',
                // NEXT_PUBLIC_* is frozen into the bundle, so one image per
                // stack (decision 5).
                //
                // These are the addresses the dashboard's own .env held before the
                // control plane, with the same paths: its code appends to them, so
                // pizzasys and toolbox end in /api/v1 and the rest in /api. A bare
                // host makes every call a 404 (login is /api/v1/auth/login).
                'env' => [
                    'NEXT_PUBLIC_APP_URL' => $this->stack('app_url', build: true, required: true),
                    'NEXT_PUBLIC_API_URL' => $this->url('pizzasys:/api/v1', build: true),
                    'NEXT_PUBLIC_HIRING_API_URL' => $this->url('hiring:/api', build: true),
                    'NEXT_PUBLIC_DATA_API_URL' => $this->url('data:/api', build: true),
                    'NEXT_PUBLIC_QA_API_URL' => $this->url('qa:/api', build: true),
                    'NEXT_PUBLIC_MAINTENANCE_API_URL' => $this->url('maintenance:/api', build: true),
                    'NEXT_PUBLIC_NEW_MAINTENANCE_API_URL' => $this->url('maintenance:/api', build: true),
                    'NEXT_PUBLIC_NOTIFICATIONS_API_URL' => $this->url('notifications:/api', build: true),
                    'NEXT_PUBLIC_OPERATIONS_API_URL' => $this->url('operations:/api', build: true),
                    'NEXT_PUBLIC_TOOLBOX_API_URL' => $this->url('toolbox:/api/v1', build: true),
                    'NEXT_PUBLIC_INVENTORY_API_URL' => $this->url('inventory:/api', build: true),
                    'NEXT_PUBLIC_SCREEN_PROJECT_BASE_URL' => $this->url('screens:/api', build: true),
                    // Screens: the proxy's base is above; the browser also connects to
                    // LiveKit, and the CSP only allows the host named here. It must be the
                    // host the Screens backend returns as its LiveKit server_url, so it
                    // defaults to the Screens domain and is overridden per client if not.
                    'SCREEN_PROJECT_LIVEKIT_DOMAIN' => $this->url('screens', build: true, description: 'Must match the LiveKit server URL the Screens service returns.'),
                    'SCREEN_PROJECT_LIVEKIT_WSS' => $this->url('screens@wss', build: true, description: 'The same host over wss://.'),
                    'NEXT_PUBLIC_API_TIMEOUT' => $this->literal('10000', null, build: true),
                    'NEXT_PUBLIC_TOKEN_STORAGE_KEY' => $this->literal('auth_token_encrypted', null, build: true),
                    'NEXT_PUBLIC_LUMINA_BASE' => $this->literal(null, 'Lumina, if the client has it.', build: true),
                    'NEXT_PUBLIC_LUMINA_BRIDGE' => $this->literal(null, 'Lumina bridge, if the client has it.', build: true),
                    'NEXT_PUBLIC_FEATURE_BREADCRUMBS' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_DARK_MODE' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_DASHBOARD_PERSONALIZATION' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_DEV_TOOLS' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_I18N_INTELLIGENCE' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_LOCALIZATION' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_RTL_SUPPORT' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_SEARCH' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_SECURITY_MONITOR' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_SIDEBAR' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_THEME_SYSTEM' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_FEATURE_USER_MENU' => $this->literal(null, 'Feature flag; the code has its own default.', build: true),
                    'NEXT_PUBLIC_REVERB_KEY' => $this->generated('shared:reverb_key', 'The Notifications service\'s Reverb key.', required: true, build: true),
                    'NEXT_PUBLIC_REVERB_WS_HOST' => $this->url('notifications@host', build: true, description: 'Hostname only; Reverb is served at /app on it.'),
                    'NEXT_PUBLIC_REVERB_AUTH_ENDPOINT' => $this->url('notifications:/api/broadcasting/auth', build: true),
                    'NEXT_PUBLIC_SENSORS_API_URL' => $this->literal(null, 'External sensors API, if the client has one.', build: true),
                    'NEXT_PUBLIC_DSPR_API_URL' => $this->url('data:/api/reports/dspr', build: true),
                    'NEXT_PUBLIC_HOOKS_API_URL' => $this->literal(null, 'External hooks API, if the client has one.', build: true),
                    // Server side, at run time.
                    'AUTH_API_URL' => $this->url('pizzasys:/api/v1'),
                    'HIRING_API_URL' => $this->url('hiring:/api'),
                    'DATA_API_URL' => $this->url('data:/api'),
                    'QA_API_URL' => $this->url('qa:/api'),
                    'MAINTENANCE_API_URL' => $this->url('maintenance:/api'),
                    'NEW_MAINTENANCE_API_URL' => $this->url('maintenance:/api'),
                    'NOTIFICATIONS_API_URL' => $this->url('notifications:/api'),
                    'OPERATIONS_API_URL' => $this->url('operations:/api'),
                    'TOOLBOX_API_URL' => $this->url('toolbox:/api/v1'),
                    'INVENTORY_API_URL' => $this->url('inventory:/api'),
                    'SCREEN_PROJECT_BASE_URL' => $this->url('screens:/api'),
                    'DATA_API_TOKEN' => $this->generated('shared:x_secret_data', 'Data\'s X_SECRET_KEY.'),
                    'MAINTENANCE_API_TOKEN' => $this->generated('shared:x_secret_maintenance', 'Maintenance\'s X_SECRET_KEY.'),
                    'QA_API_TOKEN' => $this->secret('Token QA expects from the dashboard, if any.'),
                    'HOOKS_API_URL' => $this->literal(null),
                    'HOOKS_API_URL_HEADER_KEY' => $this->secret(),
                    'SENSORS_API_URL' => $this->literal(null),
                    'SENSORS_API_TOKEN' => $this->secret(),
                    'DSPR_API_URL' => $this->url('data:/api/reports/dspr'),
                    'DSPR_API_TOKEN' => $this->secret(),
                    'LUMINA_BRIDGE_URL' => $this->literal(null, 'Lumina bridge, if the client has it.'),
                    'MULTI_DASHBOARD_API_URL' => $this->literal(null, 'Full override of the multi-dashboard report URL; otherwise derived from DSPR_API_URL.'),
                    'BUSINESS_REPORTS_TIMEOUT_MS' => $this->literal('60000', 'Heavy multi-store report page.'),
                    'OPERATIONS_TIMEOUT_MS' => $this->literal('30000'),
                    'AUTH_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'DATA_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'DSPR_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'HIRING_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'HIRING_EXPORT_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'HIRING_IMPORT_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'HOOKS_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'MAINTENANCE_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'NOTIFICATIONS_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'QA_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'QA_EXPORT_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'SENSORS_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'TOOLBOX_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'TOOLBOX_UPLOAD_TIMEOUT_MS' => $this->literal(null, 'Upstream timeout in ms; the code has its own default.'),
                    'DASHBOARD_REPORT_API_URL' => $this->literal(null),
                    'DASHBOARD_REPORT_API_TOKEN' => $this->secret(),
                ],
            ],
        ];
    }

    /**
     * A Laravel service with everything they all share: app, database, Redis,
     * and (unless it is the identity provider) how to reach pizzasys.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, array<string, mixed>>  $env
     * @return array<string, mixed>
     */
    private function laravel(array $service, bool $auth = true, bool $nats = true, array $env = []): array
    {
        $common = [
            'APP_NAME' => $this->literal($service['name'], required: true),
            'APP_ENV' => $this->stack('app_env'),
            'APP_KEY' => $this->generated('app_key', required: true),
            'APP_DEBUG' => $this->literal('false'),
            'APP_URL' => $this->stack('app_url', required: true),
            'LOG_CHANNEL' => $this->literal('stderr', 'Container logs; read them from the server page.'),
            'LOG_LEVEL' => $this->literal('info'),
            'TRUSTED_PROXIES' => $this->literal('*', 'nginx on the same machine terminates TLS.'),
            'FILESYSTEM_DISK' => $this->literal('local'),
            'DB_CONNECTION' => $this->literal('mysql'),
            'DB_HOST' => $this->stack('db_host'),
            'DB_PORT' => $this->stack('db_port'),
            'DB_DATABASE' => $this->stack('database', required: true),
            'DB_USERNAME' => $this->stack('db_username', required: true),
            'DB_PASSWORD' => $this->stack('db_password', required: true),
            'CACHE_STORE' => $this->literal('redis'),
            'CACHE_PREFIX' => $this->stack('redis_prefix'),
            'QUEUE_CONNECTION' => $this->literal('redis'),
            'SESSION_DRIVER' => $this->literal('redis'),
            'REDIS_CLIENT' => $this->literal('phpredis'),
            'REDIS_HOST' => $this->stack('redis_host'),
            'REDIS_PORT' => $this->stack('redis_port'),
            'REDIS_USERNAME' => $this->stack('redis_username', required: true),
            'REDIS_PASSWORD' => $this->stack('redis_password', required: true),
            'REDIS_DB' => $this->stack('redis_index'),
            'REDIS_CACHE_DB' => $this->stack('redis_index'),
            'REDIS_PREFIX' => $this->stack('redis_prefix'),
            'MAIL_MAILER' => $this->literal('log', 'Set to smtp and fill the MAIL_* keys when the client has a mail account.'),
            'MAIL_HOST' => $this->literal(null),
            'MAIL_PORT' => $this->literal(null),
            'MAIL_USERNAME' => $this->secret(),
            'MAIL_PASSWORD' => $this->secret(),
            'MAIL_FROM_ADDRESS' => $this->literal(null),
            'MAIL_FROM_NAME' => $this->literal(null),
        ];

        if ($auth) {
            $common += [
                'AUTH_SERVER_BASE_URL' => $this->url('pizzasys'),
                'AUTH_SERVER_VERIFY_PATH' => $this->stack('auth_verify_path', required: true),
                'AUTH_SERVER_SERVICE_NAME' => $this->stack('auth_service_name', required: true),
                'AUTH_SERVER_CALL_TOKEN' => $this->generated('service_token', 'Registered on pizzasys by every deploy.', required: true),
            ];
        }

        if ($nats) {
            $common += [
                'NATS_HOST' => $this->stack('nats_host'),
                'NATS_PORT' => $this->stack('nats_port'),
                'NATS_TOKEN' => $this->stack('nats_token', required: true),
                // Testing selects the *_TESTING_* streams with this.
                'DEV_MODE' => $this->stack('dev_mode', required: true),
            ];
        }

        return array_merge([
            'default_branch' => 'testing',
            'main_branch' => 'main',
            'build_recipe' => Service::RECIPE_LARAVEL_API,
            'health_path' => '/up',
            'per_stack_build' => false,
            'identity_provider' => false,
            'build_assets' => true,
            'extra_databases' => [],
            'first_run_commands' => [],
            'every_deploy_commands' => [],
        ], $service, ['env' => $common + $env]);
    }

    /**
     * @return list<array{name: string, command: string}>
     */
    private function processes(bool $scheduler = false, bool|string $queue = false, bool $nats = false): array
    {
        $processes = [];

        if ($scheduler) {
            $processes[] = ['name' => 'scheduler', 'command' => 'php artisan schedule:work'];
        }

        if ($queue) {
            $processes[] = ['name' => 'queue', 'command' => is_string($queue) ? $queue : 'php artisan queue:work --sleep=3 --tries=1 --timeout=600'];
        }

        if ($nats) {
            $processes[] = ['name' => 'nats', 'command' => 'php artisan nats:consume'];
        }

        return $processes;
    }

    /** @return array<string, array<string, mixed>> */
    private function tcp(): array
    {
        return [
            'TCP_DRIVER' => $this->literal('fake', 'fake or tcp. Leave on fake until the client gives TCP access.'),
            'TCP_WRITES_ENABLED' => $this->literal('false'),
            'TCP_CLIENT_ID' => $this->secret(),
            'TCP_CLIENT_SECRET' => $this->secret(),
            'TCP_API_KEY' => $this->secret(),
            'TCP_COMPANY_ID' => $this->literal(null),
            'TCP_DEFAULT_JOB_CODE' => $this->literal(null),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function extraDatabase(string $prefix, string $database): array
    {
        return [
            "DB_{$prefix}_HOST" => $this->stack('db_host'),
            "DB_{$prefix}_PORT" => $this->stack('db_port'),
            "DB_{$prefix}_DATABASE" => $this->stack("database:{$database}", required: true),
            "DB_{$prefix}_USERNAME" => $this->stack('db_username', required: true),
            "DB_{$prefix}_PASSWORD" => $this->stack('db_password', required: true),
        ];
    }

    private function literal(?string $default, ?string $description = null, bool $required = false, bool $build = false): array
    {
        return ['source' => ServiceEnvDefinition::SOURCE_LITERAL, 'default_value' => $default, 'description' => $description, 'required' => $required, 'is_build_arg' => $build];
    }

    private function secret(?string $description = null, bool $required = false): array
    {
        return ['source' => ServiceEnvDefinition::SOURCE_SECRET, 'default_value' => null, 'description' => $description, 'required' => $required];
    }

    private function generated(string $shape, ?string $description = null, bool $required = false, bool $build = false): array
    {
        return ['source' => ServiceEnvDefinition::SOURCE_GENERATED, 'default_value' => $shape, 'description' => $description, 'required' => $required, 'is_build_arg' => $build];
    }

    private function stack(string $property, bool $required = false, bool $build = false): array
    {
        return ['source' => ServiceEnvDefinition::SOURCE_STACK, 'default_value' => $property, 'description' => null, 'required' => $required, 'is_build_arg' => $build];
    }

    private function url(string $target, bool $build = false, ?string $description = null): array
    {
        return ['source' => ServiceEnvDefinition::SOURCE_SERVICE_URL, 'default_value' => $target, 'description' => $description, 'required' => true, 'is_build_arg' => $build];
    }
}
