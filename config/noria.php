<?php

declare(strict_types=1);

return [
    'connection' => env('NORIA_DB_CONNECTION'),

    'tables' => [
        'audit_logs' => env('NORIA_TABLE_AUDIT_LOGS'),
        'otp_challenges' => env('NORIA_TABLE_OTP_CHALLENGES'),
        'invitations' => env('NORIA_TABLE_INVITATIONS'),
    ],

    'table_prefix' => env('NORIA_TABLE_PREFIX', ''),

    'load_migrations' => (bool) env('NORIA_LOAD_MIGRATIONS', true),

    'timestamps' => env('NORIA_TIMESTAMPS', 'tz'),

    'tenancy' => [
        'enabled' => (bool) env('NORIA_TENANCY', true),

        'column' => env('NORIA_TENANT_COLUMN', 'workspace_id'),
        'workspace_guc' => env('NORIA_TENANT_GUC', 'app.workspace_id'),

        'gucs' => [
            'app.workspace_id',
            'app.user_id',
            'app.staff_read',
            'app.noria_write',
            'app.invitation_token',
        ],

        'staff_read_guc' => env('NORIA_STAFF_READ_GUC', 'app.staff_read'),
        'noria_write_guc' => env('NORIA_WRITE_GUC', 'app.noria_write'),

        'unscoped_tables' => [
            'auth_events',
            'personal_access_tokens',
        ],

        'queue' => env('NORIA_TENANT_QUEUE', 'default'),
        'overlap_expires_after' => (int) env('NORIA_TENANT_OVERLAP_TTL', 3600),
    ],

    'rbac' => [
        'resources' => null,
        'actions' => null,
    ],

    'audit' => [
        'enabled' => (bool) env('NORIA_AUDIT', true),
    ],

    'http' => [
        'security_headers' => [
            'enabled' => (bool) env('NORIA_SECURITY_HEADERS', true),
            'report_only' => (bool) env('NORIA_CSP_REPORT_ONLY', false),
            'report_uri' => env('NORIA_CSP_REPORT_URI'),
            'directives' => [
                'default-src' => ["'self'"],
                'base-uri' => ["'self'"],
                'form-action' => ["'self'"],
                'frame-ancestors' => ["'none'"],
                'object-src' => ["'none'"],
                'img-src' => ["'self'", 'data:', 'blob:'],
                'font-src' => ["'self'", 'data:'],
                'style-src' => ["'self'", "'unsafe-inline'"],
                'script-src' => ["'self'"],
                'connect-src' => ["'self'"],
            ],
            'api' => [
                'paths' => ['api/*'],
                'directives' => [
                    'default-src' => ["'none'"],
                    'frame-ancestors' => ["'none'"],
                    'base-uri' => ["'none'"],
                    'form-action' => ["'none'"],
                ],
            ],

            'dev_origin' => (bool) env('NORIA_CSP_DEV_ORIGIN', true),
            'dev_origin_directives' => ['script-src', 'style-src', 'font-src'],

            'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'hsts_max_age' => (int) env('NORIA_HSTS_MAX_AGE', 31_536_000),
        ],

        'trusted_proxies' => env('NORIA_TRUSTED_PROXIES'),
    ],

    'log' => [
        'mask' => env('NORIA_LOG_MASK', 'redact'),

        'exception_channel' => env('NORIA_LOG_EXCEPTION_CHANNEL', 'app'),

        'credential_keys' => [],
        'credential_suffixes' => [],
        'pii_keys' => [],

        'verbatim_keys' => ['payload'],

        'address_suffixes' => ['url', 'uri', 'endpoint', 'callback'],
    ],

    'scheduler' => [
        'heartbeat_ttl' => (int) env('NORIA_HEARTBEAT_TTL', 300),
    ],

    'errors' => [
        'codes' => [502, 504],
        'view' => env('NORIA_ERROR_VIEW', 'errors.'),
        'stylesheet' => env('NORIA_ERROR_STYLESHEET', 'resources/css/app.css'),
    ],

    'csv' => [
        'max_bytes' => (int) env('NORIA_CSV_MAX_BYTES', 5_242_880),
    ],

    'money' => [
        'currency' => env('NORIA_CURRENCY', 'KES'),

        'digits' => (int) env('NORIA_MONEY_DIGITS', 2),

        'fraction_digits' => [
            'KES' => 0,
            'TZS' => 0,
            'UGX' => 0,
            'RWF' => 0,
            'NGN' => 0,
            'USD' => 2,
            'EUR' => 2,
            'GBP' => 2,
            'ZAR' => 2,
        ],

        'max_minor' => (int) env('NORIA_MONEY_MAX_MINOR', 1_000_000_000_000),

        'locales' => [
            'EUR' => 'en_IE',
        ],
    ],

    'db' => [
        'disk' => env('NORIA_BACKUP_DISK', 'local'),
        'timeout' => (int) env('NORIA_BACKUP_TIMEOUT', 1800),
        'compression' => (int) env('NORIA_BACKUP_COMPRESSION', 9),
        'gzip' => (bool) env('NORIA_BACKUP_GZIP', true),
        'working_directory' => env('NORIA_BACKUP_WORKDIR'),

        'attempts' => (int) env('NORIA_BACKUP_ATTEMPTS', 3),

        'tiers' => [
            'hourly' => [
                'prefix' => env('NORIA_BACKUP_HOURLY_PREFIX', 'backups/hourly'),
                'hours' => (int) env('NORIA_BACKUP_HOURLY_HOURS', 48),
            ],
            'daily' => [
                'prefix' => env('NORIA_BACKUP_DAILY_PREFIX', 'backups/daily'),
                'days' => (int) env('NORIA_BACKUP_DAILY_DAYS', 30),
                'hour' => (int) env('NORIA_BACKUP_DAILY_HOUR', 0),
            ],
        ],

        'admin_connection' => env('NORIA_DB_ADMIN_CONNECTION'),

        'rebuild' => [
            'unrestored' => ['migrations'],

            'verify_commands' => [],
        ],
    ],

    'identity' => [
        'hash_key' => env('NORIA_HASH_KEY'),

        'country' => env('NORIA_COUNTRY', 'KE'),

        'dialling_codes' => [
            'KE' => '254',
            'UG' => '256',
            'TZ' => '255',
            'RW' => '250',
            'ET' => '251',
            'NG' => '234',
            'GH' => '233',
            'ZA' => '27',
        ],
    ],

    'invitations' => [
        'ttl_days' => (int) env('NORIA_INVITATION_TTL_DAYS', 7),

        'token_guc' => env('NORIA_INVITATION_GUC', 'app.invitation_token'),
    ],

    'auth' => [
        'social' => [
            'state_ttl' => (int) env('NORIA_SOCIAL_STATE_TTL', 10),
        ],

        'otp' => [
            'length' => (int) env('NORIA_OTP_LENGTH', 6),
            'ttl' => (int) env('NORIA_OTP_TTL', 10),
            'attempts' => (int) env('NORIA_OTP_ATTEMPTS', 5),
            'throttle' => (int) env('NORIA_OTP_THROTTLE', 60),
        ],
    ],
];
