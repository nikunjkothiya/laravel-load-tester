<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Load Testing Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the load testing package.
    |
    */

    // The base URL of your application (without trailing slash)
    'base_url' => env('LOAD_TESTING_BASE_URL', 'http://localhost'),
    
    // Test configuration
    'test' => [
        // Number of concurrent users to simulate
        'concurrent_users' => env('LOAD_TESTING_CONCURRENT_USERS', 50),
        
        // Duration of the test in seconds
        'duration' => env('LOAD_TESTING_DURATION', 60),
        
        // Ramp-up time in seconds (time to reach full load)
        'ramp_up' => env('LOAD_TESTING_RAMP_UP', 10),
        
        // Request timeout in seconds
        'timeout' => env('LOAD_TESTING_TIMEOUT', 30),
        
        // Maximum number of requests per user
        'max_requests_per_user' => env('LOAD_TESTING_MAX_REQUESTS_PER_USER', 100),
    ],
    
    // Authentication settings for load testing
    'auth' => [
        // Whether to include authentication in the test
        'enabled' => env('LOAD_TESTING_AUTH_ENABLED', false),
        
        // Authentication method: 'session', 'token', 'jwt', 'sanctum', 'passport', or 'custom'
        'method' => env('LOAD_TESTING_AUTH_METHOD', 'session'),
        
        // Session-based authentication settings (Laravel's default auth)
        'session' => [
            // The login route path
            'login_route' => env('LOAD_TESTING_AUTH_LOGIN_ROUTE', '/login'),
            
            // The login form field names
            'username_field' => env('LOAD_TESTING_AUTH_USERNAME_FIELD', 'email'),
            'password_field' => env('LOAD_TESTING_AUTH_PASSWORD_FIELD', 'password'),
            
            // CSRF token field name
            'csrf_field' => env('LOAD_TESTING_AUTH_CSRF_FIELD', '_token'),
        ],
        
        // Token-based authentication settings (API tokens, Bearer tokens)
        'token' => [
            // API/Bearer token auth endpoint
            'endpoint' => env('LOAD_TESTING_AUTH_TOKEN_ENDPOINT', '/api/login'),
            
            // Field names for the token request
            'username_field' => env('LOAD_TESTING_AUTH_TOKEN_USERNAME_FIELD', 'email'),
            'password_field' => env('LOAD_TESTING_AUTH_TOKEN_PASSWORD_FIELD', 'password'),
            
            // Response token field in JSON response
            'token_response_field' => env('LOAD_TESTING_AUTH_TOKEN_RESPONSE_FIELD', 'token'),
            
            // Token type (Bearer, Token, etc.)
            'token_type' => env('LOAD_TESTING_AUTH_TOKEN_TYPE', 'Bearer'),
            
            // Header name for the token
            'token_header' => env('LOAD_TESTING_AUTH_TOKEN_HEADER', 'Authorization'),
        ],
        
        // JWT authentication settings
        'jwt' => [
            // JWT auth endpoint
            'endpoint' => env('LOAD_TESTING_AUTH_JWT_ENDPOINT', '/api/auth/login'),
            
            // Field names for the JWT request
            'username_field' => env('LOAD_TESTING_AUTH_JWT_USERNAME_FIELD', 'email'),
            'password_field' => env('LOAD_TESTING_AUTH_JWT_PASSWORD_FIELD', 'password'),
            
            // Response token field in JSON response
            'token_response_field' => env('LOAD_TESTING_AUTH_JWT_RESPONSE_FIELD', 'access_token'),
            
            // Token type (usually Bearer)
            'token_type' => env('LOAD_TESTING_AUTH_JWT_TOKEN_TYPE', 'Bearer'),
        ],
        
        // Authentication table in the database
        'table' => env('LOAD_TESTING_AUTH_TABLE', 'users'),
        
        // Credentials for authentication (will be used to create test users)
        'credentials' => [
            'username' => env('LOAD_TESTING_AUTH_USERNAME', 'test@example.com'),
            'password' => env('LOAD_TESTING_AUTH_PASSWORD', 'password'),
            'password_hash' => env('LOAD_TESTING_AUTH_PASSWORD_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), // "password"
        ],
        
        // Multiple user testing - provide a JSON array of users in .env
        // Example: '[{"username":"user1@example.com","password":"password1"},{"username":"user2@example.com","password":"password2"}]'
        'multiple_users' => env('LOAD_TESTING_AUTH_USERS'),
        
        // Custom authentication - use this for custom authentication flows
        'custom' => [
            // Any additional configuration for custom authentication
            'headers' => [], // Custom headers to include in requests
            'cookies' => [], // Custom cookies to include in requests
        ],
    ],
    
    // Routes to include in the load test
    'routes' => [
        // By default, all routes will be tested if this is empty
        'include' => [],
        
        // Routes to exclude from testing
        'exclude' => [
            // Add routes to exclude, e.g., '/admin/*'
        ],
    ],
    
    // Monitoring settings
    'monitoring' => [
        // Enable resource monitoring (CPU, memory)
        'enabled' => env('LOAD_TESTING_MONITORING_ENABLED', true),
        
        // Interval in seconds between resource usage checks
        'interval' => env('LOAD_TESTING_MONITORING_INTERVAL', 5),
        
        // Store monitoring results in database
        'store_results' => env('LOAD_TESTING_MONITORING_STORE_RESULTS', true),
        
        // Table to store results in (will be created if it doesn't exist)
        'results_table' => env('LOAD_TESTING_MONITORING_RESULTS_TABLE', 'load_test_results'),
        
        // Database query monitoring
        'database' => [
            // Enable database query monitoring
            'enabled' => env('LOAD_TESTING_DB_MONITORING_ENABLED', true),
            
            // Threshold in milliseconds to consider a query as slow
            'slow_threshold' => env('LOAD_TESTING_DB_SLOW_THRESHOLD', 100),
            
            // Maximum number of queries to log (to prevent memory issues)
            'max_queries' => env('LOAD_TESTING_DB_MAX_QUERIES', 1000),
            
            // Whether to log query bindings
            'log_bindings' => env('LOAD_TESTING_DB_LOG_BINDINGS', true),
            
            // Store all queries or only slow queries
            'only_slow' => env('LOAD_TESTING_DB_ONLY_SLOW', false),
        ],
    ],
    
    // Reporting settings
    'reporting' => [
        // Generate HTML report after test
        'html' => env('LOAD_TESTING_REPORTING_HTML', true),
        
        // Generate JSON report after test
        'json' => env('LOAD_TESTING_REPORTING_JSON', true),
        
        // Directory to store reports (relative to storage path)
        'output_dir' => env('LOAD_TESTING_REPORTING_OUTPUT_DIR', 'load-testing'),
    ],
    
    // Dashboard settings
    'dashboard_url' => env('LOAD_TESTING_DASHBOARD_URL', 'load-testing-dashboard'),
]; 