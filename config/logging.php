<?php

use App\Logging\JsonLoggingTap;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', env('APP_ENV') === 'production' ? 'stderr' : 'daily')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', env('APP_ENV') === 'production' ? 'warning' : 'debug'),
            'replace_placeholders' => false,
            'tap' => [JsonLoggingTap::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', env('APP_ENV') === 'production' ? 'warning' : 'debug'),
            'max_files' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => false,
            'tap' => [JsonLoggingTap::class],
        ],

        'monthly' => [
            'driver' => 'monthly',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', env('APP_ENV') === 'production' ? 'warning' : 'debug'),
            'max_files' => 3,
            'replace_placeholders' => false,
            'tap' => [JsonLoggingTap::class],
        ],

        'slack' => [
            'driver' => 'slack',
            'attachment' => false,
            'context' => false,
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => false,
            'tap' => [JsonLoggingTap::class],
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', env('APP_ENV') === 'production' ? 'warning' : 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'tap' => [JsonLoggingTap::class],
            'processors' => [],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', env('APP_ENV') === 'production' ? 'warning' : 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'tap' => [JsonLoggingTap::class],
            'processors' => [],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', env('APP_ENV') === 'production' ? 'warning' : 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => false,
            'tap' => [JsonLoggingTap::class],
        ],

        'errorlog' => [
            'driver' => 'monolog',
            'handler' => ErrorLogHandler::class,
            'level' => env('LOG_LEVEL', env('APP_ENV') === 'production' ? 'warning' : 'debug'),
            'replace_placeholders' => false,
            'tap' => [JsonLoggingTap::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => 'php://stderr',
        ],

    ],

];
