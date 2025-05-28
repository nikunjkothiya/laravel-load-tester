<?php

namespace NikunjKothiya\LaravelLoadTesting\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InitLoadTestingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'load-test:init {--force : Force recreate the .env.loadtesting file} {--quick : Quick setup with defaults}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize load testing configuration with interactive setup';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->displayWelcome();

        // Create .env.loadtesting file
        $this->createEnvironmentFile();

        // Publish configuration if not already published
        if (!file_exists(config_path('load-testing.php'))) {
            $this->call('vendor:publish', [
                '--provider' => 'NikunjKothiya\LaravelLoadTesting\LoadTestingServiceProvider',
                '--tag' => 'config'
            ]);
        }

        // Interactive setup or quick setup
        if ($this->option('quick')) {
            $this->quickSetup();
        } else {
            $this->interactiveSetup();
        }

        $this->displayCompletionGuide();

        return 0;
    }

    /**
     * Display welcome message
     */
    protected function displayWelcome(): void
    {
        $this->info('');
        $this->info('┌─────────────────────────────────────────────┐');
        $this->info('│                                             │');
        $this->info('│      Laravel Load Testing Setup Wizard     │');
        $this->info('│                                             │');
        $this->info('└─────────────────────────────────────────────┘');
        $this->info('');
        $this->info('This wizard will help you configure load testing for your Laravel application.');
        $this->info('');
    }

    /**
     * Create the .env.loadtesting file
     */
    protected function createEnvironmentFile(): void
    {
        $targetPath = base_path('.env.loadtesting');

        if (file_exists($targetPath) && !$this->option('force')) {
            $this->warn('📄 .env.loadtesting already exists');

            if ($this->confirm('Do you want to recreate it with new settings?', false)) {
                $this->info('🔄 Recreating .env.loadtesting file');
            } else {
                $this->info('✅ Using existing .env.loadtesting file');
                return;
            }
        }

        // Generate a random secret key
        $secretKey = Str::random(32);

        // Detect application URL
        $appUrl = config('app.url', 'http://localhost');

        // Generate base .env.loadtesting content
        $content = $this->generateEnvContent($appUrl, $secretKey);

        // Write the file
        File::put($targetPath, $content);
        $this->info('✅ Created .env.loadtesting configuration file');
    }

    /**
     * Quick setup with auto-detection
     */
    protected function quickSetup(): void
    {
        $this->info('');
        $this->info('🚀 Quick Setup Mode');
        $this->info('');

        $envPath = base_path('.env.loadtesting');
        $content = file_get_contents($envPath);

        // Auto-detect environment
        $this->autoDetectDatabase($content);
        $this->autoDetectAuthentication($content);
        $this->autoDetectServerResources($content);

        // Save the updated content
        file_put_contents($envPath, $content);

        $this->info('');
        $this->info('✅ Quick setup complete!');
        $this->warn('⚠️  Authentication is set to defaults. You may need to update credentials in .env.loadtesting');
    }

    /**
     * Interactive setup with user input
     */
    protected function interactiveSetup(): void
    {
        $this->info('');
        $this->info('🔧 Interactive Setup');
        $this->info('');

        $envPath = base_path('.env.loadtesting');
        $content = file_get_contents($envPath);

        // Step 1: Basic Configuration
        $this->setupBasicConfiguration($content);

        // Step 2: Authentication Setup
        $this->setupAuthentication($content);

        // Step 3: Database Configuration
        $this->setupDatabase($content);

        // Step 4: Performance Settings
        $this->setupPerformance($content);

        // Save the updated content
        file_put_contents($envPath, $content);

        $this->info('');
        $this->info('✅ Interactive setup complete!');
    }

    /**
     * Setup basic configuration
     */
    protected function setupBasicConfiguration(string &$content): void
    {
        $this->info('📋 Step 1: Basic Configuration');
        $this->info('');

        // Base URL
        $currentUrl = config('app.url', 'http://localhost');
        $baseUrl = $this->ask('What is your application URL?', $currentUrl);
        $content = preg_replace('/LOAD_TESTING_BASE_URL=.*/', "LOAD_TESTING_BASE_URL={$baseUrl}", $content);

        // Concurrent users
        $users = $this->ask('How many concurrent users do you want to simulate?', '50');
        $content = preg_replace('/LOAD_TESTING_CONCURRENT_USERS=.*/', "LOAD_TESTING_CONCURRENT_USERS={$users}", $content);

        // Test duration
        $duration = $this->ask('How long should the test run (in seconds)?', '60');
        $content = preg_replace('/LOAD_TESTING_DURATION=.*/', "LOAD_TESTING_DURATION={$duration}", $content);

        $this->info('✅ Basic configuration set');
        $this->info('');
    }

    /**
     * Setup authentication
     */
    protected function setupAuthentication(string &$content): void
    {
        $this->info('🔐 Step 2: Authentication Setup');
        $this->info('');

        $authEnabled = $this->confirm('Do you want to test authenticated routes?', true);
        $content = preg_replace('/LOAD_TESTING_AUTH_ENABLED=.*/', "LOAD_TESTING_AUTH_ENABLED=" . ($authEnabled ? 'true' : 'false'), $content);

        if (!$authEnabled) {
            $this->info('✅ Authentication disabled - will test public routes only');
            $this->info('');
            return;
        }

        // Detect available auth methods
        $authMethods = $this->detectAuthMethods();

        if (empty($authMethods)) {
            $this->warn('⚠️  No authentication packages detected');
            $authMethod = $this->choice('Which authentication method do you want to use?', [
                'session' => 'Session-based (Laravel default)',
                'token' => 'API Token',
                'custom' => 'Custom authentication'
            ], 'session');
        } else {
            $this->info('🔍 Detected authentication methods: ' . implode(', ', $authMethods));

            if (count($authMethods) === 1) {
                $authMethod = $authMethods[0];
                $this->info("✅ Using detected method: {$authMethod}");
            } else {
                $authMethod = $this->choice('Which authentication method do you want to use?', $authMethods, $authMethods[0]);
            }
        }

        $content = preg_replace('/LOAD_TESTING_AUTH_METHOD=.*/', "LOAD_TESTING_AUTH_METHOD={$authMethod}", $content);

        // Get credentials
        $this->info('');
        $this->info('👤 Authentication Credentials');
        $this->info('');

        if (in_array($authMethod, ['session', 'sanctum', 'passport', 'jwt'])) {
            $username = $this->ask('Enter test user email/username', 'test@example.com');
            $password = $this->secret('Enter test user password');

            if (empty($password)) {
                $password = 'password';
                $this->warn('⚠️  Using default password. Make sure this user exists!');
            }

            $content = preg_replace('/LOAD_TESTING_AUTH_USERNAME=.*/', "LOAD_TESTING_AUTH_USERNAME={$username}", $content);
            $content = preg_replace('/LOAD_TESTING_AUTH_PASSWORD=.*/', "LOAD_TESTING_AUTH_PASSWORD={$password}", $content);

            // Verify user exists
            $this->verifyTestUser($username, $password);
        }

        if (in_array($authMethod, ['passport'])) {
            $this->info('');
            $this->info('🔑 OAuth Client Configuration');
            $clientId = $this->ask('Enter OAuth Client ID (leave empty to auto-detect)');
            $clientSecret = $this->ask('Enter OAuth Client Secret (leave empty to auto-detect)');

            if ($clientId) {
                $content = preg_replace('/LOAD_TESTING_AUTH_CLIENT_ID=.*/', "LOAD_TESTING_AUTH_CLIENT_ID={$clientId}", $content);
            }
            if ($clientSecret) {
                $content = preg_replace('/LOAD_TESTING_AUTH_CLIENT_SECRET=.*/', "LOAD_TESTING_AUTH_CLIENT_SECRET={$clientSecret}", $content);
            }
        }

        $this->info('✅ Authentication configured');
        $this->info('');
    }

    /**
     * Setup database configuration
     */
    protected function setupDatabase(string &$content): void
    {
        $this->info('🗄️  Step 3: Database Configuration');
        $this->info('');

        $dbMonitoring = $this->confirm('Enable database monitoring during tests?', true);
        $content = preg_replace('/LOAD_TESTING_DB_MONITORING_ENABLED=.*/', "LOAD_TESTING_DB_MONITORING_ENABLED=" . ($dbMonitoring ? 'true' : 'false'), $content);

        if ($dbMonitoring) {
            $slowThreshold = $this->ask('Slow query threshold (milliseconds)', '100');
            $content = preg_replace('/LOAD_TESTING_DB_SLOW_THRESHOLD=.*/', "LOAD_TESTING_DB_SLOW_THRESHOLD={$slowThreshold}", $content);
        }

        $useTestDb = $this->confirm('Use a separate test database? (Recommended)', true);
        $content = preg_replace('/LOAD_TESTING_USE_TEST_DB=.*/', "LOAD_TESTING_USE_TEST_DB=" . ($useTestDb ? 'true' : 'false'), $content);

        if ($useTestDb) {
            $currentDb = config('database.connections.' . config('database.default') . '.database', 'laravel');
            $testDbName = $this->ask('Test database name', $currentDb . '_testing');
            $content = preg_replace('/LOAD_TESTING_TEST_DB_NAME=.*/', "LOAD_TESTING_TEST_DB_NAME={$testDbName}", $content);
        }

        $this->autoDetectDatabase($content);
        $this->info('✅ Database configuration set');
        $this->info('');
    }

    /**
     * Setup performance settings
     */
    protected function setupPerformance(string &$content): void
    {
        $this->info('⚡ Step 4: Performance Settings');
        $this->info('');

        $this->autoDetectServerResources($content);

        $memoryLimit = $this->ask('PHP memory limit for load testing (MB)', '512');
        $content = preg_replace('/LOAD_TESTING_MEMORY_LIMIT_MB=.*/', "LOAD_TESTING_MEMORY_LIMIT_MB={$memoryLimit}", $content);

        $timeout = $this->ask('Request timeout (seconds)', '30');
        $content = preg_replace('/LOAD_TESTING_TIMEOUT=.*/', "LOAD_TESTING_TIMEOUT={$timeout}", $content);

        $this->info('✅ Performance settings configured');
        $this->info('');
    }

    /**
     * Detect available authentication methods
     */
    protected function detectAuthMethods(): array
    {
        $methods = [];

        // Check for Laravel's default auth
        if (file_exists(app_path('Http/Controllers/Auth'))) {
            $methods[] = 'session';
        }

        // Check for Laravel Sanctum
        if (class_exists('\Laravel\Sanctum\Sanctum')) {
            $methods[] = 'sanctum';
        }

        // Check for Laravel Passport
        if (class_exists('\Laravel\Passport\Passport')) {
            $methods[] = 'passport';
        }

        // Check for JWT
        if (class_exists('\Tymon\JWTAuth\JWTAuth') || class_exists('\PHPOpenSourceSaver\JWTAuth\JWTAuth')) {
            $methods[] = 'jwt';
        }

        return $methods;
    }

    /**
     * Verify test user exists
     */
    protected function verifyTestUser(string $username, string $password): void
    {
        try {
            $userModel = config('auth.providers.users.model', 'App\Models\User');

            if (!class_exists($userModel)) {
                $userModel = 'App\User'; // Laravel 7 and below
            }

            if (class_exists($userModel)) {
                $user = $userModel::where('email', $username)->first();

                if (!$user) {
                    $this->warn("⚠️  User '{$username}' not found in database");

                    if ($this->confirm('Do you want to create this test user?', true)) {
                        $this->createTestUser($userModel, $username, $password);
                    } else {
                        $this->error('❌ Please create the test user manually or update the credentials in .env.loadtesting');
                    }
                } else {
                    $this->info("✅ Test user '{$username}' found");
                }
            }
        } catch (\Exception $e) {
            $this->warn("⚠️  Could not verify test user: " . $e->getMessage());
        }
    }

    /**
     * Create test user
     */
    protected function createTestUser(string $userModel, string $username, string $password): void
    {
        try {
            $user = new $userModel();
            $user->name = 'Load Test User';
            $user->email = $username;
            $user->password = Hash::make($password);

            if (method_exists($user, 'markEmailAsVerified')) {
                $user->email_verified_at = now();
            }

            $user->save();

            $this->info("✅ Created test user '{$username}'");
        } catch (\Exception $e) {
            $this->error("❌ Failed to create test user: " . $e->getMessage());
        }
    }

    /**
     * Auto-detect database settings
     */
    protected function autoDetectDatabase(string &$content): void
    {
        try {
            $connection = config('database.default');
            $driver = config("database.connections.{$connection}.driver");
            $database = config("database.connections.{$connection}.database");

            $this->info("🔍 Detected database: {$driver} ({$database})");

            // Update database settings in .env.loadtesting
            $content = preg_replace('/LOAD_TESTING_DB_DRIVER=.*/', "LOAD_TESTING_DB_DRIVER={$driver}", $content);

            // Configure database connection pool based on driver
            if ($driver === 'mysql') {
                try {
                    $maxConnections = DB::select("SHOW VARIABLES LIKE 'max_connections'");
                    if (!empty($maxConnections)) {
                        $maxConn = $maxConnections[0]->Value;
                        $recommendedPool = min((int)($maxConn * 0.7), 100);
                        $content = preg_replace('/LOAD_TESTING_DB_MAX_CONNECTIONS=.*/', "LOAD_TESTING_DB_MAX_CONNECTIONS={$recommendedPool}", $content);
                        $this->info("⚡ Configured MySQL connection pool: {$recommendedPool} connections");
                    }
                } catch (\Exception $e) {
                    // Use default
                }
            } else if ($driver === 'pgsql') {
                $content = preg_replace('/LOAD_TESTING_DB_MAX_CONNECTIONS=.*/', "LOAD_TESTING_DB_MAX_CONNECTIONS=50", $content);
                $this->info("⚡ Configured PostgreSQL connection pool: 50 connections");
            }
        } catch (\Exception $e) {
            $this->warn("⚠️  Could not detect database configuration: " . $e->getMessage());
        }
    }

    /**
     * Auto-detect authentication settings (for quick setup)
     */
    protected function autoDetectAuthentication(string &$content): void
    {
        $methods = $this->detectAuthMethods();

        if (!empty($methods)) {
            $this->info("🔍 Detected authentication: " . implode(', ', $methods));
            $content = preg_replace('/LOAD_TESTING_AUTH_ENABLED=.*/', "LOAD_TESTING_AUTH_ENABLED=true", $content);
            $content = preg_replace('/LOAD_TESTING_AUTH_METHOD=.*/', "LOAD_TESTING_AUTH_METHOD={$methods[0]}", $content);
        }
    }

    /**
     * Auto-detect server resources
     */
    protected function autoDetectServerResources(string &$content): void
    {
        $memoryLimit = $this->getSystemMemory();

        if ($memoryLimit) {
            $this->info("🔍 Detected system memory: {$memoryLimit}MB");

            // Calculate concurrent users based on available memory
            $recommendedUsers = min((int)($memoryLimit / 10), 100);
            $content = preg_replace('/LOAD_TESTING_CONCURRENT_USERS=.*/', "LOAD_TESTING_CONCURRENT_USERS={$recommendedUsers}", $content);
            $this->info("⚡ Recommended concurrent users: {$recommendedUsers}");
        }
    }

    /**
     * Get system memory in MB
     */
    protected function getSystemMemory(): ?int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            try {
                $wmi = new \COM('WbemScripting.SWbemLocator');
                $output = $wmi->ConnectServer('.', 'root\\CIMV2')->ExecQuery('SELECT TotalPhysicalMemory FROM Win32_ComputerSystem');

                foreach ($output as $device) {
                    return (int)($device->TotalPhysicalMemory / 1024 / 1024);
                }
            } catch (\Exception $e) {
                return null;
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            try {
                $meminfo = file_get_contents('/proc/meminfo');
                preg_match('/MemTotal:\s+(\d+)/', $meminfo, $matches);
                if (isset($matches[1])) {
                    return (int)($matches[1] / 1024);
                }
            } catch (\Exception $e) {
                return null;
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            try {
                $result = exec('sysctl -n hw.memsize');
                return (int)($result / 1024 / 1024);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Generate the environment file content
     */
    protected function generateEnvContent(string $appUrl, string $secretKey): string
    {
        return <<<EOT
# Laravel Load Testing Configuration
# Generated by: php artisan load-test:init

# =========================================
# Basic Configuration
# =========================================
LOAD_TESTING_ENABLED=true
LOAD_TESTING_BASE_URL={$appUrl}
LOAD_TESTING_SECRET_KEY={$secretKey}
LOAD_TESTING_DASHBOARD_URL=load-testing-dashboard

# =========================================
# Test Parameters
# =========================================
LOAD_TESTING_CONCURRENT_USERS=50
LOAD_TESTING_DURATION=60
LOAD_TESTING_RAMP_UP=10
LOAD_TESTING_TIMEOUT=30

# =========================================
# Authentication Settings
# =========================================
LOAD_TESTING_AUTH_ENABLED=false
LOAD_TESTING_AUTH_METHOD=auto-detect
LOAD_TESTING_AUTH_USERNAME=test@example.com
LOAD_TESTING_AUTH_PASSWORD=password
LOAD_TESTING_AUTH_CLIENT_ID=
LOAD_TESTING_AUTH_CLIENT_SECRET=

# =========================================
# Database Settings
# =========================================
LOAD_TESTING_DB_DRIVER=mysql
LOAD_TESTING_DB_SNAPSHOTS=true
LOAD_TESTING_USE_TEST_DB=false
LOAD_TESTING_TEST_DB_NAME=laravel_testing
LOAD_TESTING_DB_MAX_CONNECTIONS=100

# =========================================
# Monitoring Settings
# =========================================
LOAD_TESTING_MONITORING_ENABLED=true
LOAD_TESTING_MONITORING_INTERVAL=5
LOAD_TESTING_DB_MONITORING_ENABLED=true
LOAD_TESTING_DB_SLOW_THRESHOLD=100
LOAD_TESTING_MEMORY_LIMIT_MB=512

# =========================================
# Error Handling Settings
# =========================================
LOAD_TESTING_CIRCUIT_BREAKER_FAILURE_THRESHOLD=5
LOAD_TESTING_CIRCUIT_BREAKER_RESET_TIMEOUT=60
LOAD_TESTING_RETRY_MAX_ATTEMPTS=3
LOAD_TESTING_RETRY_INITIAL_DELAY=1

# =========================================
# Dashboard Settings
# =========================================
LOAD_TESTING_DASHBOARD_REFRESH_RATE=1
LOAD_TESTING_DASHBOARD_HISTORY_COUNT=10
LOAD_TESTING_DASHBOARD_WEBSOCKET_PORT=8080

# =========================================
# Route Configuration
# =========================================
LOAD_TESTING_INCLUDE_ROUTES=
LOAD_TESTING_EXCLUDE_ROUTES=
LOAD_TESTING_ROUTE_DISCOVERY=true

# =========================================
# Reporting Settings
# =========================================
LOAD_TESTING_REPORT_FORMAT=html
LOAD_TESTING_REPORT_PATH=storage/load-testing/reports
LOAD_TESTING_LOG_LEVEL=info
EOT;
    }

    /**
     * Display completion guide
     */
    protected function displayCompletionGuide(): void
    {
        $this->info('');
        $this->info('🎉 Setup Complete!');
        $this->info('');
        $this->info('┌─────────────────────────────────────────────┐');
        $this->info('│                Next Steps                   │');
        $this->info('└─────────────────────────────────────────────┘');
        $this->info('');
        $this->info('1. 📝 Review your configuration:');
        $this->info('   php artisan load-test:config');
        $this->info('');
        $this->info('2. 🧪 Run a quick test:');
        $this->info('   php artisan load-testing:run --users=5 --duration=30');
        $this->info('');
        $this->info('3. 📊 Access the dashboard:');
        $baseUrl = config('app.url', 'http://localhost');
        $this->info("   {$baseUrl}/load-testing-dashboard");
        $this->info('');
        $this->info('4. 🔧 Edit configuration if needed:');
        $this->info('   nano .env.loadtesting');
        $this->info('');
        $this->info('┌─────────────────────────────────────────────┐');
        $this->info('│              Important Notes                │');
        $this->info('└─────────────────────────────────────────────┘');
        $this->info('');
        $this->info('• Configuration file: .env.loadtesting');
        $this->info('• Dashboard will be available during tests');
        $this->info('• Reports saved to: storage/load-testing/reports');
        $this->info('• Always test on staging before production');
        $this->info('');
        $this->info('🚀 Happy Load Testing!');
        $this->info('');
    }
}
