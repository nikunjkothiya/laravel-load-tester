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
        
        // Check .env.loadtesting file exists
        if (!file_exists(base_path('.env.loadtesting'))) {
            $this->error('Missing .env.loadtesting file. Run: php artisan load-test:init');
            return 1;
        }
        
        // Load .env.loadtesting file
        $this->loadTestingEnvironment();
        
        // Validate configuration
        $allValid = $this->validateConfiguration();
        
        // Test authentication if valid configuration
        if ($allValid && config('load-testing.auth.enabled', false)) {
            $this->testAuthentication();
        }
        
        // Test database configuration
        $this->testDatabaseConfiguration();
        
        $this->info('Configuration validation complete.');
        
        return $allValid ? 0 : 1;
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
     * Validate configuration values
     *
     * @return bool
     */
    protected function validateConfiguration(): bool
    {
        $this->info('Checking configuration values...');
        
        $checks = [
            'LOAD_TESTING_BASE_URL' => 'Base URL',
            'LOAD_TESTING_CONCURRENT_USERS' => 'Concurrent users',
            'LOAD_TESTING_DURATION' => 'Test duration',
        ];
        
        if (config('load-testing.auth.enabled', false)) {
            $checks['LOAD_TESTING_AUTH_METHOD'] = 'Authentication method';
            
            if (config('load-testing.auth.method') !== 'auto-detect') {
                $checks['LOAD_TESTING_AUTH_USERNAME'] = 'Authentication username';
                $checks['LOAD_TESTING_AUTH_PASSWORD'] = 'Authentication password';
            }
        }
        
        $allValid = true;
        foreach ($checks as $var => $description) {
            $value = env($var);
            if ($value) {
                $this->info("✓ {$description}: " . $value);
            } else {
                $this->error("✗ Missing {$description} ({$var})");
                $allValid = false;
            }
        }
        
        // Check base URL
        $baseUrl = config('load-testing.base_url');
        if ($baseUrl) {
            try {
                $client = new \GuzzleHttp\Client([
                    'base_uri' => $baseUrl,
                    'timeout' => 5,
                    'verify' => false,
                ]);
                
                $response = $client->get('/');
                $statusCode = $response->getStatusCode();
                
                if ($statusCode >= 200 && $statusCode < 500) {
                    $this->info("✓ Base URL is accessible: {$baseUrl} (Status: {$statusCode})");
                } else {
                    $this->warn("⚠ Base URL returned status code {$statusCode}: {$baseUrl}");
                }
            } catch (\Exception $e) {
                $this->error("✗ Cannot connect to base URL: {$baseUrl} - " . $e->getMessage());
                $allValid = false;
            }
        }
        
        return $allValid;
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