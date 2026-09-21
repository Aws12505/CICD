<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where backups land
    |--------------------------------------------------------------------------
    |
    | A dedicated volume, deliberately NOT storage/app/private: that path is
    | inside the bind-mounted repo tree, is chowned and walked on every
    | backend boot, and sits on a disk whose local driver has 'serve' => true.
    |
    */

    'root' => env('BACKUP_ROOT', '/var/backups/control-plane'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Databases are small and taken nightly; file volumes are large and taken
    | weekly, with deleted files kept in a dated sidecar for the same window.
    |
    */

    'retention' => [
        'database_days' => (int) env('BACKUP_KEEP_DATABASE_DAYS', 14),
        'files_days' => (int) env('BACKUP_KEEP_FILES_DAYS', 56),
        'control_plane_days' => (int) env('BACKUP_KEEP_CONTROL_PLANE_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Staleness
    |--------------------------------------------------------------------------
    |
    | A stack with no successful database backup inside this window goes amber
    | on the dashboard. The normal way a backup system fails is silently, so
    | noticing is most of the job.
    |
    */

    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 36),

    /*
    |--------------------------------------------------------------------------
    | The MySQL container on a client server
    |--------------------------------------------------------------------------
    |
    | One shared instance per server, joined by both tiers. Dumps run inside
    | it via docker exec.
    |
    */

    'mysql_container' => env('BACKUP_MYSQL_CONTAINER', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | The control plane's own database
    |--------------------------------------------------------------------------
    |
    | Needs an account with read access across the schema. Kept separate from
    | the app's own DB_USERNAME.
    |
    */

    'mysql' => [
        'host' => env('BACKUP_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('BACKUP_DB_PORT', env('DB_PORT', '3306')),
        'username' => env('BACKUP_DB_USERNAME'),
        'password' => env('BACKUP_DB_PASSWORD'),
    ],

];
