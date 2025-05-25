<?php

namespace NikunjKothiya\LaravelLoadTesting\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class InitLoadTestingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'load-test:init {--force : Force recreate the .env.loadtesting file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize load testing configuration with automatic environment setup';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('');
        $this->info('┌─────────────────────────────────────────────┐');
        $this->info('│                                             │');
        $this->info('│      Laravel Load Testing Initialization    │');
        $this->info('│                                             │');
        $this->info('└─────────────────────────────────────────────┘');
        $this->info('');
        
        // Create .env.loadtesting file
        $this->createEnvironmentFile();
        
        // Publish configuration if not already published
        if (!file_exists(config_path('load-testing.php'))) {
            $this->call('vendor:publish', [
                '--provider' => 'NikunjKothiya\LaravelLoadTesting\LoadTestingServiceProvider',
                '--tag' => 'config'
            ]);
        }
        
        // Auto-detect and configure environment
        $this->autoDetectEnvironment();
        
        $this->info('');
        $this->info('✓ Load testing environment setup complete!');
        $this->info('');
        $this->info('Your .env.loadtesting file has been created with optimal settings.');
        $this->info('You can now run load tests with: php artisan load-test:run');
        $this->info('');
        
        return 0;
    }
    
    /**
     * Create the .env.loadtesting file
     *
     * @return void
     */
    protected function createEnvironmentFile(): void
    {
        $targetPath = base_path('.env.loadtesting');
        
        if (file_exists($targetPath) && !$this->option('force')) {
            $this->warn('  → .env.loadtesting already exists');
            
            if ($this->confirm('  Do you want to recreate it with new optimal settings?', false)) {
                // Continue with creation (force)
                $this->info('  → Recreating .env.loadtesting file');
            } else {
                $this->info('  → Using existing .env.loadtesting file');
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
        $this->info('  ✓ Created .env.loadtesting configuration file');
    }
    
    /**
     * Auto-detect and configure the environment
     *
     * @return void
     */
    protected function autoDetectEnvironment(): void
    {
        $this->info('');
        $this->info('  Detecting your environment settings...');
        
        $envPath = base_path('.env.loadtesting');
        $content = file_get_contents($envPath);
        
        // Detect database settings
        $this->autoDetectDatabase($content);
        
        // Detect authentication settings
        $this->autoDetectAuthentication($content);
        
        // Detect server resources
        $this->autoDetectServerResources($content);
        
        // Save the updated content
        file_put_contents($envPath, $content);
    }
    
    /**
     * Auto-detect database settings
     *
     * @param string &$content The .env file content
     * @return void
     */
    protected function autoDetectDatabase(string &$content): void
    {
        try {
            $connection = config('database.default');
            $driver = config("database.connections.{$connection}.driver");
            $database = config("database.connections.{$connection}.database");
            
            $this->info("  → Detected database: {$driver} ({$database})");
            
            // Update database settings in .env.loadtesting
            $content = preg_replace('/LOAD_TESTING_DB_DRIVER=.*/', "LOAD_TESTING_DB_DRIVER={$driver}", $content);
            
            // Suggest test database name
            $testDbName = $database . '_testing';
            $content = preg_replace('/LOAD_TESTING_TEST_DB_NAME=.*/', "LOAD_TESTING_TEST_DB_NAME={$testDbName}", $content);
            
            // Configure database connection pool based on driver
            if ($driver === 'mysql') {
                // Try to get max_connections from MySQL
                try {
                    $maxConnections = DB::select("SHOW VARIABLES LIKE 'max_connections'");
                    if (!empty($maxConnections)) {
                        $maxConn = $maxConnections[0]->Value;
                        $recommendedPool = min((int)($maxConn * 0.7), 100);
                        $content = preg_replace('/LOAD_TESTING_DB_MAX_CONNECTIONS=.*/', "LOAD_TESTING_DB_MAX_CONNECTIONS={$recommendedPool}", $content);
                        $this->info("  → Configured MySQL connection pool: {$recommendedPool} connections");
                    }
                } catch (\Exception $e) {
                    // Use default
                }
            } else if ($driver === 'pgsql') {
                // PostgreSQL typically has lower max_connections
                $content = preg_replace('/LOAD_TESTING_DB_MAX_CONNECTIONS=.*/', "LOAD_TESTING_DB_MAX_CONNECTIONS=50", $content);
                $this->info("  → Configured PostgreSQL connection pool: 50 connections");
            }
        } catch (\Exception $e) {
            $this->warn("  → Could not detect database configuration: " . $e->getMessage());
        }
    }
    
    /**
     * Auto-detect authentication settings
     *
     * @param string &$content The .env file content
     * @return void
     */
    protected function autoDetectAuthentication(string &$content): void
    {
        // Check if using Laravel's default auth
        if (file_exists(app_path('Http/Controllers/Auth'))) {
            $this->info("  → Detected Laravel authentication");
            $content = preg_replace('/LOAD_TESTING_AUTH_ENABLED=.*/', "LOAD_TESTING_AUTH_ENABLED=true", $content);
            $content = preg_replace('/LOAD_TESTING_AUTH_METHOD=.*/', "LOAD_TESTING_AUTH_METHOD=auto-detect", $content);
        }
        
        // Check for Laravel Sanctum
        if (class_exists('\Laravel\Sanctum\Sanctum')) {
            $this->info("  → Detected Laravel Sanctum");
            $content = preg_replace('/LOAD_TESTING_AUTH_ENABLED=.*/', "LOAD_TESTING_AUTH_ENABLED=true", $content);
            $content = preg_replace('/LOAD_TESTING_AUTH_METHOD=.*/', "LOAD_TESTING_AUTH_METHOD=sanctum", $content);
        }
        
        // Check for Laravel Passport
        if (class_exists('\Laravel\Passport\Passport')) {
            $this->info("  → Detected Laravel Passport");
            $content = preg_replace('/LOAD_TESTING_AUTH_ENABLED=.*/', "LOAD_TESTING_AUTH_ENABLED=true", $content);
            $content = preg_replace('/LOAD_TESTING_AUTH_METHOD=.*/', "LOAD_TESTING_AUTH_METHOD=passport", $content);
        }
        
        // Check for JWT
        if (class_exists('\Tymon\JWTAuth\JWTAuth') || class_exists('\PHPOpenSourceSaver\JWTAuth\JWTAuth')) {
            $this->info("  → Detected JWT Authentication");
            $content = preg_replace('/LOAD_TESTING_AUTH_ENABLED=.*/', "LOAD_TESTING_AUTH_ENABLED=true", $content);
            $content = preg_replace('/LOAD_TESTING_AUTH_METHOD=.*/', "LOAD_TESTING_AUTH_METHOD=jwt", $content);
        }
    }
    
    /**
     * Auto-detect server resources
     *
     * @param string &$content The .env file content
     * @return void
     */
    protected function autoDetectServerResources(string &$content): void
    {
        // Get system memory
        $memoryLimit = $this->getSystemMemory();
        
        if ($memoryLimit) {
            $this->info("  → Detected system memory: {$memoryLimit}MB");
            
            // Calculate concurrent users based on available memory
            // Use a conservative estimate of 10MB per concurrent user
            $recommendedUsers = min((int)($memoryLimit / 10), 100);
            
            $content = preg_replace('/LOAD_TESTING_CONCURRENT_USERS=.*/', "LOAD_TESTING_CONCURRENT_USERS={$recommendedUsers}", $content);
            $this->info("  → Configured concurrent users: {$recommendedUsers}");
        }
    }
    
    /**
     * Get system memory in MB
     *
     * @return int|null
     */
    protected function getSystemMemory(): ?int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows
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
            // Linux
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
            // macOS
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
     *
     * @param string $appUrl
     * @param string $secretKey
     * @return string
     */
    protected function generateEnvContent(string $appUrl, string $secretKey): string
    {
        return <<<EOT
# Laravel Load Testing Configuration
# Auto-generated by the load-test:init command

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
# Additional Settings
# =========================================
LOAD_TESTING_LOG_LEVEL=info
LOAD_TESTING_REPORT_FORMAT=html
EOT;
    }
} 