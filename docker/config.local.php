<?php
/**
 * Settings for the public demo container (docker/README.md). Nothing secret here:
 * the demo uses SQLite inside the container and sends no email.
 */
return [
    'environment' => 'demo',
    'app_url' => 'https://booktrack-demo.onrender.com',
    'db' => [
        'driver'      => 'sqlite',
        'sqlite_path' => '/var/lib/booktrack/booktrack.sqlite',
    ],
    'demo'  => ['enabled' => true, 'reset_daily' => false],
    'mail'  => ['driver' => 'off'],
    'debug' => false,
    'security' => ['key_file'=>'/var/lib/booktrack/installation.key','web_root'=>'/var/www/html'],
    'operations' => ['lock_directory'=>'/var/lib/booktrack/locks'],
    'backups' => ['directory'=>'/var/lib/booktrack/backups'],
];
