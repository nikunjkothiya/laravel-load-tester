<?php

namespace NikunjKothiya\LaravelLoadTesting\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class ConfigValidationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'load-test:config {--fix : Attempt to fix common issues automatically}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate and check load testing configuration';

    /**
     * Required configuration variables
     */
    protected array $requiredVars = [
        'LOAD_TESTING_ENABLED',
        'LOAD_TESTING_BASE_URL',
        'LOAD_TESTING_SECRET_KEY',
        'LOAD_TESTING_CONCURRENT_USERS',
        'LOAD_TESTING_DURATION',
        'LOAD_TESTING_TIMEOUT',
    ];

    /**
     * Authentication-related variables
     */
    protected array $authVars = [
        'LOAD_TESTING_AUTH_ENABLED',
        'LOAD_TESTING_AUTH_METHOD',
        'LOAD_TESTING_AUTH_USERNAME',
        'LOAD_TESTING_AUTH_PASSWORD',
    ];

    /**
     * Database-related variables
     */
    protected array $databaseVars = [
        'LOAD_TESTING_DB_MONITORING_ENABLED',
        'LOAD_TESTING_DB_SLOW_THRESHOLD',
        'LOAD_TESTING_DB_MAX_CONNECTIONS',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Laravel Load Testing - Configuration Validation');
        $this->line('');

        // Load .env.loadtesting file first
        $this->loadTestingEnvironment();

        // Check Laravel compatibility first
        $this->info('🔧 Checking Laravel Compatibility...');
        $compatibilityResult = $this->checkLaravelCompatibility();

        if (!$compatibilityResult['compatible']) {
            $this->error('❌ Laravel compatibility issues found:');
            foreach ($compatibilityResult['issues'] as $issue) {
                $this->line("   • {$issue}");
            }
            return 1;
        }

        if (!empty($compatibilityResult['warnings'])) {
            $this->warn('⚠️  Laravel compatibility warnings:');
            foreach ($compatibilityResult['warnings'] as $warning) {
                $this->line("   • {$warning}");
            }
            $this->line('');
        }

        $this->info('✅ Laravel compatibility check passed');
        $this->line('');

        // Validate configuration comprehensively
        $validationResults = $this->validateAllConfiguration();

        // Display results
        $this->displayValidationResults($validationResults);

        // Test authentication if valid configuration
        if ($validationResults['overall_valid'] && config('load-testing.auth.enabled', false)) {
            $this->testAuthentication();
        }

        // Test database configuration
        $this->testDatabaseConfiguration();

        // Test route discovery
        $this->testRouteDiscovery();

        $this->info('Configuration validation complete.');

        return $validationResults['overall_valid'] ? 0 : 1;
    }

    /**
     * Check Laravel compatibility.
     *
     * @return array
     */
    protected function checkLaravelCompatibility(): array
    {
        $issues = [];
        $warnings = [];

        // Check Laravel version
        try {
            $laravelVersion = app()->version();
            if (version_compare($laravelVersion, '9.0', '<')) {
                $issues[] = "Laravel version {$laravelVersion} is not supported. Minimum required: 9.0";
            } else {
                $this->line("   ✅ Laravel version: {$laravelVersion}");
            }
        } catch (\Exception $e) {
            $issues[] = "Cannot determine Laravel version: " . $e->getMessage();
        }

        // Check required Laravel components
        $requiredClasses = [
            'Illuminate\Support\Facades\Route' => 'Laravel Route facade',
            'Illuminate\Support\Facades\DB' => 'Laravel Database facade',
            'Illuminate\Support\Facades\Log' => 'Laravel Log facade',
            'Illuminate\Support\Facades\File' => 'Laravel File facade',
            'Illuminate\Support\Facades\Schema' => 'Laravel Schema facade',
        ];

        foreach ($requiredClasses as $class => $description) {
            if (!class_exists($class)) {
                $issues[] = "Missing required Laravel component: {$description}";
            } else {
                $this->line("   ✅ {$description} available");
            }
        }

        // Check database connection
        try {
            DB::connection()->getPdo();
            $this->line("   ✅ Database connection successful");
        } catch (\Exception $e) {
            $issues[] = "Database connection failed: " . $e->getMessage();
        }

        // Check if routes are available
        try {
            $routes = Route::getRoutes();
            // Convert RouteCollection to array to count properly
            $routeArray = [];
            foreach ($routes as $route) {
                $routeArray[] = $route;
            }
            $routeCount = count($routeArray);

            if ($routeCount === 0) {
                $warnings[] = "No routes found. Make sure your application has defined routes.";
            } else {
                $this->line("   ✅ Found {$routeCount} application routes");
            }
        } catch (\Exception $e) {
            $issues[] = "Failed to access application routes: " . $e->getMessage();
        }

        // Check storage permissions
        $storageDir = storage_path('load-testing');
        if (!File::exists($storageDir)) {
            try {
                File::makeDirectory($storageDir, 0755, true);
                $this->line("   ✅ Created load testing storage directory");
            } catch (\Exception $e) {
                $issues[] = "Cannot create storage directory: " . $e->getMessage();
            }
        } else {
            $this->line("   ✅ Load testing storage directory exists");
        }

        if (!is_writable(storage_path())) {
            $issues[] = "Storage directory is not writable";
        } else {
            $this->line("   ✅ Storage directory is writable");
        }

        // Check memory limit
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = $this->convertToBytes($memoryLimit);
        $recommendedMemory = 512 * 1024 * 1024; // 512MB

        if ($memoryLimitBytes < $recommendedMemory) {
            $warnings[] = "Memory limit ({$memoryLimit}) is below recommended 512M for load testing";
        } else {
            $this->line("   ✅ Memory limit: {$memoryLimit}");
        }

        // Check max execution time
        $maxExecutionTime = ini_get('max_execution_time');
        if ($maxExecutionTime > 0 && $maxExecutionTime < 300) {
            $warnings[] = "Max execution time ({$maxExecutionTime}s) might be too low for load testing";
        } else {
            $executionTimeDisplay = $maxExecutionTime == 0 ? 'unlimited' : $maxExecutionTime . 's';
            $this->line("   ✅ Max execution time: {$executionTimeDisplay}");
        }

        return [
            'compatible' => empty($issues),
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    /**
     * Test route discovery functionality.
     *
     * @return void
     */
    protected function testRouteDiscovery(): void
    {
        $this->info('🗺️  Testing Route Discovery...');

        try {
            $routes = Route::getRoutes();
            $testableRoutes = [];
            $excludedRoutes = [];

            foreach ($routes as $route) {
                $uri = $route->uri();
                $methods = $route->methods();
                $middleware = $route->middleware();

                // Apply the same logic as LoadTestingService
                if ($this->shouldExcludeRoute($uri)) {
                    $excludedRoutes[] = $uri;
                    continue;
                }

                if ($this->shouldExcludeByMiddleware($middleware)) {
                    $excludedRoutes[] = $uri . ' (middleware)';
                    continue;
                }

                if (in_array('GET', $methods)) {
                    $testableRoutes[] = $uri;
                }
            }

            // Convert RouteCollection to array to count properly
            $routeArray = [];
            foreach ($routes as $route) {
                $routeArray[] = $route;
            }
            $totalRoutes = count($routeArray);

            $this->line("   ✅ Total routes: " . $totalRoutes);
            $this->line("   ✅ Testable routes: " . count($testableRoutes));
            $this->line("   ✅ Excluded routes: " . count($excludedRoutes));

            if (count($testableRoutes) > 0) {
                $this->line("   📋 Sample testable routes:");
                $sampleRoutes = array_slice($testableRoutes, 0, 5);
                foreach ($sampleRoutes as $route) {
                    $this->line("      • {$route}");
                }
                if (count($testableRoutes) > 5) {
                    $this->line("      • ... and " . (count($testableRoutes) - 5) . " more");
                }
            }
        } catch (\Exception $e) {
            $this->error("   ❌ Route discovery failed: " . $e->getMessage());
        }

        $this->line('');
    }

    /**
     * Check if a route should be excluded from testing.
     */
    protected function shouldExcludeRoute($uri): bool
    {
        $excludePatterns = config('load-testing.routes.exclude', []);

        // Add default exclusions for safety
        $defaultExclusions = [
            'telescope*',
            'horizon*',
            '_debugbar*',
            'nova*',
            'nova-api*',
            'admin*',
            'password/*',
            'email/verify*',
            'logout',
            'load-testing-dashboard*',
        ];

        $allExclusions = array_merge($excludePatterns, $defaultExclusions);

        foreach ($allExclusions as $pattern) {
            if (fnmatch($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if route should be excluded based on middleware.
     */
    protected function shouldExcludeByMiddleware(array $middleware): bool
    {
        $excludeMiddleware = [
            'verified',
            'password.confirm',
            'signed',
            'throttle:1',
            'can:',
        ];

        foreach ($middleware as $mw) {
            foreach ($excludeMiddleware as $exclude) {
                if (str_starts_with($mw, $exclude)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Convert memory limit string to bytes.
     *
     * @param string $val
     * @return int
     */
    protected function convertToBytes(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int) $val;

        switch ($last) {
            case 'g':
                $val *= 1024;
                // no break
            case 'm':
                $val *= 1024;
                // no break
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    /**
     * Display header
     */
    protected function displayHeader(): void
    {
        $this->info('');
        $this->info('┌─────────────────────────────────────────────┐');
        $this->info('│                                             │');
        $this->info('│      Load Testing Configuration Check      │');
        $this->info('│                                             │');
        $this->info('└─────────────────────────────────────────────┘');
        $this->info('');
    }

    /**
     * Load configuration from .env.loadtesting
     */
    protected function loadConfiguration(string $envPath): array
    {
        $content = file_get_contents($envPath);
        $config = [];

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $config[trim($key)] = trim($value);
            }
        }

        return $config;
    }

    /**
     * Validate basic configuration
     */
    protected function validateBasicConfig(array $config): array
    {
        $issues = [];

        $this->info('🔍 Checking Basic Configuration...');

        // Check required variables
        foreach ($this->requiredVars as $var) {
            if (!isset($config[$var]) || empty($config[$var])) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'basic',
                    'message' => "Missing required variable: {$var}",
                    'fix' => "Add {$var} to your .env.loadtesting file"
                ];
            }
        }

        // Validate specific values
        if (isset($config['LOAD_TESTING_CONCURRENT_USERS'])) {
            $users = (int)$config['LOAD_TESTING_CONCURRENT_USERS'];
            if ($users <= 0) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'basic',
                    'message' => 'LOAD_TESTING_CONCURRENT_USERS must be greater than 0',
                    'fix' => 'Set a positive number (e.g., 50)'
                ];
            } elseif ($users > 1000) {
                $issues[] = [
                    'type' => 'warning',
                    'category' => 'basic',
                    'message' => 'LOAD_TESTING_CONCURRENT_USERS is very high (>1000)',
                    'fix' => 'Consider starting with a lower number for testing'
                ];
            }
        }

        if (isset($config['LOAD_TESTING_DURATION'])) {
            $duration = (int)$config['LOAD_TESTING_DURATION'];
            if ($duration <= 0) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'basic',
                    'message' => 'LOAD_TESTING_DURATION must be greater than 0',
                    'fix' => 'Set a positive number in seconds (e.g., 60)'
                ];
            }
        }

        if (isset($config['LOAD_TESTING_BASE_URL'])) {
            $url = $config['LOAD_TESTING_BASE_URL'];
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'basic',
                    'message' => 'LOAD_TESTING_BASE_URL is not a valid URL',
                    'fix' => 'Use format: http://localhost or https://yourdomain.com'
                ];
            }
        }

        $this->displaySectionResult('Basic Configuration', $issues, 'basic');
        return $issues;
    }

    /**
     * Validate authentication configuration
     */
    protected function validateAuthConfig(array $config): array
    {
        $issues = [];

        $this->info('🔐 Checking Authentication Configuration...');

        $authEnabled = isset($config['LOAD_TESTING_AUTH_ENABLED']) &&
            strtolower($config['LOAD_TESTING_AUTH_ENABLED']) === 'true';

        if (!$authEnabled) {
            $this->info('  ℹ️  Authentication disabled - skipping auth checks');
            return $issues;
        }

        // Check auth method
        if (!isset($config['LOAD_TESTING_AUTH_METHOD']) || empty($config['LOAD_TESTING_AUTH_METHOD'])) {
            $issues[] = [
                'type' => 'error',
                'category' => 'auth',
                'message' => 'LOAD_TESTING_AUTH_METHOD is required when auth is enabled',
                'fix' => 'Set to: session, sanctum, passport, jwt, or token'
            ];
        } else {
            $method = $config['LOAD_TESTING_AUTH_METHOD'];
            $validMethods = ['session', 'sanctum', 'passport', 'jwt', 'token', 'auto-detect'];

            if (!in_array($method, $validMethods)) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'auth',
                    'message' => "Invalid auth method: {$method}",
                    'fix' => 'Use one of: ' . implode(', ', $validMethods)
                ];
            }

            // Check credentials for methods that need them
            if (in_array($method, ['session', 'sanctum', 'passport', 'jwt']) || $method === 'auto-detect') {
                if (!isset($config['LOAD_TESTING_AUTH_USERNAME']) || empty($config['LOAD_TESTING_AUTH_USERNAME'])) {
                    $issues[] = [
                        'type' => 'error',
                        'category' => 'auth',
                        'message' => 'LOAD_TESTING_AUTH_USERNAME is required for this auth method',
                        'fix' => 'Set a valid email/username for testing'
                    ];
                }

                if (!isset($config['LOAD_TESTING_AUTH_PASSWORD']) || empty($config['LOAD_TESTING_AUTH_PASSWORD'])) {
                    $issues[] = [
                        'type' => 'error',
                        'category' => 'auth',
                        'message' => 'LOAD_TESTING_AUTH_PASSWORD is required for this auth method',
                        'fix' => 'Set the password for the test user'
                    ];
                }

                // Verify test user exists
                if (isset($config['LOAD_TESTING_AUTH_USERNAME']) && !empty($config['LOAD_TESTING_AUTH_USERNAME'])) {
                    $userExists = $this->checkTestUserExists($config['LOAD_TESTING_AUTH_USERNAME']);
                    if (!$userExists) {
                        $issues[] = [
                            'type' => 'warning',
                            'category' => 'auth',
                            'message' => "Test user '{$config['LOAD_TESTING_AUTH_USERNAME']}' not found in database",
                            'fix' => 'Create the user or run: php artisan load-test:init'
                        ];
                    }
                }
            }

            // Check OAuth settings for Passport
            if ($method === 'passport') {
                if (!isset($config['LOAD_TESTING_AUTH_CLIENT_ID']) || empty($config['LOAD_TESTING_AUTH_CLIENT_ID'])) {
                    $issues[] = [
                        'type' => 'warning',
                        'category' => 'auth',
                        'message' => 'LOAD_TESTING_AUTH_CLIENT_ID not set for Passport',
                        'fix' => 'Set OAuth client ID or leave empty for auto-detection'
                    ];
                }
            }
        }

        $this->displaySectionResult('Authentication', $issues, 'auth');
        return $issues;
    }

    /**
     * Validate database configuration
     */
    protected function validateDatabaseConfig(array $config): array
    {
        $issues = [];

        $this->info('🗄️  Checking Database Configuration...');

        $dbMonitoring = isset($config['LOAD_TESTING_DB_MONITORING_ENABLED']) &&
            strtolower($config['LOAD_TESTING_DB_MONITORING_ENABLED']) === 'true';

        if (!$dbMonitoring) {
            $this->info('  ℹ️  Database monitoring disabled - skipping DB checks');
            return $issues;
        }

        // Check database connection
        try {
            DB::connection()->getPdo();
            $this->info('  ✅ Database connection successful');
        } catch (\Exception $e) {
            $issues[] = [
                'type' => 'error',
                'category' => 'database',
                'message' => 'Cannot connect to database: ' . $e->getMessage(),
                'fix' => 'Check your database configuration in .env'
            ];
        }

        // Check slow query threshold
        if (isset($config['LOAD_TESTING_DB_SLOW_THRESHOLD'])) {
            $threshold = (int)$config['LOAD_TESTING_DB_SLOW_THRESHOLD'];
            if ($threshold <= 0) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'database',
                    'message' => 'LOAD_TESTING_DB_SLOW_THRESHOLD must be greater than 0',
                    'fix' => 'Set a positive number in milliseconds (e.g., 100)'
                ];
            }
        }

        // Check test database settings
        $useTestDb = isset($config['LOAD_TESTING_USE_TEST_DB']) &&
            strtolower($config['LOAD_TESTING_USE_TEST_DB']) === 'true';

        if ($useTestDb) {
            if (!isset($config['LOAD_TESTING_TEST_DB_NAME']) || empty($config['LOAD_TESTING_TEST_DB_NAME'])) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'database',
                    'message' => 'LOAD_TESTING_TEST_DB_NAME required when using test database',
                    'fix' => 'Set a test database name (e.g., laravel_testing)'
                ];
            }
        }

        $this->displaySectionResult('Database', $issues, 'database');
        return $issues;
    }

    /**
     * Validate performance configuration
     */
    protected function validatePerformanceConfig(array $config): array
    {
        $issues = [];

        $this->info('⚡ Checking Performance Configuration...');

        // Check memory limit
        if (isset($config['LOAD_TESTING_MEMORY_LIMIT_MB'])) {
            $memoryLimit = (int)$config['LOAD_TESTING_MEMORY_LIMIT_MB'];
            $phpMemoryLimit = $this->getPhpMemoryLimitMB();

            if ($memoryLimit > $phpMemoryLimit) {
                $issues[] = [
                    'type' => 'warning',
                    'category' => 'performance',
                    'message' => "Memory limit ({$memoryLimit}MB) exceeds PHP limit ({$phpMemoryLimit}MB)",
                    'fix' => 'Increase PHP memory_limit or reduce LOAD_TESTING_MEMORY_LIMIT_MB'
                ];
            }
        }

        // Check timeout settings
        if (isset($config['LOAD_TESTING_TIMEOUT'])) {
            $timeout = (int)$config['LOAD_TESTING_TIMEOUT'];
            if ($timeout <= 0) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'performance',
                    'message' => 'LOAD_TESTING_TIMEOUT must be greater than 0',
                    'fix' => 'Set a positive number in seconds (e.g., 30)'
                ];
            } elseif ($timeout > 300) {
                $issues[] = [
                    'type' => 'warning',
                    'category' => 'performance',
                    'message' => 'LOAD_TESTING_TIMEOUT is very high (>300 seconds)',
                    'fix' => 'Consider using a lower timeout for better responsiveness'
                ];
            }
        }

        $this->displaySectionResult('Performance', $issues, 'performance');
        return $issues;
    }

    /**
     * Validate connectivity
     */
    protected function validateConnectivity(array $config): array
    {
        $issues = [];

        $this->info('🌐 Checking Connectivity...');

        if (isset($config['LOAD_TESTING_BASE_URL']) && !empty($config['LOAD_TESTING_BASE_URL'])) {
            $baseUrl = $config['LOAD_TESTING_BASE_URL'];

            try {
                $response = Http::timeout(10)->get($baseUrl);

                if ($response->successful()) {
                    $this->info('  ✅ Application URL is accessible');
                } else {
                    $issues[] = [
                        'type' => 'warning',
                        'category' => 'connectivity',
                        'message' => "Application URL returned status {$response->status()}",
                        'fix' => 'Check if your application is running and accessible'
                    ];
                }
            } catch (\Exception $e) {
                $issues[] = [
                    'type' => 'warning',
                    'category' => 'connectivity',
                    'message' => "Cannot reach application URL: {$e->getMessage()}",
                    'fix' => 'Ensure your application is running and the URL is correct'
                ];
            }
        }

        $this->displaySectionResult('Connectivity', $issues, 'connectivity');
        return $issues;
    }

    /**
     * Display section result
     */
    protected function displaySectionResult(string $section, array $allIssues, string $category): void
    {
        $sectionIssues = array_filter($allIssues, fn($issue) => $issue['category'] === $category);

        if (empty($sectionIssues)) {
            $this->info("  ✅ {$section} - All checks passed");
        } else {
            $errorCount = count(array_filter($sectionIssues, fn($issue) => $issue['type'] === 'error'));
            $warningCount = count(array_filter($sectionIssues, fn($issue) => $issue['type'] === 'warning'));

            if ($errorCount > 0) {
                $this->error("  ❌ {$section} - {$errorCount} error(s), {$warningCount} warning(s)");
            } else {
                $this->warn("  ⚠️  {$section} - {$warningCount} warning(s)");
            }
        }
    }

    /**
     * Display results summary
     */
    protected function displayResults(array $issues, array $config): void
    {
        $this->info('');
        $this->info('┌─────────────────────────────────────────────┐');
        $this->info('│                  Summary                    │');
        $this->info('└─────────────────────────────────────────────┘');
        $this->info('');

        if (empty($issues)) {
            $this->info('🎉 All configuration checks passed!');
            $this->info('');
            $this->info('Your load testing setup is ready to use:');
            $this->info('php artisan load-testing:run');
            return;
        }

        $errors = array_filter($issues, fn($issue) => $issue['type'] === 'error');
        $warnings = array_filter($issues, fn($issue) => $issue['type'] === 'warning');

        $this->info('📊 Configuration Issues Found:');
        $this->info('');

        if (!empty($errors)) {
            $this->error('❌ Errors (' . count($errors) . '):');
            foreach ($errors as $error) {
                $this->error("   • {$error['message']}");
                $this->info("     Fix: {$error['fix']}");
            }
            $this->info('');
        }

        if (!empty($warnings)) {
            $this->warn('⚠️  Warnings (' . count($warnings) . '):');
            foreach ($warnings as $warning) {
                $this->warn("   • {$warning['message']}");
                $this->info("     Suggestion: {$warning['fix']}");
            }
            $this->info('');
        }

        if (!empty($errors)) {
            $this->error('❌ Please fix the errors before running load tests.');
            $this->info('');
            $this->info('💡 Quick fixes:');
            $this->info('   • Run: php artisan load-test:init --force');
            $this->info('   • Or edit: .env.loadtesting');
        } else {
            $this->info('✅ No critical errors found. You can run load tests, but consider addressing warnings.');
        }

        $this->info('');
        $this->info('🔧 For automatic fixes, run:');
        $this->info('   php artisan load-test:config --fix');
    }

    /**
     * Attempt to fix common issues
     */
    protected function attemptFixes(array $issues, string $envPath): void
    {
        $this->info('');
        $this->info('🔧 Attempting automatic fixes...');
        $this->info('');

        $content = file_get_contents($envPath);
        $fixed = 0;

        foreach ($issues as $issue) {
            if ($issue['type'] === 'error' && $issue['category'] === 'basic') {
                if (str_contains($issue['message'], 'Missing required variable')) {
                    $varName = str_replace('Missing required variable: ', '', $issue['message']);
                    $defaultValue = $this->getDefaultValue($varName);

                    if ($defaultValue !== null) {
                        $content .= "\n{$varName}={$defaultValue}";
                        $this->info("  ✅ Added {$varName}={$defaultValue}");
                        $fixed++;
                    }
                }
            }
        }

        if ($fixed > 0) {
            file_put_contents($envPath, $content);
            $this->info('');
            $this->info("✅ Fixed {$fixed} issue(s) automatically.");
            $this->info('Please review the changes in .env.loadtesting');
        } else {
            $this->warn('No automatic fixes available for the current issues.');
        }
    }

    /**
     * Get default value for a configuration variable
     */
    protected function getDefaultValue(string $varName): ?string
    {
        $defaults = [
            'LOAD_TESTING_ENABLED' => 'true',
            'LOAD_TESTING_BASE_URL' => config('app.url', 'http://localhost'),
            'LOAD_TESTING_SECRET_KEY' => \Illuminate\Support\Str::random(32),
            'LOAD_TESTING_CONCURRENT_USERS' => '50',
            'LOAD_TESTING_DURATION' => '60',
            'LOAD_TESTING_TIMEOUT' => '30',
        ];

        return $defaults[$varName] ?? null;
    }

    /**
     * Check if test user exists
     */
    protected function checkTestUserExists(string $username): bool
    {
        try {
            $userModel = config('auth.providers.users.model', 'App\Models\User');

            if (!class_exists($userModel)) {
                $userModel = 'App\User';
            }

            if (class_exists($userModel)) {
                return $userModel::where('email', $username)->exists();
            }
        } catch (\Exception $e) {
            // Ignore errors
        }

        return false;
    }

    /**
     * Get PHP memory limit in MB
     */
    protected function getPhpMemoryLimitMB(): int
    {
        $memoryLimit = ini_get('memory_limit');

        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int)substr($memoryLimit, 0, -1);

        switch ($unit) {
            case 'g':
                return $value * 1024;
            case 'm':
                return $value;
            case 'k':
                return (int)($value / 1024);
            default:
                return (int)($value / 1024 / 1024);
        }
    }

    /**
     * Load .env.loadtesting environment file
     */
    protected function loadTestingEnvironment(): void
    {
        $envPath = base_path('.env.loadtesting');

        if (!File::exists($envPath)) {
            $this->error('❌ .env.loadtesting file not found!');
            $this->info('Run: php artisan load-test:init to create it');
            return;
        }

        // Load the environment variables
        $content = File::get($envPath);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, '"\'');

                // Set environment variable
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    /**
     * Validate all configuration sections
     */
    protected function validateAllConfiguration(): array
    {
        $envPath = base_path('.env.loadtesting');
        $config = [];

        if (File::exists($envPath)) {
            $config = $this->loadConfiguration($envPath);
        }

        $allIssues = [];

        // Validate each section
        $allIssues = array_merge($allIssues, $this->validateBasicConfig($config));
        $allIssues = array_merge($allIssues, $this->validateAuthConfig($config));
        $allIssues = array_merge($allIssues, $this->validateDatabaseConfig($config));
        $allIssues = array_merge($allIssues, $this->validatePerformanceConfig($config));
        $allIssues = array_merge($allIssues, $this->validateConnectivity($config));

        $errors = array_filter($allIssues, fn($issue) => $issue['type'] === 'error');
        $overallValid = empty($errors);

        return [
            'overall_valid' => $overallValid,
            'issues' => $allIssues,
            'config' => $config
        ];
    }

    /**
     * Display validation results
     */
    protected function displayValidationResults(array $results): void
    {
        $issues = $results['issues'];
        $config = $results['config'];

        $this->info('');
        $this->info('📊 Configuration Validation Results:');
        $this->info('');

        if (empty($issues)) {
            $this->info('🎉 All configuration checks passed!');
            return;
        }

        $errors = array_filter($issues, fn($issue) => $issue['type'] === 'error');
        $warnings = array_filter($issues, fn($issue) => $issue['type'] === 'warning');

        if (!empty($errors)) {
            $this->error('❌ Errors (' . count($errors) . '):');
            foreach ($errors as $error) {
                $this->error("   • {$error['message']}");
                $this->info("     Fix: {$error['fix']}");
            }
            $this->line('');
        }

        if (!empty($warnings)) {
            $this->warn('⚠️  Warnings (' . count($warnings) . '):');
            foreach ($warnings as $warning) {
                $this->warn("   • {$warning['message']}");
                $this->info("     Suggestion: {$warning['fix']}");
            }
            $this->line('');
        }

        if ($this->option('fix') && !empty($errors)) {
            $this->attemptFixes($errors, base_path('.env.loadtesting'));
        }
    }

    /**
     * Test authentication configuration
     */
    protected function testAuthentication(): void
    {
        $this->info('🔐 Testing Authentication...');

        try {
            $authMethod = config('load-testing.auth.method');
            $username = config('load-testing.auth.credentials.username');

            if (empty($username)) {
                $this->warn('   ⚠️  No username configured for authentication test');
                return;
            }

            // Test user existence
            $userExists = $this->checkTestUserExists($username);
            if ($userExists) {
                $this->info("   ✅ Test user '{$username}' found in database");
            } else {
                $this->warn("   ⚠️  Test user '{$username}' not found in database");
            }

            $this->info("   ✅ Authentication method: {$authMethod}");
        } catch (\Exception $e) {
            $this->error("   ❌ Authentication test failed: " . $e->getMessage());
        }

        $this->line('');
    }

    /**
     * Test database configuration
     */
    protected function testDatabaseConfiguration(): void
    {
        $this->info('🗄️  Testing Database Configuration...');

        try {
            // Test basic connection
            $pdo = DB::connection()->getPdo();
            $this->info('   ✅ Database connection successful');

            // Test query execution
            $result = DB::select('SELECT 1 as test');
            if (!empty($result)) {
                $this->info('   ✅ Database query execution successful');
            }

            // Check if monitoring is enabled
            $monitoringEnabled = config('load-testing.monitoring.database.enabled', false);
            if ($monitoringEnabled) {
                $this->info('   ✅ Database monitoring enabled');
            } else {
                $this->info('   ℹ️  Database monitoring disabled');
            }
        } catch (\Exception $e) {
            $this->error("   ❌ Database test failed: " . $e->getMessage());
        }

        $this->line('');
    }
}
