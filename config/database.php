<?php

use Pdo\Mysql;

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql', 'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'gamexsports_portal'),
            'username' => env('DB_USERNAME', 'gamexsports_portal'), 'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'prefix_indexes' => true, 'strict' => true, 'engine' => null,
            'timezone' => '+00:00',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
        'legacy' => [
            'driver' => 'mysql',
            'host' => env('LEGACY_DB_HOST', '127.0.0.1'), 'port' => env('LEGACY_DB_PORT', '3306'),
            'database' => env('LEGACY_DB_DATABASE', 'gamexsports'),
            'username' => env('LEGACY_DB_USERNAME', 'gamexsports_legacy_reader'),
            'password' => env('LEGACY_DB_PASSWORD', ''),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci',
            'prefix' => '', 'strict' => true, 'timezone' => '+08:00',
        ],
        'sqlite' => [
            'driver' => 'sqlite', 'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '', 'foreign_key_constraints' => true,
        ],
    ],
    // The player portal owns its own migration history in the same database.
    'migrations' => ['table' => 'admin_migrations', 'update_date_on_publish' => true],
    'redis' => ['client' => 'phpredis'],
];
