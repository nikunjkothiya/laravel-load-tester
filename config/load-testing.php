<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Load Testing Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the Laravel Load Testing
    | package. The package automatically adapts to different Laravel project
    | structures and authentication systems.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Package Enable/Disable
    |--------------------------------------------------------------------------
    |
    | Enable or disable the load testing package globally.
    |
    */
    'enabled' => (bool) env('LOAD_TESTING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Secret key for authenticating load testing requests.
    |
    */
    'secret_key' => env('LOAD_TESTING_SECRET_KEY', 'default-secret-key'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Configuration
    |--------------------------------------------------------------------------
    |
    | Dashboard URL for accessing the load testing dashboard.
    |
    */
    'dashboard_url' => env('LOAD_TESTING_DASHBOARD_URL', 'load-testing-dashboard'),

    /*
    |--------------------------------------------------------------------------
    | Base URL Configuration
    |--------------------------------------------------------------------------
    |
    | The base URL for your application. This will be auto-detected from
    | APP_URL if not specified in .env.loadtesting file.
    |
    */
    'base_url' => env('LOAD_TESTING_BASE_URL', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Test Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the default test parameters. These can be overridden
    | via command line arguments or .env.loadtesting file.
    |
    */
    'test' => [
        'concurrent_users' => (int) env('LOAD_TESTING_CONCURRENT_USERS', 10),
        'duration' => (int) env('LOAD_TESTING_DURATION', 60),
        'ramp_up' => (int) env('LOAD_TESTING_RAMP_UP', 10),
        'timeout' => (int) env('LOAD_TESTING_TIMEOUT', 30),
        'delay_between_requests' => (int) env('LOAD_TESTING_DELAY', 1),
        'think_time' => (int) env('LOAD_TESTING_THINK_TIME', 0),
        'max_redirects' => (int) env('LOAD_TESTING_MAX_REDIRECTS', 5),
        'verify_ssl' => (bool) env('LOAD_TESTING_VERIFY_SSL', false),
        'user_agent' => env('LOAD_TESTING_USER_AGENT', 'Laravel-Load-Tester/1.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | Configure authentication settings. The package supports multiple
    | authentication methods and can auto-detect your project's auth system.
    |
    */
    'auth' => [
        'enabled' => (bool) env('LOAD_TESTING_AUTH_ENABLED', false),
        'method' => env('LOAD_TESTING_AUTH_METHOD', 'auto-detect'),
        'create_test_users' => (bool) env('LOAD_TESTING_CREATE_TEST_USERS', false),
        'test_user_count' => (int) env('LOAD_TESTING_TEST_USER_COUNT', 10),
        'cleanup_test_users' => (bool) env('LOAD_TESTING_CLEANUP_TEST_USERS', true),

        // Session-based authentication (Laravel default)
        'session' => [
            'login_route' => env('LOAD_TESTING_SESSION_LOGIN_ROUTE', '/login'),
            'username_field' => env('LOAD_TESTING_SESSION_USERNAME_FIELD', 'email'),
            'password_field' => env('LOAD_TESTING_SESSION_PASSWORD_FIELD', 'password'),
            'csrf_field' => env('LOAD_TESTING_SESSION_CSRF_FIELD', '_token'),
            'remember_field' => env('LOAD_TESTING_SESSION_REMEMBER_FIELD', 'remember'),
            'logout_route' => env('LOAD_TESTING_SESSION_LOGOUT_ROUTE', '/logout'),
        ],

        // API Token authentication
        'token' => [
            'header_name' => env('LOAD_TESTING_TOKEN_HEADER', 'Authorization'),
            'header_prefix' => env('LOAD_TESTING_TOKEN_PREFIX', 'Bearer'),
            'token_field' => env('LOAD_TESTING_TOKEN_FIELD', 'api_token'),
        ],

        // Laravel Sanctum
        'sanctum' => [
            'token_name' => env('LOAD_TESTING_SANCTUM_TOKEN_NAME', 'load-testing-token'),
            'abilities' => explode(',', env('LOAD_TESTING_SANCTUM_ABILITIES', '*')),
            'csrf_cookie_route' => env('LOAD_TESTING_SANCTUM_CSRF_ROUTE', '/sanctum/csrf-cookie'),
        ],

        // Laravel Passport
        'passport' => [
            'client_id' => env('LOAD_TESTING_PASSPORT_CLIENT_ID'),
            'client_secret' => env('LOAD_TESTING_PASSPORT_CLIENT_SECRET'),
            'scope' => env('LOAD_TESTING_PASSPORT_SCOPE', '*'),
            'token_url' => env('LOAD_TESTING_PASSPORT_TOKEN_URL', '/oauth/token'),
        ],

        // JWT Authentication
        'jwt' => [
            'login_route' => env('LOAD_TESTING_JWT_LOGIN_ROUTE', '/api/auth/login'),
            'refresh_route' => env('LOAD_TESTING_JWT_REFRESH_ROUTE', '/api/auth/refresh'),
            'header_name' => env('LOAD_TESTING_JWT_HEADER', 'Authorization'),
            'header_prefix' => env('LOAD_TESTING_JWT_PREFIX', 'Bearer'),
        ],

        // Test user credentials
        'credentials' => [
            'username' => env('LOAD_TESTING_USERNAME'),
            'password' => env('LOAD_TESTING_PASSWORD'),
            'email' => env('LOAD_TESTING_EMAIL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Discovery Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how routes are discovered and filtered for load testing.
    | The package intelligently adapts to different project structures.
    |
    */
    'routes' => [
        'max_routes' => (int) env('LOAD_TESTING_MAX_ROUTES', 100),
        'discovery_method' => env('LOAD_TESTING_ROUTE_DISCOVERY', 'intelligent'), // intelligent, manual, all

        // Routes to include (if empty, all routes except excluded ones will be tested)
        'include' => array_filter(explode(',', env('LOAD_TESTING_INCLUDE_ROUTES', ''))),

        // Routes to exclude from testing (supports wildcards)
        'exclude' => array_merge([
            // Default safety exclusions
            'telescope*',
            'horizon*',
            '_debugbar*',
            'nova*',
            'nova-api*',
            'password/*',
            'email/verify*',
            'logout',
            'load-testing-dashboard*',
        ], array_filter(explode(',', env('LOAD_TESTING_EXCLUDE_ROUTES', '')))),

        // Middleware to exclude
        'exclude_middleware' => [
            'verified',
            'password.confirm',
            'signed',
            'throttle:1',
            'can:',
            'role:',
            'permission:',
        ],

        // Project-specific exclusions (auto-detected)
        'auto_exclude' => [
            'admin_routes' => (bool) env('LOAD_TESTING_EXCLUDE_ADMIN', true),
            'auth_routes' => (bool) env('LOAD_TESTING_EXCLUDE_AUTH', true),
            'dangerous_routes' => (bool) env('LOAD_TESTING_EXCLUDE_DANGEROUS', true),
        ],

        // Route parameter handling
        'parameters' => [
            'use_real_data' => (bool) env('LOAD_TESTING_USE_REAL_DATA', true),
            'fallback_values' => [
                'id' => 1,
                'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                'slug' => 'sample-slug',
                'token' => 'sample-token',
                'hash' => 'sample-hash',
                'code' => 'ABC123',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Configure database monitoring and management during load testing.
    |
    */
    'database' => [
        'monitor' => (bool) env('LOAD_TESTING_MONITOR_DATABASE', true),
        'create_snapshots' => (bool) env('LOAD_TESTING_CREATE_SNAPSHOTS', false),
        'use_test_database' => (bool) env('LOAD_TESTING_USE_TEST_DATABASE', false),
        'test_database_suffix' => env('LOAD_TESTING_TEST_DB_SUFFIX', '_load_test'),

        'pool' => [
            'max_connections' => (int) env('LOAD_TESTING_DB_MAX_CONNECTIONS', 100),
            'timeout' => (int) env('LOAD_TESTING_DB_TIMEOUT', 30),
        ],

        'monitoring' => [
            'slow_query_threshold' => (int) env('LOAD_TESTING_SLOW_QUERY_THRESHOLD', 1000), // milliseconds
            'log_queries' => (bool) env('LOAD_TESTING_LOG_QUERIES', false),
            'track_deadlocks' => (bool) env('LOAD_TESTING_TRACK_DEADLOCKS', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure system resource monitoring during load testing.
    |
    */
    'monitoring' => [
        'enabled' => (bool) env('LOAD_TESTING_MONITORING_ENABLED', true),
        'interval' => (int) env('LOAD_TESTING_MONITORING_INTERVAL', 5), // seconds

        'results_table' => env('LOAD_TESTING_RESULTS_TABLE', 'load_testing_results'),

        'database' => [
            'enabled' => (bool) env('LOAD_TESTING_DATABASE_MONITORING_ENABLED', true),
        ],

        'resources' => [
            'cpu' => (bool) env('LOAD_TESTING_MONITOR_CPU', true),
            'memory' => (bool) env('LOAD_TESTING_MONITOR_MEMORY', true),
            'disk' => (bool) env('LOAD_TESTING_MONITOR_DISK', true),
            'network' => (bool) env('LOAD_TESTING_MONITOR_NETWORK', false),
        ],

        'thresholds' => [
            'cpu_warning' => (int) env('LOAD_TESTING_CPU_WARNING', 80),
            'cpu_critical' => (int) env('LOAD_TESTING_CPU_CRITICAL', 95),
            'memory_warning' => (int) env('LOAD_TESTING_MEMORY_WARNING', 80),
            'memory_critical' => (int) env('LOAD_TESTING_MEMORY_CRITICAL', 95),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how test results are reported and stored.
    |
    */
    'reporting' => [
        'enabled' => (bool) env('LOAD_TESTING_REPORTING_ENABLED', true),
        'formats' => array_filter(explode(',', env('LOAD_TESTING_REPORT_FORMATS', 'html,json,console'))),
        'output_path' => env('LOAD_TESTING_OUTPUT_PATH', storage_path('load-testing')),

        'store_in_database' => (bool) env('LOAD_TESTING_STORE_IN_DB', true),
        'keep_reports' => (int) env('LOAD_TESTING_KEEP_REPORTS', 30), // days

        'dashboard' => [
            'enabled' => (bool) env('LOAD_TESTING_DASHBOARD_ENABLED', true),
            'route' => env('LOAD_TESTING_DASHBOARD_ROUTE', '/load-testing-dashboard'),
            'middleware' => array_filter(explode(',', env('LOAD_TESTING_DASHBOARD_MIDDLEWARE', 'web'))),
            'auto_refresh' => (int) env('LOAD_TESTING_DASHBOARD_REFRESH', 5), // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Advanced Configuration
    |--------------------------------------------------------------------------
    |
    | Advanced settings for fine-tuning the load testing behavior.
    |
    */
    'advanced' => [
        'async_mode' => (bool) env('LOAD_TESTING_ASYNC_MODE', true),
        'connection_pooling' => (bool) env('LOAD_TESTING_CONNECTION_POOLING', true),
        'keep_alive' => (bool) env('LOAD_TESTING_KEEP_ALIVE', true),
        'gzip_compression' => (bool) env('LOAD_TESTING_GZIP', true),

        'retry' => [
            'enabled' => (bool) env('LOAD_TESTING_RETRY_ENABLED', true),
            'max_attempts' => (int) env('LOAD_TESTING_MAX_RETRIES', 3),
            'delay' => (int) env('LOAD_TESTING_RETRY_DELAY', 1000), // milliseconds
        ],

        'circuit_breaker' => [
            'enabled' => (bool) env('LOAD_TESTING_CIRCUIT_BREAKER', true),
            'failure_threshold' => (int) env('LOAD_TESTING_FAILURE_THRESHOLD', 50), // percentage
            'recovery_timeout' => (int) env('LOAD_TESTING_RECOVERY_TIMEOUT', 30), // seconds
        ],

        'rate_limiting' => [
            'respect_app_limits' => (bool) env('LOAD_TESTING_RESPECT_RATE_LIMITS', true),
            'custom_limits' => [
                'requests_per_minute' => (int) env('LOAD_TESTING_REQUESTS_PER_MINUTE', 0), // 0 = no limit
                'burst_size' => (int) env('LOAD_TESTING_BURST_SIZE', 10),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Adaptation Settings
    |--------------------------------------------------------------------------
    |
    | Settings for automatic adaptation to different Laravel project types.
    |
    */
    'adaptation' => [
        'auto_detect_project_type' => (bool) env('LOAD_TESTING_AUTO_DETECT_PROJECT', true),
        'auto_configure_auth' => (bool) env('LOAD_TESTING_AUTO_CONFIGURE_AUTH', true),
        'auto_exclude_dangerous_routes' => (bool) env('LOAD_TESTING_AUTO_EXCLUDE_DANGEROUS', true),

        'project_types' => [
            'api' => [
                'prefer_json' => true,
                'include_auth_headers' => true,
                'test_post_routes' => true,
            ],
            'spa' => [
                'handle_csrf' => true,
                'maintain_session' => true,
                'test_ajax_routes' => true,
            ],
            'admin' => [
                'exclude_admin_routes' => true,
                'require_admin_auth' => true,
                'test_crud_operations' => false,
            ],
            'multi_tenant' => [
                'handle_tenant_context' => true,
                'test_per_tenant' => false,
                'exclude_tenant_management' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Security-related settings to ensure safe load testing.
    |
    */
    'security' => [
        'prevent_data_modification' => (bool) env('LOAD_TESTING_PREVENT_DATA_MODIFICATION', true),
        'read_only_mode' => (bool) env('LOAD_TESTING_READ_ONLY_MODE', false),
        'allowed_environments' => array_filter(explode(',', env('LOAD_TESTING_ALLOWED_ENVIRONMENTS', 'local,testing,staging'))),

        'safety_checks' => [
            'check_environment' => (bool) env('LOAD_TESTING_CHECK_ENVIRONMENT', true),
            'require_confirmation' => (bool) env('LOAD_TESTING_REQUIRE_CONFIRMATION', false),
            'backup_before_test' => (bool) env('LOAD_TESTING_BACKUP_BEFORE_TEST', false),
        ],
    ],
];
