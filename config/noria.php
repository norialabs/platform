<?php

declare(strict_types=1);

return [
    /*
     * Null keeps the platform on the default connection. Point it elsewhere
     * to put the audit trail and the tenancy checks on another database.
     */
    'connection' => env('NORIA_DB_CONNECTION'),

    'tables' => [
        'audit_logs' => env('NORIA_TABLE_AUDIT_LOGS'),
        'otp_challenges' => env('NORIA_TABLE_OTP_CHALLENGES'),
        'invitations' => env('NORIA_TABLE_INVITATIONS'),
    ],

    'table_prefix' => env('NORIA_TABLE_PREFIX', ''),

    'load_migrations' => (bool) env('NORIA_LOAD_MIGRATIONS', true),

    /*
     * Timestamp columns the package creates. 'tz' is timestamptz, which is
     * what an estate spanning more than one offset needs and what these
     * products already write for the columns they thought about. 'plain'
     * is Laravel's default, for a host whose other tables use that.
     */
    'timestamps' => env('NORIA_TIMESTAMPS', 'tz'),

    'tenancy' => [
        'enabled' => (bool) env('NORIA_TENANCY', true),

        /*
         * The column every tenant table carries, and the setting the policies
         * read it from. Both appear in generated SQL, so changing either is a
         * migration, not a config flip.
         */
        'column' => env('NORIA_TENANT_COLUMN', 'workspace_id'),
        'workspace_guc' => env('NORIA_TENANT_GUC', 'app.workspace_id'),

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
            'app.noria_write',
            'app.invitation_token',
        ],

        'staff_read_guc' => env('NORIA_STAFF_READ_GUC', 'app.staff_read'),
        'noria_write_guc' => env('NORIA_WRITE_GUC', 'app.noria_write'),

        /*
         * Tables that carry the tenant column but are deliberately outside
         * tenancy - read before a workspace can be known, or platform records
         * where the column is a tag rather than an owner. Adding to this list
         * is a decision, which is why it is config rather than a guess made
         * by whoever is reading the failure.
         *
         * The package's own three are excluded whatever this says, so a
         * host renaming one does not have to declare it here as well.
         */
        'unscoped_tables' => [
            'auth_events',
            'personal_access_tokens',
        ],

        'queue' => env('NORIA_TENANT_QUEUE', 'default'),
        'overlap_expires_after' => (int) env('NORIA_TENANT_OVERLAP_TTL', 3600),
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
        'enabled' => (bool) env('NORIA_AUDIT', true),
    ],

    'http' => [
        /*
         * Sent on every response. A product with an embedded widget or a
         * third-party script relaxes its own directives here rather than
         * dropping the middleware.
         */
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
            'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'hsts_max_age' => (int) env('NORIA_HSTS_MAX_AGE', 31_536_000),
        ],

        /*
         * Null trusts nothing. '*' trusts every proxy, which is correct only
         * where the container is never reachable except through one.
         */
        'trusted_proxies' => env('NORIA_TRUSTED_PROXIES'),
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

        /*
         * Subtrees kept exactly as they arrived. A provider's own document
         * is evidence: masking a field inside it makes the record disagree
         * with what the provider sent, and a reconciliation against it then
         * fails for the wrong reason.
         *
         * Replaces the default rather than adding to it. This list is a
         * hole, and a product holding user input under a key called
         * payload has to be able to close it.
         */
        'verbatim_keys' => ['payload'],

        /*
         * Keys whose value is an address. The query string is dropped,
         * because a token in one is still a token, and the path alone
         * keeps the line useful.
         */
        'address_suffixes' => ['url', 'uri', 'endpoint', 'callback'],
    ],

    'scheduler' => [
        /*
         * Longer than the minute the heartbeat is scheduled at, so one slow
         * run is not an outage, short enough that a stopped scheduler is.
         */
        'heartbeat_ttl' => (int) env('NORIA_HEARTBEAT_TTL', 300),
    ],

    /*
     * A 502 or a 504 means the application is not answering, so the page
     * for it cannot be rendered by the application and its stylesheet
     * cannot be fetched either. Both are baked in ahead of time.
     */
    'errors' => [
        'codes' => [502, 504],
        'view' => env('NORIA_ERROR_VIEW', 'errors.'),
        'stylesheet' => env('NORIA_ERROR_STYLESHEET', 'resources/css/app.css'),
    ],

    'money' => [
        'currency' => env('NORIA_CURRENCY', 'KES'),

        /*
         * Amounts are stored in hundredths of the major unit whatever the
         * currency displays, so a tariff of 2.75 per unit survives being
         * multiplied by a reading before anything rounds it.
         *
         * These are display digits, capped at two. A currency absent from
         * the table falls back to 'digits'.
         */
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

        /*
         * The locale each currency renders in. Pinned per currency rather
         * than taken from app.locale, because otherwise the same amount
         * prints "KES 1,234.50" on one deployment and "Ksh 1,234.50" on the
         * next, and nothing in the code says which.
         *
         * The default is en_<country> read off the currency code, which is
         * right wherever the code names its country. The euro is the one
         * that does not, so it is listed.
         */
        'locales' => [
            'EUR' => 'en_IE',
        ],
    ],

    'db' => [
        /*
         * The disk is the host's - the package never invents storage.
         */
        'disk' => env('NORIA_BACKUP_DISK', 'local'),
        'timeout' => (int) env('NORIA_BACKUP_TIMEOUT', 1800),
        'compression' => (int) env('NORIA_BACKUP_COMPRESSION', 9),
        'gzip' => (bool) env('NORIA_BACKUP_GZIP', true),
        'working_directory' => env('NORIA_BACKUP_WORKDIR'),

        /*
         * Object storage fails in ways a local disk does not, and a failed
         * upload looks identical to a rejected one once the disk swallows
         * the reason. Retried with a widening gap before it is called lost.
         */
        'attempts' => (int) env('NORIA_BACKUP_ATTEMPTS', 3),

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
                'prefix' => env('NORIA_BACKUP_HOURLY_PREFIX', 'backups/hourly'),
                'hours' => (int) env('NORIA_BACKUP_HOURLY_HOURS', 48),
            ],
            'daily' => [
                'prefix' => env('NORIA_BACKUP_DAILY_PREFIX', 'backups/daily'),
                'days' => (int) env('NORIA_BACKUP_DAILY_DAYS', 30),
                'hour' => (int) env('NORIA_BACKUP_DAILY_HOUR', 0),
            ],
        ],

        /*
         * A connection whose role may bypass row level security, used for
         * the dump and for the role work a restore does. Null means the
         * application's own connection, which on a tenant database means
         * pg_dump sees no rows at all - so Backup refuses rather than
         * writing a file that restores to an empty database.
         */
        'admin_connection' => env('NORIA_DB_ADMIN_CONNECTION'),

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

    'identity' => [
        /*
         * A key of its own so rotating the application key does not orphan
         * every outstanding invitation and sign-in code at once. Falls back
         * to the application key when unset.
         */
        'hash_key' => env('NORIA_HASH_KEY'),

        'country' => env('NORIA_COUNTRY', 'KE'),

        /*
         * Local spellings normalise against these. A product selling into
         * another market adds its code rather than editing the package.
         */
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

        /*
         * Somebody accepting has not joined a workspace yet, so the row is
         * read through a policy keyed on this setting rather than through
         * tenancy. Add it to tenancy.gucs or nothing will clear it.
         */
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
