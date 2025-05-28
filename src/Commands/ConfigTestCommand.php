<?php

namespace NikunjKothiya\LaravelLoadTesting\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use NikunjKothiya\LaravelLoadTesting\Services\AuthDetector;
use NikunjKothiya\LaravelLoadTesting\Services\AuthManager;
use NikunjKothiya\LaravelLoadTesting\Services\DatabaseManager;

class ConfigTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'load-test:config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate load testing configuration';

    /**
     * @var AuthDetector
     */
    protected $authDetector;

    /**
     * Create a new command instance.
     *
     * @param AuthDetector $authDetector
     * @return void
     */
    public function __construct(AuthDetector $authDetector)
    {
        parent::__construct();
        $this->authDetector = $authDetector;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Validating Load Testing Configuration...');
        $this->info('');

        // Check .env.loadtesting file exists
        if (!file_exists(base_path('.env.loadtesting'))) {
            $this->error('❌ Missing .env.loadtesting file.');
            $this->info('   Run: php artisan load-test:init');
            return 1;
        }

        // Load .env.loadtesting file
        $this->loadTestingEnvironment();

        // Validate configuration comprehensively
        $validationResults = $this->validateAllConfiguration();

        // Display validation summary
        $this->displayValidationSummary($validationResults);

        // Test authentication if valid configuration
        if ($validationResults['overall_valid'] && config('load-testing.auth.enabled', false)) {
            $this->testAuthentication();
        }

        // Test database configuration
        $this->testDatabaseConfiguration();

        $this->info('');
        $this->info('Configuration validation complete.');

        return $validationResults['overall_valid'] ? 0 : 1;
    }

    /**
     * Load the .env.loadtesting file
     *
     * @return void
     */
    protected function loadTestingEnvironment(): void
    {
        $envFile = base_path('.env.loadtesting');

        if (file_exists($envFile)) {
            $this->info('Loading .env.loadtesting file...');

            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }

                // Parse the line
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                // Set environment variable
                putenv("{$name}={$value}");

                // Set in $_ENV and $_SERVER as well
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }

            $this->info('✓ .env.loadtesting file loaded');
        }
    }

    /**
     * Validate all configuration comprehensively.
     *
     * @return array
     */
    protected function validateAllConfiguration(): array
    {
        $results = [
            'overall_valid' => true,
            'categories' => [
                'basic' => $this->validateBasicConfiguration(),
                'authentication' => $this->validateAuthenticationConfiguration(),
                'database' => $this->validateDatabaseConfiguration(),
                'monitoring' => $this->validateMonitoringConfiguration(),
                'dashboard' => $this->validateDashboardConfiguration(),
            ]
        ];

        // Check if any category failed
        foreach ($results['categories'] as $category) {
            if (!$category['valid']) {
                $results['overall_valid'] = false;
            }
        }

        return $results;
    }

    /**
     * Validate basic configuration.
     *
     * @return array
     */
    protected function validateBasicConfiguration(): array
    {
        $this->info('🔍 Validating Basic Configuration...');

        $required = [
            'LOAD_TESTING_BASE_URL' => [
                'description' => 'Base URL for testing',
                'default' => config('app.url', 'http://localhost'),
                'validator' => function ($value) {
                    return filter_var($value, FILTER_VALIDATE_URL) !== false;
                }
            ],
            'LOAD_TESTING_CONCURRENT_USERS' => [
                'description' => 'Number of concurrent users',
                'default' => '50',
                'validator' => function ($value) {
                    return is_numeric($value) && (int)$value > 0 && (int)$value <= 1000;
                }
            ],
            'LOAD_TESTING_DURATION' => [
                'description' => 'Test duration in seconds',
                'default' => '60',
                'validator' => function ($value) {
                    return is_numeric($value) && (int)$value > 0 && (int)$value <= 3600;
                }
            ],
            'LOAD_TESTING_TIMEOUT' => [
                'description' => 'Request timeout in seconds',
                'default' => '30',
                'validator' => function ($value) {
                    return is_numeric($value) && (int)$value > 0 && (int)$value <= 300;
                }
            ],
        ];

        return $this->validateConfigurationSection($required, 'Basic');
    }

    /**
     * Validate authentication configuration.
     *
     * @return array
     */
    protected function validateAuthenticationConfiguration(): array
    {
        $this->info('🔐 Validating Authentication Configuration...');

        $authEnabled = env('LOAD_TESTING_AUTH_ENABLED', false);

        if (!$authEnabled) {
            $this->line('   ℹ️  Authentication is disabled');
            return ['valid' => true, 'missing' => [], 'invalid' => []];
        }

        $required = [
            'LOAD_TESTING_AUTH_METHOD' => [
                'description' => 'Authentication method',
                'default' => 'session',
                'validator' => function ($value) {
                    return in_array($value, ['session', 'token', 'jwt', 'sanctum', 'passport', 'oauth', 'auto-detect']);
                }
            ],
        ];

        $authMethod = env('LOAD_TESTING_AUTH_METHOD', 'session');

        // Add method-specific requirements
        if ($authMethod === 'session') {
            $required = array_merge($required, [
                'LOAD_TESTING_AUTH_USERNAME' => [
                    'description' => 'Test user username/email',
                    'validator' => function ($value) {
                        return !empty($value);
                    }
                ],
                'LOAD_TESTING_AUTH_PASSWORD' => [
                    'description' => 'Test user password',
                    'validator' => function ($value) {
                        return !empty($value);
                    }
                ],
            ]);
        } elseif (in_array($authMethod, ['token', 'jwt', 'sanctum', 'passport'])) {
            $required = array_merge($required, [
                'LOAD_TESTING_AUTH_USERNAME' => [
                    'description' => 'API user username/email',
                    'validator' => function ($value) {
                        return !empty($value);
                    }
                ],
                'LOAD_TESTING_AUTH_PASSWORD' => [
                    'description' => 'API user password',
                    'validator' => function ($value) {
                        return !empty($value);
                    }
                ],
            ]);
        }

        return $this->validateConfigurationSection($required, 'Authentication');
    }

    /**
     * Validate database configuration.
     *
     * @return array
     */
    protected function validateDatabaseConfiguration(): array
    {
        $this->info('🗄️  Validating Database Configuration...');

        $required = [
            'LOAD_TESTING_DB_MONITORING_ENABLED' => [
                'description' => 'Database monitoring enabled',
                'default' => 'true',
                'validator' => function ($value) {
                    return in_array(strtolower($value), ['true', 'false', '1', '0']);
                }
            ],
        ];

        $dbMonitoring = env('LOAD_TESTING_DB_MONITORING_ENABLED', true);

        if ($dbMonitoring) {
            $required = array_merge($required, [
                'LOAD_TESTING_DB_SLOW_THRESHOLD' => [
                    'description' => 'Slow query threshold (ms)',
                    'default' => '100',
                    'validator' => function ($value) {
                        return is_numeric($value) && (int)$value > 0;
                    }
                ],
                'LOAD_TESTING_DB_MAX_CONNECTIONS' => [
                    'description' => 'Maximum database connections',
                    'default' => '50',
                    'validator' => function ($value) {
                        return is_numeric($value) && (int)$value > 0 && (int)$value <= 200;
                    }
                ],
            ]);
        }

        return $this->validateConfigurationSection($required, 'Database');
    }

    /**
     * Validate monitoring configuration.
     *
     * @return array
     */
    protected function validateMonitoringConfiguration(): array
    {
        $this->info('📊 Validating Monitoring Configuration...');

        $required = [
            'LOAD_TESTING_MONITORING_ENABLED' => [
                'description' => 'Resource monitoring enabled',
                'default' => 'true',
                'validator' => function ($value) {
                    return in_array(strtolower($value), ['true', 'false', '1', '0']);
                }
            ],
            'LOAD_TESTING_MONITORING_INTERVAL' => [
                'description' => 'Monitoring interval (seconds)',
                'default' => '5',
                'validator' => function ($value) {
                    return is_numeric($value) && (int)$value > 0 && (int)$value <= 60;
                }
            ],
        ];

        return $this->validateConfigurationSection($required, 'Monitoring');
    }

    /**
     * Validate dashboard configuration.
     *
     * @return array
     */
    protected function validateDashboardConfiguration(): array
    {
        $this->info('🖥️  Validating Dashboard Configuration...');

        $required = [
            'LOAD_TESTING_DASHBOARD_URL' => [
                'description' => 'Dashboard URL path',
                'default' => 'load-testing-dashboard',
                'validator' => function ($value) {
                    return !empty($value) && preg_match('/^[a-zA-Z0-9\-_\/]+$/', $value);
                }
            ],
        ];

        return $this->validateConfigurationSection($required, 'Dashboard');
    }

    /**
     * Validate a configuration section.
     *
     * @param array $required
     * @param string $sectionName
     * @return array
     */
    protected function validateConfigurationSection(array $required, string $sectionName): array
    {
        $missing = [];
        $invalid = [];
        $valid = true;

        foreach ($required as $var => $config) {
            $value = env($var);
            $description = $config['description'];
            $default = $config['default'] ?? null;
            $validator = $config['validator'] ?? null;

            if ($value === null || $value === '') {
                if ($default !== null) {
                    $this->line("   ⚠️  {$description}: Using default value '{$default}'");
                } else {
                    $this->error("   ❌ Missing {$description} ({$var})");
                    $missing[] = [
                        'var' => $var,
                        'description' => $description,
                        'suggestion' => $this->getSuggestionForVariable($var)
                    ];
                    $valid = false;
                }
            } else {
                if ($validator && !$validator($value)) {
                    $this->error("   ❌ Invalid {$description}: {$value}");
                    $invalid[] = [
                        'var' => $var,
                        'value' => $value,
                        'description' => $description
                    ];
                    $valid = false;
                } else {
                    $this->info("   ✅ {$description}: {$value}");
                }
            }
        }

        return [
            'valid' => $valid,
            'missing' => $missing,
            'invalid' => $invalid
        ];
    }

    /**
     * Display validation summary.
     *
     * @param array $results
     * @return void
     */
    protected function displayValidationSummary(array $results): void
    {
        $this->info('');
        $this->info('📋 Validation Summary:');

        $totalMissing = 0;
        $totalInvalid = 0;

        foreach ($results['categories'] as $categoryName => $category) {
            $status = $category['valid'] ? '✅' : '❌';
            $this->line("   {$status} " . ucfirst($categoryName) . " Configuration");

            $totalMissing += count($category['missing']);
            $totalInvalid += count($category['invalid']);
        }

        if ($totalMissing > 0 || $totalInvalid > 0) {
            $this->info('');
            $this->error('🚨 Configuration Issues Found:');

            if ($totalMissing > 0) {
                $this->error("   • {$totalMissing} missing variables");
                $this->info('');
                $this->info('💡 Missing Variables - Add these to your .env.loadtesting file:');

                foreach ($results['categories'] as $category) {
                    foreach ($category['missing'] as $missing) {
                        $this->line("   {$missing['var']}={$missing['suggestion']}");
                        $this->line("   # {$missing['description']}");
                        $this->line('');
                    }
                }
            }

            if ($totalInvalid > 0) {
                $this->error("   • {$totalInvalid} invalid values");
                $this->info('');
                $this->info('🔧 Invalid Values - Fix these in your .env.loadtesting file:');

                foreach ($results['categories'] as $category) {
                    foreach ($category['invalid'] as $invalid) {
                        $this->line("   {$invalid['var']} (current: {$invalid['value']})");
                        $this->line("   # {$invalid['description']}");
                        $this->line('');
                    }
                }
            }

            $this->info('After fixing these issues, run: php artisan load-test:config');
        } else {
            $this->info('');
            $this->info('🎉 All configuration is valid!');
        }
    }

    /**
     * Get suggestion for a missing variable.
     *
     * @param string $var
     * @return string
     */
    protected function getSuggestionForVariable(string $var): string
    {
        $suggestions = [
            'LOAD_TESTING_BASE_URL' => config('app.url', 'http://localhost'),
            'LOAD_TESTING_CONCURRENT_USERS' => '50',
            'LOAD_TESTING_DURATION' => '60',
            'LOAD_TESTING_TIMEOUT' => '30',
            'LOAD_TESTING_AUTH_ENABLED' => 'false',
            'LOAD_TESTING_AUTH_METHOD' => 'session',
            'LOAD_TESTING_AUTH_USERNAME' => 'test@example.com',
            'LOAD_TESTING_AUTH_PASSWORD' => 'password',
            'LOAD_TESTING_DB_MONITORING_ENABLED' => 'true',
            'LOAD_TESTING_DB_SLOW_THRESHOLD' => '100',
            'LOAD_TESTING_DB_MAX_CONNECTIONS' => '50',
            'LOAD_TESTING_MONITORING_ENABLED' => 'true',
            'LOAD_TESTING_MONITORING_INTERVAL' => '5',
            'LOAD_TESTING_DASHBOARD_URL' => 'load-testing-dashboard',
        ];

        return $suggestions[$var] ?? 'your_value_here';
    }

    /**
     * Test authentication configuration
     *
     * @return void
     */
    protected function testAuthentication(): void
    {
        $this->info('Testing authentication configuration...');

        $authMethod = config('load-testing.auth.method');

        if ($authMethod === 'auto-detect') {
            $detectedMethod = $this->authDetector->detectAuthSystem();
            $this->info("✓ Auto-detected authentication method: {$detectedMethod}");
            $authMethod = $detectedMethod;
        }

        $this->info("Authentication method: {$authMethod}");

        try {
            $authManager = app(AuthManager::class);
            $result = $authManager->prepareAuthentication();

            if ($result['success'] ?? false) {
                $this->info('✓ Authentication test passed');

                // Show additional details if available
                if (isset($result['token'])) {
                    $this->info('  Token: ' . substr($result['token'], 0, 20) . '...');
                }

                if (isset($result['token_type'])) {
                    $this->info('  Token type: ' . $result['token_type']);
                }
            } else {
                $this->error('✗ Authentication failed: ' . ($result['error'] ?? 'Unknown error'));

                // Provide hint based on auth method
                switch ($authMethod) {
                    case 'session':
                        $this->warn('  Hint: Check that the login route and form field names are correct.');
                        $this->warn('  Current login route: ' . config('load-testing.auth.session.login_route'));
                        break;
                    case 'token':
                    case 'sanctum':
                        $this->warn('  Hint: Check the token endpoint and credentials.');
                        $this->warn('  Current token endpoint: ' . config('load-testing.auth.token.endpoint'));
                        break;
                    case 'jwt':
                        $this->warn('  Hint: Check the JWT endpoint and credentials.');
                        $this->warn('  Current JWT endpoint: ' . config('load-testing.auth.jwt.endpoint'));
                        break;
                    case 'passport':
                        $this->warn('  Hint: Ensure Passport client credentials are set in .env.loadtesting.');
                        $this->warn('  LOAD_TESTING_PASSPORT_CLIENT_ID and LOAD_TESTING_PASSPORT_CLIENT_SECRET');
                        break;
                }
            }
        } catch (\Exception $e) {
            $this->error('✗ Authentication test failed with exception: ' . $e->getMessage());
        }
    }

    /**
     * Test database configuration
     *
     * @return void
     */
    protected function testDatabaseConfiguration(): void
    {
        $this->info('Testing database configuration...');

        try {
            // Check connection
            $connection = \DB::connection();
            $pdo = $connection->getPdo();

            $this->info('✓ Database connection successful');
            $this->info('  Driver: ' . $connection->getDriverName());
            $this->info('  Database: ' . $connection->getDatabaseName());

            // Check if database monitoring is enabled
            if (config('load-testing.monitoring.database.enabled', false)) {
                $this->info('✓ Database monitoring is enabled');
                $this->info('  Slow query threshold: ' . config('load-testing.monitoring.database.slow_threshold', 100) . 'ms');

                // Test query logging
                \DB::enableQueryLog();
                \DB::select('SELECT 1');
                $queryLog = \DB::getQueryLog();
                \DB::disableQueryLog();

                if (count($queryLog) > 0) {
                    $this->info('✓ Query logging is working');
                } else {
                    $this->warn('⚠ Query logging may not be capturing queries');
                }

                // Check database manager
                $databaseManager = app(DatabaseManager::class);
                $metrics = $databaseManager->monitorPerformance();

                $this->info('✓ Database metrics collector is working');
            } else {
                $this->warn('⚠ Database monitoring is disabled. Enable it in config or .env.loadtesting to track query performance.');
            }
        } catch (\Exception $e) {
            $this->error('✗ Database connection test failed: ' . $e->getMessage());
        }
    }
}
