<?php

declare(strict_types=1);

return [
    /*
     * Null keeps the platform on the default connection. Point it elsewhere
     * to put the audit trail and the tenancy checks on another database.
     */
    'connection' => env('PLATFORM_DB_CONNECTION'),

    'tables' => [
        'audit_logs' => env('PLATFORM_TABLE_AUDIT_LOGS'),
        'otp_challenges' => env('PLATFORM_TABLE_OTP_CHALLENGES'),
    ],

    'table_prefix' => env('PLATFORM_TABLE_PREFIX', ''),

    'load_migrations' => (bool) env('PLATFORM_LOAD_MIGRATIONS', true),

    'tenancy' => [
        'enabled' => (bool) env('PLATFORM_TENANCY', true),

        /*
         * The column every tenant table carries, and the setting the policies
         * read it from. Both appear in generated SQL, so changing either is a
         * migration, not a config flip.
         */
        'column' => env('PLATFORM_TENANT_COLUMN', 'workspace_id'),
        'workspace_guc' => env('PLATFORM_TENANT_GUC', 'app.workspace_id'),

        /*
         * Every setting the policies read. clear() resets all of them, and
         * pushing one that is not on the list throws.
         *
         * Two of them widen what a connection can see rather than narrowing
         * it, and the unwind is allowed to fail - so a pooled connection
         * still holding staff_read would read every workspace for every
         * request after. A setting clear() does not know about is a setting
         * that outlives the request that set it.
         */
        'gucs' => [
            'app.workspace_id',
            'app.user_id',
            'app.staff_read',
            'app.platform_write',
            'app.invitation_token',
        ],

        'staff_read_guc' => env('PLATFORM_STAFF_READ_GUC', 'app.staff_read'),
        'platform_write_guc' => env('PLATFORM_WRITE_GUC', 'app.platform_write'),

        /*
         * Tables that carry the tenant column but are deliberately outside
         * tenancy - read before a workspace can be known, or platform records
         * where the column is a tag rather than an owner. Adding to this list
         * is a decision, which is why it is config rather than a guess made
         * by whoever is reading the failure.
         */
        'unscoped_tables' => [
            'audit_logs',
            'auth_events',
            'otp_challenges',
            'personal_access_tokens',
        ],

        'queue' => env('PLATFORM_TENANT_QUEUE', 'default'),
        'overlap_expires_after' => (int) env('PLATFORM_TENANT_OVERLAP_TTL', 3600),
    ],

    /*
     * The product's catalogue. Both name a backed enum implementing the
     * matching contract: the resources a product grants over are the
     * product, so the package validates against them and never ships them.
     */
    'rbac' => [
        'resources' => null,
        'actions' => null,
    ],

    'audit' => [
        'enabled' => (bool) env('PLATFORM_AUDIT', true),
    ],

    'http' => [
        /*
         * Sent on every response. A product with an embedded widget or a
         * third-party script relaxes its own directives here rather than
         * dropping the middleware.
         */
        'security_headers' => [
            'enabled' => (bool) env('PLATFORM_SECURITY_HEADERS', true),
            'report_only' => (bool) env('PLATFORM_CSP_REPORT_ONLY', false),
            'report_uri' => env('PLATFORM_CSP_REPORT_URI'),
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
            'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'hsts_max_age' => (int) env('PLATFORM_HSTS_MAX_AGE', 31_536_000),
        ],

        /*
         * Null trusts nothing. '*' trusts every proxy, which is correct only
         * where the container is never reachable except through one.
         */
        'trusted_proxies' => env('PLATFORM_TRUSTED_PROXIES'),
    ],

    'log' => [
        /*
         * Added to the package defaults, never replacing them: a field that
         * is sensitive in one product is sensitive everywhere the log ends
         * up. Normalised the same way - api_key, Api-Key and apikey are one.
         */
        'credential_keys' => [],
        'credential_suffixes' => [],
        'pii_keys' => [],
    ],

    'scheduler' => [
        /*
         * Longer than the minute the heartbeat is scheduled at, so one slow
         * run is not an outage, short enough that a stopped scheduler is.
         */
        'heartbeat_ttl' => (int) env('PLATFORM_HEARTBEAT_TTL', 300),
    ],

    'money' => [
        'currency' => env('PLATFORM_CURRENCY', 'KES'),
        'minor_units' => (int) env('PLATFORM_CURRENCY_MINOR_UNITS', 2),
    ],

    'db' => [
        /*
         * The disk is the host's - the package never invents storage.
         */
        'disk' => env('PLATFORM_BACKUP_DISK', 'local'),
        'timeout' => (int) env('PLATFORM_BACKUP_TIMEOUT', 1800),
        'compression' => (int) env('PLATFORM_BACKUP_COMPRESSION', 9),
        'gzip' => (bool) env('PLATFORM_BACKUP_GZIP', true),
        'working_directory' => env('PLATFORM_BACKUP_WORKDIR'),

        /*
         * Object storage fails in ways a local disk does not, and a failed
         * upload looks identical to a rejected one once the disk swallows
         * the reason. Retried with a widening gap before it is called lost.
         */
        'attempts' => (int) env('PLATFORM_BACKUP_ATTEMPTS', 3),

        /*
         * Two tiers rather than one retention number: an hourly dump is for
         * the mistake somebody made this morning, a daily one is for the
         * corruption nobody noticed for a fortnight. Keeping a fortnight of
         * hourlies to get the second costs fourteen times the storage.
         *
         * The first dump taken after daily_hour UTC is promoted to daily.
         */
        'tiers' => [
            'hourly' => [
                'prefix' => env('PLATFORM_BACKUP_HOURLY_PREFIX', 'backups/hourly'),
                'hours' => (int) env('PLATFORM_BACKUP_HOURLY_HOURS', 48),
            ],
            'daily' => [
                'prefix' => env('PLATFORM_BACKUP_DAILY_PREFIX', 'backups/daily'),
                'days' => (int) env('PLATFORM_BACKUP_DAILY_DAYS', 30),
                'hour' => (int) env('PLATFORM_BACKUP_DAILY_HOUR', 0),
            ],
        ],

        /*
         * A connection whose role may bypass row level security, used for
         * the dump and for the role work a restore does. Null means the
         * application's own connection, which on a tenant database means
         * pg_dump sees no rows at all - so Backup refuses rather than
         * writing a file that restores to an empty database.
         */
        'admin_connection' => env('PLATFORM_DB_ADMIN_CONNECTION'),

        'rebuild' => [
            /*
             * Tables whose rows are not carried across a rebuild, because
             * the rebuilt schema writes them itself.
             */
            'unrestored' => ['migrations'],

            /*
             * The product's own last word on whether the rebuilt database
             * is sound, run before the copy is dropped. Empty skips it.
             */
            'verify_commands' => [],
        ],
    ],

    'auth' => [
        'social' => [
            'state_ttl' => (int) env('PLATFORM_SOCIAL_STATE_TTL', 10),
        ],

        'otp' => [
            'length' => (int) env('PLATFORM_OTP_LENGTH', 6),
            'ttl' => (int) env('PLATFORM_OTP_TTL', 10),
            'attempts' => (int) env('PLATFORM_OTP_ATTEMPTS', 5),
            'throttle' => (int) env('PLATFORM_OTP_THROTTLE', 60),
        ],
    ],
];
