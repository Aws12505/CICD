<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MySQL Full-Instance Backup
    |--------------------------------------------------------------------------
    |
    | Credentials for a MySQL account with read access across every database
    | on the server (not just DB_DATABASE). Keep this separate from the app's
    | own DB_USERNAME/DB_PASSWORD, which is scoped to a single schema only.
    |
    */

    'mysql' => [
        'host' => env('BACKUP_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('BACKUP_DB_PORT', env('DB_PORT', '3306')),
        'username' => env('BACKUP_DB_USERNAME'),
        'password' => env('BACKUP_DB_PASSWORD'),

        // How many weekly dumps to keep on disk before pruning the oldest.
        'keep' => (int) env('BACKUP_MYSQL_KEEP', 8),
    ],

];
