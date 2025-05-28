<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use React\EventLoop\Factory as ReactFactory;
use React\Http\Browser;
use Clue\React\Buzz\Browser as ReactBrowser;

class LoadTestingService
{
    /**
     * @var array Configuration
     */
    protected $config;

    /**
     * @var array Test results
     */
    protected $results = [];

    /**
     * @var array Discovered routes
     */
    protected $routes = [];

    /**
     * @var array Session tokens for authenticated users
     */
    protected $sessionTokens = [];

    /**
     * @var array Resource usage metrics
     */
    protected $resourceUsage = [];

    /**
     * @var array Database query log
     */
    protected $queryLog = [];

    /**
     * @var float Test start time
     */
    protected $startTime;

    /**
     * @var float Test end time
     */
    protected $endTime;

    /**
     * @var AuthManager Authentication manager
     */
    protected $authManager;

    /**
     * @var DatabaseManager Database manager
     */
    protected $databaseManager;

    /**
     * @var MetricsCollector Metrics collector
     */
    protected $metricsCollector;

    /**
     * @var ResourceManager Resource manager
     */
    protected $resourceManager;

    /**
     * @var \React\EventLoop\LoopInterface|null Event loop for async operations
     */
    protected $eventLoop;

    /**
     * @var mixed|null Async HTTP client
     */
    protected $asyncClient;

    /**
     * WebSocket service for real-time updates
     *
     * @var \NikunjKothiya\LaravelLoadTesting\Services\DashboardWebSocket|null
     */
    protected $webSocket;

    /**
     * Create a new LoadTestingService instance.
     * 
     * @param AuthManager|null $authManager
     * @param DatabaseManager|null $databaseManager
     * @param MetricsCollector|null $metricsCollector
     * @param ResourceManager|null $resourceManager
     */
    public function __construct(
        AuthManager $authManager = null,
        DatabaseManager $databaseManager = null,
        MetricsCollector $metricsCollector = null,
        ResourceManager $resourceManager = null
    ) {
        $this->config = config('load-testing');
        $this->authManager = $authManager ?? app(AuthManager::class);
        $this->databaseManager = $databaseManager ?? app(DatabaseManager::class);
        $this->metricsCollector = $metricsCollector ?? app(MetricsCollector::class);
        $this->resourceManager = $resourceManager ?? app(ResourceManager::class);

        // Initialize async client if ReactPHP is available
        if (class_exists('React\EventLoop\Factory')) {
            try {
                $factoryClass = 'React\EventLoop\Factory';
                $this->eventLoop = $factoryClass::create();

                if (class_exists('Clue\React\Buzz\Browser')) {
                    $browserClass = 'Clue\React\Buzz\Browser';
                    $this->asyncClient = new $browserClass($this->eventLoop);
                }

                // Register for cleanup
                $this->resourceManager->registerResource('event_loop', $this->eventLoop);
            } catch (\Exception $e) {
                Log::warning('Failed to initialize ReactPHP components: ' . $e->getMessage());
                $this->eventLoop = null;
                $this->asyncClient = null;
            }
        }

        // Try to get WebSocket service if available
        try {
            $this->webSocket = app(DashboardWebSocket::class);
        } catch (\Exception $e) {
            // WebSocket service not available, continue without it
            $this->webSocket = null;
        }
    }

    /**
     * Prepare the environment for load testing.
     *
     * @return self
     * @throws \Exception
     */
    public function prepare(): self
    {
        try {
            // Get all application routes
            $this->discoverRoutes();

            // Prepare database if needed
            $this->databaseManager->prepare();

            // Create test users if authentication is enabled
            if ($this->config['auth']['enabled']) {
                $this->createTestUsers();
            }

            return $this;
        } catch (\Exception $e) {
            Log::error('Failed to prepare load testing environment: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Run the load test.
     *
     * @return array
     * @throws \Exception
     */
    public function run(): array
    {
        try {
            $this->startTime = microtime(true);

            // Start resource monitoring
            if ($this->config['monitoring']['enabled']) {
                $this->startResourceMonitoring();
            }

            // Start database query monitoring
            if ($this->config['monitoring']['database']['enabled'] ?? false) {
                $this->startDatabaseMonitoring();
            }

            // Authenticate test users if needed
            if ($this->config['auth']['enabled']) {
                $this->authenticateUsers();
            }

            // Run the load test
            $this->runLoadTest();

            // Stop database query monitoring
            if ($this->config['monitoring']['database']['enabled'] ?? false) {
                $this->stopDatabaseMonitoring();
            }

            // Stop resource monitoring
            if ($this->config['monitoring']['enabled']) {
                $this->stopResourceMonitoring();
            }

            $this->endTime = microtime(true);

            // Generate reports
            $this->generateReports();

            return $this->results;
        } catch (\Exception $e) {
            Log::error('Load test execution failed: ' . $e->getMessage());
            throw $e;
        } finally {
            // Ensure cleanup happens even if test fails
            $this->resourceManager->cleanup();
        }
    }

    /**
     * Discover routes for load testing.
     */
    protected function discoverRoutes()
    {
        try {
            $routes = Route::getRoutes();
            $testableRoutes = [];
            $excludedRoutes = [];
            $skippedRoutes = [];

            // Get project-specific configuration
            $projectConfig = $this->detectProjectStructure();

            // Convert RouteCollection to array for counting
            $routeArray = [];
            foreach ($routes as $route) {
                $routeArray[] = $route;
            }

            foreach ($routes as $route) {
                $uri = $route->uri();
                $methods = $route->methods();
                $name = $route->getName();
                $middleware = $route->middleware();
                $action = $route->getAction();

                // Enhanced route filtering with project-aware logic
                $exclusionResult = $this->shouldExcludeRouteAdvanced($uri, $middleware, $action, $projectConfig);

                if ($exclusionResult['exclude']) {
                    $excludedRoutes[] = [
                        'uri' => $uri,
                        'reason' => $exclusionResult['reason']
                    ];
                    continue;
                }

                // Process different HTTP methods with intelligent handling
                foreach ($methods as $method) {
                    // Skip HEAD and OPTIONS methods
                    if (in_array($method, ['HEAD', 'OPTIONS'])) {
                        continue;
                    }

                    // Enhanced method handling - support GET, POST with smart data generation
                    if (in_array($method, ['GET', 'POST'])) {
                        $processedRoute = $this->processRouteAdvanced($uri, $method, $middleware, $action, $projectConfig);

                        if ($processedRoute !== null) {
                            $testableRoutes[] = [
                                'uri' => $processedRoute['uri'],
                                'original_uri' => $uri,
                                'method' => $method,
                                'name' => $name,
                                'middleware' => $middleware,
                                'action' => $action,
                                'requires_auth' => $this->routeRequiresAuthAdvanced($middleware, $action),
                                'auth_guards' => $this->extractAuthGuards($middleware),
                                'parameters' => $processedRoute['parameters'] ?? [],
                                'form_data' => $processedRoute['form_data'] ?? [],
                                'project_type' => $projectConfig['type'],
                                'priority' => $this->calculateRoutePriority($uri, $name, $middleware),
                            ];
                        } else {
                            $skippedRoutes[] = [
                                'uri' => $uri,
                                'method' => $method,
                                'reason' => 'Complex parameters or unsupported structure'
                            ];
                        }
                    }
                }
            }

            // Intelligent route prioritization and limiting
            $testableRoutes = $this->prioritizeAndLimitRoutes($testableRoutes, $projectConfig);

            $this->routes = $testableRoutes;

            // Enhanced logging with project insights
            Log::info("Route Discovery Complete", [
                'project_type' => $projectConfig['type'],
                'total_routes' => count($routeArray),
                'testable_routes' => count($testableRoutes),
                'excluded_routes' => count($excludedRoutes),
                'skipped_routes' => count($skippedRoutes),
                'auth_routes' => count(array_filter($testableRoutes, fn($r) => $r['requires_auth'])),
            ]);

            return $this;
        } catch (\Exception $e) {
            Log::error('Route discovery failed: ' . $e->getMessage());
            // Fallback to basic route discovery
            return $this->discoverRoutesBasic();
        }
    }

    /**
     * Detect project structure and type for intelligent adaptation.
     */
    protected function detectProjectStructure(): array
    {
        $structure = [
            'type' => 'standard',
            'auth_system' => 'session',
            'api_routes' => false,
            'spa_mode' => false,
            'admin_panel' => false,
            'multi_tenant' => false,
            'packages' => [],
            'custom_guards' => [],
        ];

        try {
            // Detect Laravel packages and structure
            $composerPath = base_path('composer.json');
            if (File::exists($composerPath)) {
                $composer = json_decode(File::get($composerPath), true);
                $packages = array_keys($composer['require'] ?? []);
                $structure['packages'] = $packages;

                // Detect common packages
                if (in_array('laravel/sanctum', $packages)) {
                    $structure['auth_system'] = 'sanctum';
                }
                if (in_array('laravel/passport', $packages)) {
                    $structure['auth_system'] = 'passport';
                }
                if (in_array('tymon/jwt-auth', $packages) || in_array('php-open-source-saver/jwt-auth', $packages)) {
                    $structure['auth_system'] = 'jwt';
                }
                if (in_array('laravel/nova', $packages)) {
                    $structure['admin_panel'] = 'nova';
                }
                if (in_array('filament/filament', $packages)) {
                    $structure['admin_panel'] = 'filament';
                }
                if (in_array('spatie/laravel-multitenancy', $packages)) {
                    $structure['multi_tenant'] = true;
                }
            }

            // Detect API routes
            if (File::exists(base_path('routes/api.php'))) {
                $structure['api_routes'] = true;
            }

            // Detect SPA mode
            if (
                File::exists(base_path('resources/js/app.js')) ||
                File::exists(base_path('resources/js/app.vue')) ||
                File::exists(base_path('resources/js/app.tsx'))
            ) {
                $structure['spa_mode'] = true;
            }

            // Detect custom auth guards
            $authConfig = config('auth.guards', []);
            $structure['custom_guards'] = array_keys($authConfig);

            // Determine project type
            if ($structure['api_routes'] && $structure['spa_mode']) {
                $structure['type'] = 'spa_api';
            } elseif ($structure['api_routes']) {
                $structure['type'] = 'api';
            } elseif ($structure['spa_mode']) {
                $structure['type'] = 'spa';
            } elseif ($structure['admin_panel']) {
                $structure['type'] = 'admin';
            }
        } catch (\Exception $e) {
            Log::warning('Project structure detection failed: ' . $e->getMessage());
        }

        return $structure;
    }

    /**
     * Advanced route exclusion with project-aware logic.
     */
    protected function shouldExcludeRouteAdvanced($uri, $middleware, $action, $projectConfig): array
    {
        // Base exclusions that apply to all projects
        $baseExclusions = [
            'telescope*' => 'Laravel Telescope',
            'horizon*' => 'Laravel Horizon',
            '_debugbar*' => 'Debug Bar',
            'nova*' => 'Laravel Nova',
            'nova-api*' => 'Laravel Nova API',
            'password/*' => 'Password Reset',
            'email/verify*' => 'Email Verification',
            'logout' => 'Logout Route',
            'load-testing-dashboard*' => 'Load Testing Dashboard',
        ];

        // Project-specific exclusions
        $projectExclusions = [];

        switch ($projectConfig['type']) {
            case 'admin':
                $projectExclusions = array_merge($projectExclusions, [
                    'admin/login' => 'Admin Login',
                    'admin/logout' => 'Admin Logout',
                    'filament*' => 'Filament Admin',
                ]);
                break;

            case 'api':
                // For API projects, be more permissive but exclude auth endpoints
                $projectExclusions = array_merge($projectExclusions, [
                    'api/auth/login' => 'API Login',
                    'api/auth/logout' => 'API Logout',
                    'api/auth/refresh' => 'Token Refresh',
                ]);
                break;

            case 'spa_api':
                $projectExclusions = array_merge($projectExclusions, [
                    'sanctum/csrf-cookie' => 'CSRF Cookie',
                    'api/user' => 'Current User Endpoint',
                ]);
                break;
        }

        // Multi-tenant exclusions
        if ($projectConfig['multi_tenant']) {
            $projectExclusions['tenant/*'] = 'Tenant Management';
        }

        // Custom exclusions from config
        $configExclusions = $this->config['routes']['exclude'] ?? [];

        // Combine all exclusions
        $allExclusions = array_merge($baseExclusions, $projectExclusions);

        foreach ($configExclusions as $pattern) {
            $allExclusions[$pattern] = 'Custom Exclusion';
        }

        // Check exclusions
        foreach ($allExclusions as $pattern => $reason) {
            if (fnmatch($pattern, $uri)) {
                return ['exclude' => true, 'reason' => $reason];
            }
        }

        // Check middleware-based exclusions
        $middlewareExclusion = $this->shouldExcludeByMiddlewareAdvanced($middleware, $projectConfig);
        if ($middlewareExclusion['exclude']) {
            return $middlewareExclusion;
        }

        // Check action-based exclusions
        if (isset($action['controller'])) {
            $controller = $action['controller'];

            // Exclude certain controller types
            $excludedControllers = [
                'Auth\\' => 'Authentication Controller',
                'Password\\' => 'Password Controller',
                'Verification\\' => 'Email Verification Controller',
            ];

            foreach ($excludedControllers as $pattern => $reason) {
                if (str_contains($controller, $pattern)) {
                    return ['exclude' => true, 'reason' => $reason];
                }
            }
        }

        // Include only specific routes if configured
        if (!empty($this->config['routes']['include'])) {
            $includePatterns = $this->config['routes']['include'];
            $shouldInclude = false;

            foreach ($includePatterns as $pattern) {
                if (fnmatch($pattern, $uri)) {
                    $shouldInclude = true;
                    break;
                }
            }

            if (!$shouldInclude) {
                return ['exclude' => true, 'reason' => 'Not in include list'];
            }
        }

        return ['exclude' => false, 'reason' => null];
    }

    /**
     * Advanced middleware-based exclusion.
     */
    protected function shouldExcludeByMiddlewareAdvanced($middleware, $projectConfig): array
    {
        $excludeMiddleware = [
            'verified' => 'Email Verification Required',
            'password.confirm' => 'Password Confirmation Required',
            'signed' => 'Signed Route',
            'throttle:1' => 'Heavy Rate Limiting',
            'can:' => 'Permission-based Route',
            'role:' => 'Role-based Route',
            'permission:' => 'Permission-based Route',
        ];

        // Project-specific middleware exclusions
        if ($projectConfig['type'] === 'admin') {
            $excludeMiddleware['admin'] = 'Admin Middleware';
            $excludeMiddleware['super-admin'] = 'Super Admin Middleware';
        }

        foreach ($middleware as $mw) {
            foreach ($excludeMiddleware as $exclude => $reason) {
                if (str_starts_with($mw, $exclude)) {
                    return ['exclude' => true, 'reason' => $reason];
                }
            }
        }

        return ['exclude' => false, 'reason' => null];
    }

    /**
     * Advanced route processing with intelligent parameter handling.
     */
    protected function processRouteAdvanced($uri, $method, $middleware, $action, $projectConfig): ?array
    {
        $result = [
            'uri' => $uri,
            'parameters' => [],
            'form_data' => [],
        ];

        // Handle route parameters
        if (preg_match_all('/\{([^}]+)\}/', $uri, $matches)) {
            foreach ($matches[1] as $parameter) {
                // Skip optional parameters for now
                if (str_contains($parameter, '?')) {
                    return null;
                }

                // Get intelligent sample value
                $sampleValue = $this->getIntelligentSampleValue($parameter, $projectConfig);
                $result['uri'] = str_replace('{' . $parameter . '}', $sampleValue, $result['uri']);
                $result['parameters'][$parameter] = $sampleValue;
            }
        }

        // Generate form data for POST requests
        if ($method === 'POST') {
            $result['form_data'] = $this->generateFormData($action, $projectConfig);
        }

        return $result;
    }

    /**
     * Get intelligent sample values based on project context.
     */
    protected function getIntelligentSampleValue($parameter, $projectConfig): string
    {
        // Enhanced parameter mapping with project awareness
        $sampleValues = [
            'id' => '1',
            'user_id' => '1',
            'post_id' => '1',
            'article_id' => '1',
            'product_id' => '1',
            'category_id' => '1',
            'order_id' => '1',
            'invoice_id' => '1',
            'user' => '1',
            'post' => '1',
            'article' => '1',
            'product' => '1',
            'category' => '1',
            'slug' => 'sample-slug',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'token' => 'sample-token',
            'hash' => 'sample-hash',
            'code' => 'ABC123',
            'email' => 'test@example.com',
            'username' => 'testuser',
        ];

        // Project-specific parameter handling
        if ($projectConfig['multi_tenant']) {
            $sampleValues['tenant'] = 'test-tenant';
            $sampleValues['tenant_id'] = '1';
        }

        if ($projectConfig['type'] === 'api') {
            $sampleValues['version'] = 'v1';
            $sampleValues['api_version'] = 'v1';
        }

        // Check for parameter patterns
        $paramLower = strtolower($parameter);
        foreach ($sampleValues as $pattern => $value) {
            if (str_contains($paramLower, $pattern)) {
                return $value;
            }
        }

        // Try to get real data from database for better testing
        return $this->getRealisticSampleValue($parameter);
    }

    /**
     * Get realistic sample values from database.
     */
    protected function getRealisticSampleValue($parameter): string
    {
        try {
            $paramLower = strtolower($parameter);

            // Try to find real IDs from database
            if (str_contains($paramLower, 'user') && Schema::hasTable('users')) {
                $user = DB::table('users')->first();
                if ($user) {
                    return (string) $user->id;
                }
            }

            if (str_contains($paramLower, 'post') && Schema::hasTable('posts')) {
                $post = DB::table('posts')->first();
                if ($post) {
                    return (string) $post->id;
                }
            }

            if (str_contains($paramLower, 'product') && Schema::hasTable('products')) {
                $product = DB::table('products')->first();
                if ($product) {
                    return (string) $product->id;
                }
            }
        } catch (\Exception $e) {
            // Ignore database errors and use default
        }

        // Default fallback
        return '1';
    }

    /**
     * Generate form data for POST requests.
     */
    protected function generateFormData($action, $projectConfig): array
    {
        // Basic form data that works for most Laravel forms
        $formData = [];

        // Add CSRF token if needed
        if ($projectConfig['type'] !== 'api') {
            $formData['_token'] = 'test-csrf-token';
        }

        // Add common form fields based on route action
        if (isset($action['controller'])) {
            $controller = $action['controller'];

            if (str_contains($controller, 'Contact')) {
                $formData = array_merge($formData, [
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'message' => 'This is a test message for load testing.',
                ]);
            }

            if (str_contains($controller, 'Comment')) {
                $formData = array_merge($formData, [
                    'content' => 'This is a test comment for load testing.',
                    'author_name' => 'Test User',
                ]);
            }
        }

        return $formData;
    }

    /**
     * Enhanced authentication detection.
     */
    protected function routeRequiresAuthAdvanced($middleware, $action): bool
    {
        // Standard auth middleware
        $authMiddleware = ['auth', 'auth:web', 'auth:api', 'auth:sanctum', 'auth:passport'];

        foreach ($middleware as $mw) {
            if (in_array($mw, $authMiddleware) || str_starts_with($mw, 'auth:')) {
                return true;
            }
        }

        // Check controller-based auth
        if (isset($action['controller'])) {
            $controller = $action['controller'];

            // Controllers that typically require auth
            $authControllers = [
                'Dashboard',
                'Profile',
                'Account',
                'Settings',
                'Admin',
            ];

            foreach ($authControllers as $authController) {
                if (str_contains($controller, $authController)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract authentication guards from middleware.
     */
    protected function extractAuthGuards($middleware): array
    {
        $guards = [];

        foreach ($middleware as $mw) {
            if (str_starts_with($mw, 'auth:')) {
                $guard = substr($mw, 5);
                if (!empty($guard)) {
                    $guards[] = $guard;
                }
            }
        }

        return $guards ?: ['web']; // Default to web guard
    }

    /**
     * Calculate route priority for testing.
     */
    protected function calculateRoutePriority($uri, $name, $middleware): int
    {
        $priority = 50; // Base priority

        // Higher priority for important routes
        if ($uri === '/' || $uri === 'home') {
            $priority += 30;
        }

        if (str_contains($uri, 'api/')) {
            $priority += 20;
        }

        if (!empty($name)) {
            $priority += 10;
        }

        // Lower priority for complex routes
        if (substr_count($uri, '/') > 3) {
            $priority -= 10;
        }

        if (preg_match('/\{[^}]+\}/', $uri)) {
            $priority -= 5;
        }

        return max(0, min(100, $priority));
    }

    /**
     * Prioritize and limit routes intelligently.
     */
    protected function prioritizeAndLimitRoutes($routes, $projectConfig): array
    {
        // Sort by priority
        usort($routes, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        // Intelligent limiting based on project type
        $maxRoutes = $this->config['routes']['max_routes'] ?? 100;

        if ($projectConfig['type'] === 'api') {
            $maxRoutes = min($maxRoutes, 150); // APIs can handle more routes
        } elseif ($projectConfig['type'] === 'spa_api') {
            $maxRoutes = min($maxRoutes, 120);
        }

        if (count($routes) > $maxRoutes) {
            Log::info("Limiting routes from " . count($routes) . " to {$maxRoutes} based on priority");
            $routes = array_slice($routes, 0, $maxRoutes);
        }

        return $routes;
    }

    /**
     * Fallback basic route discovery.
     */
    protected function discoverRoutesBasic()
    {
        $routes = Route::getRoutes();
        $testableRoutes = [];

        foreach ($routes as $route) {
            $uri = $route->uri();
            $methods = $route->methods();

            if (in_array('GET', $methods) && !str_contains($uri, '{')) {
                $testableRoutes[] = [
                    'uri' => $uri,
                    'original_uri' => $uri,
                    'method' => 'GET',
                    'name' => $route->getName(),
                    'middleware' => $route->middleware(),
                    'requires_auth' => false,
                    'auth_guards' => ['web'],
                    'parameters' => [],
                    'form_data' => [],
                    'project_type' => 'standard',
                    'priority' => 50,
                ];
            }
        }

        $this->routes = array_slice($testableRoutes, 0, 20); // Limit to 20 for safety
        Log::warning("Using basic route discovery. Found " . count($this->routes) . " routes");

        return $this;
    }

    /**
     * Create test users for authentication testing.
     */
    protected function createTestUsers()
    {
        $table = $this->config['auth']['table'];
        $concurrentUsers = $this->config['test']['concurrent_users'];
        $username = $this->config['auth']['credentials']['username'];
        $passwordHash = $this->config['auth']['credentials']['password_hash'];

        // Check if test users already exist
        $existingUsers = DB::table($table)
            ->where($this->config['auth']['username_field'], 'like', 'loadtest_%')
            ->count();

        if ($existingUsers >= $concurrentUsers) {
            return;
        }

        // Create additional test users as needed
        $usersToCreate = $concurrentUsers - $existingUsers;

        for ($i = 0; $i < $usersToCreate; $i++) {
            $userData = [
                $this->config['auth']['username_field'] => 'loadtest_' . uniqid(),
                $this->config['auth']['password_field'] => $passwordHash,
                'name' => 'Load Test User ' . ($existingUsers + $i + 1),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table($table)->insert($userData);
        }
    }

    /**
     * Authenticate test users.
     */
    protected function authenticateUsers()
    {
        if (!$this->config['auth']['enabled']) {
            return;
        }

        $concurrentUsers = $this->config['test']['concurrent_users'];
        $users = $this->getTestUsers($concurrentUsers);

        // Use the AuthManager for authentication
        $result = $this->authManager->prepareAuthentication();

        if ($result['success'] ?? false) {
            Log::info('Authentication successful');

            // Store auth info for future requests
            $this->sessionTokens = $result;
        } else {
            Log::error('Authentication failed: ' . ($result['error'] ?? 'Unknown error'));
        }
    }

    /**
     * Get test users for authentication.
     */
    protected function getTestUsers($concurrentUsers)
    {
        // Check if multiple users are defined in config
        $multipleUsers = $this->config['auth']['multiple_users'] ?? null;

        if ($multipleUsers) {
            try {
                $usersArray = json_decode($multipleUsers, true);

                if (is_array($usersArray) && !empty($usersArray)) {
                    // Return the defined user array, repeating if necessary to match concurrentUsers
                    $result = [];
                    $index = 0;

                    for ($i = 0; $i < $concurrentUsers; $i++) {
                        $result[] = $usersArray[$index % count($usersArray)];
                        $index++;
                    }

                    return $result;
                }
            } catch (\Exception $e) {
                Log::error('Failed to parse multiple users JSON: ' . $e->getMessage());
            }
        }

        // Get test users from database
        $table = $this->config['auth']['table'];
        $usernameField = $this->config['auth']['session']['username_field'] ?? $this->config['auth']['username_field'] ?? 'email';

        $dbUsers = DB::table($table)
            ->where($usernameField, 'like', 'loadtest_%')
            ->limit($concurrentUsers)
            ->get();

        if ($dbUsers->count() >= $concurrentUsers) {
            return $dbUsers;
        }

        // If we don't have enough users, use the default credentials
        $defaultUser = [
            $usernameField => $this->config['auth']['credentials']['username'],
            'password' => $this->config['auth']['credentials']['password'],
        ];

        $users = [];
        for ($i = 0; $i < $concurrentUsers; $i++) {
            $users[] = (object) $defaultUser;
        }

        return $users;
    }

    /**
     * Start monitoring system resources.
     */
    protected function startResourceMonitoring()
    {
        // Initialize resources array
        $this->resourceUsage = [
            'memory' => [],
            'cpu' => [],
            'time' => []
        ];

        // Create a monitoring process
        $interval = $this->config['monitoring']['interval'] ?? 5;

        // Use a separate thread/process if available
        if (function_exists('pcntl_fork') && extension_loaded('pcntl')) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                // Error forking process
                Log::error('Could not fork resource monitoring process');
            } elseif ($pid) {
                // Parent process
                $this->resourceManager->registerResource('monitoring_pid', $pid);
            } else {
                // Child process
                while (true) {
                    // Get current memory and CPU usage
                    $memory = memory_get_usage(true) / 1024 / 1024; // Convert to MB
                    $cpuUsage = $this->getCpuUsage();

                    // Send metrics to the collector
                    $this->metricsCollector->updateResourceUsage($memory, $cpuUsage);

                    // Sleep for the interval
                    sleep($interval);
                }

                exit(0);
            }
        } else {
            // Fallback to regular interval monitoring
            register_tick_function(function () use ($interval) {
                static $lastCheck = 0;

                if (time() - $lastCheck >= $interval) {
                    $memory = memory_get_usage(true) / 1024 / 1024; // Convert to MB
                    $cpuUsage = $this->getCpuUsage();

                    // Send metrics to the collector
                    $this->metricsCollector->updateResourceUsage($memory, $cpuUsage);

                    $lastCheck = time();
                }
            });

            // Enable tick function execution
            declare(ticks=1);
        }
    }

    /**
     * Stop monitoring system resources.
     */
    protected function stopResourceMonitoring()
    {
        // Kill the monitoring process if it exists
        if (isset($this->resourceManager->resources['monitoring_pid'])) {
            $pid = $this->resourceManager->resources['monitoring_pid']['resource'];
            posix_kill($pid, SIGTERM);
        }

        // Unregister the tick function if used
        unregister_tick_function(function () {});
    }

    /**
     * Start monitoring database queries.
     */
    protected function startDatabaseMonitoring()
    {
        // Enable query logging
        DB::enableQueryLog();

        // Configure database for optimal performance during testing
        $this->databaseManager->optimizeForLoadTesting();

        // Monitor database queries
        DB::listen(function ($query) {
            $sql = $query->sql;
            $bindings = $query->bindings;
            $time = $query->time; // Time in milliseconds
            $connection = $query->connectionName;

            // Record the query in the metrics collector
            $this->metricsCollector->recordDatabaseQuery([
                'sql' => $sql,
                'bindings' => $bindings,
                'time' => $time,
                'connection' => $connection,
            ]);
        });
    }

    /**
     * Stop monitoring database queries.
     */
    protected function stopDatabaseMonitoring()
    {
        // Disable query logging to prevent memory issues
        DB::disableQueryLog();

        // Get database performance metrics
        $this->queryLog = $this->databaseManager->monitorPerformance();
    }

    /**
     * Run the actual load test.
     */
    protected function runLoadTest()
    {
        $concurrentUsers = $this->config['test']['concurrent_users'];
        $duration = $this->config['test']['duration'];
        $rampUp = $this->config['test']['ramp_up'];
        $timeout = $this->config['test']['timeout'];
        $baseUrl = $this->config['base_url'];

        // Use async client if available, otherwise use Guzzle
        if ($this->asyncClient && $this->eventLoop) {
            $this->runAsyncLoadTest($concurrentUsers, $duration, $rampUp, $timeout, $baseUrl);
        } else {
            $this->runSyncLoadTest($concurrentUsers, $duration, $rampUp, $timeout, $baseUrl);
        }
    }

    /**
     * Get CPU usage percentage.
     *
     * @return float
     */
    protected function getCpuUsage(): float
    {
        // Simple CPU usage calculation for Unix-like systems
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load[0] ?? 0.0;
        }

        // Fallback for Windows or when sys_getloadavg is not available
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows CPU usage - simplified approach
            return 0.0;
        }

        // Unix/Linux CPU usage
        try {
            $cpuInfo = shell_exec("grep 'cpu ' /proc/stat | awk '{usage=(\$2+\$4)*100/(\$2+\$3+\$4)} END {print usage}'");
            return (float) trim($cpuInfo);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Run load test using asynchronous ReactPHP client.
     */
    protected function runAsyncLoadTest($concurrentUsers, $duration, $rampUp, $timeout, $baseUrl)
    {
        $startTime = microtime(true);
        $endTime = $startTime + $duration;
        $requests = [];

        // Create a request queue
        $requestQueue = new \SplQueue();

        // Prepare the requests
        foreach ($this->routes as $route) {
            $uri = $this->replaceRouteParameters($route['uri']);

            for ($userIndex = 0; $userIndex < $concurrentUsers; $userIndex++) {
                // Get auth options for this user
                $options = $this->getAuthOptionsForUser($userIndex);

                // Add request to the queue
                $requestQueue->enqueue([
                    'method' => $route['method'],
                    'uri' => $uri,
                    'options' => $options,
                    'user_index' => $userIndex
                ]);
            }
        }

        // Shuffle the queue for more realistic testing
        $tempArray = iterator_to_array($requestQueue);
        shuffle($tempArray);
        $requestQueue = new \SplQueue();
        foreach ($tempArray as $item) {
            $requestQueue->enqueue($item);
        }

        // Calculate delay between requests for ramp-up
        $requestsPerSecond = $concurrentUsers * count($this->routes) / $rampUp;
        $delayBetweenRequests = $requestsPerSecond > 0 ? 1000000 / $requestsPerSecond : 0; // in microseconds

        // Active requests counter
        $activeRequests = 0;
        $maxConcurrent = $concurrentUsers;

        // Process the queue
        $timer = $this->eventLoop->addPeriodicTimer(0.001, function () use (
            &$requestQueue,
            &$activeRequests,
            $maxConcurrent,
            $baseUrl,
            $timeout,
            $endTime,
            &$delayBetweenRequests,
            &$startTime,
            $rampUp
        ) {
            // Stop if test duration reached
            if (microtime(true) >= $endTime) {
                $this->eventLoop->stop();
                return;
            }

            // Process as many requests as we can up to the concurrency limit
            while (!$requestQueue->isEmpty() && $activeRequests < $maxConcurrent) {
                $request = $requestQueue->dequeue();
                $activeRequests++;

                // Create request headers
                $headers = $request['options']['headers'] ?? [];

                // Make the request
                $this->asyncClient->request(
                    $request['method'],
                    $baseUrl . '/' . ltrim($request['uri'], '/'),
                    $headers
                )->then(
                    function (Response $response) use (&$activeRequests, $request) {
                        // Successful response
                        $responseTime = microtime(true) * 1000 - $request['start_time'];
                        $statusCode = $response->getStatusCode();

                        // Record the response
                        $this->metricsCollector->recordResponse(
                            $responseTime,
                            $statusCode,
                            strlen((string) $response->getBody()),
                            $request['uri']
                        );

                        $activeRequests--;
                    },
                    function (\Exception $exception) use (&$activeRequests, $request) {
                        // Failed request
                        $responseTime = microtime(true) * 1000 - $request['start_time'];

                        // Record the error
                        $this->metricsCollector->recordResponse(
                            $responseTime,
                            0,
                            0,
                            $request['uri'],
                            $exception->getMessage()
                        );

                        $activeRequests--;
                    }
                );

                // Record start time for this request
                $request['start_time'] = microtime(true) * 1000;

                // Implement ramp-up by gradually increasing request rate
                if (microtime(true) - $startTime < $rampUp) {
                    usleep((int) $delayBetweenRequests);

                    // Decrease delay as we progress through ramp-up
                    $elapsedRampUp = microtime(true) - $startTime;
                    $rampUpFactor = $elapsedRampUp / $rampUp;
                    $delayBetweenRequests = (1 - $rampUpFactor) * $delayBetweenRequests;
                }
            }

            // If queue is empty and no active requests, stop the event loop
            if ($requestQueue->isEmpty() && $activeRequests === 0) {
                $this->eventLoop->stop();
            }
        });

        // Run the event loop
        $this->eventLoop->run();
    }

    /**
     * Run load test using synchronous Guzzle client.
     */
    protected function runSyncLoadTest($concurrentUsers, $duration, $rampUp, $timeout, $baseUrl)
    {
        $client = new Client([
            'base_uri' => $baseUrl,
            'timeout' => $timeout,
            'verify' => false,
        ]);

        $requests = [];
        $results = [
            'requests' => [],
            'routes' => [],
            'status_codes' => [],
            'response_times' => [],
        ];

        // Prepare the requests
        foreach (range(0, $concurrentUsers - 1) as $userIndex) {
            foreach ($this->routes as $route) {
                $uri = $this->replaceRouteParameters($route['uri']);

                // Get auth options for this user
                $options = $this->getAuthOptionsForUser($userIndex);

                // Create request with appropriate auth
                $headers = $options['headers'] ?? [];
                $request = new Request($route['method'], $uri, $headers);

                // Add request to the queue with all options
                $requests[] = [
                    'request' => $request,
                    'options' => $options
                ];
            }
        }

        // Shuffle requests for more realistic testing
        shuffle($requests);

        // Calculate total number of requests and batches
        $totalRequests = count($requests);
        $batchSize = min(50, $concurrentUsers);
        $batches = ceil($totalRequests / $batchSize);

        // Calculate delay between batches for ramp-up
        $batchDelay = $rampUp > 0 ? ($rampUp * 1000000) / $batches : 0; // in microseconds

        $startTime = microtime(true);
        $endTime = $startTime + $duration;

        // Process requests in batches with rate limiting
        for ($batch = 0; $batch < $batches; $batch++) {
            // Check if we've exceeded the test duration
            if (microtime(true) > $endTime) {
                break;
            }

            $batchRequests = array_slice($requests, $batch * $batchSize, $batchSize);

            // Create a request pool
            $pool = new Pool($client, $batchRequests, [
                'concurrency' => $batchSize,
                'fulfilled' => function (Response $response, $index) use (&$results, $batchRequests, $batch, $batchSize) {
                    $requestTime = microtime(true);
                    $requestIndex = $batch * $batchSize + $index;
                    $statusCode = $response->getStatusCode();

                    // Store response data
                    $responseTime = (microtime(true) - $requestTime) * 1000; // in ms
                    $this->metricsCollector->recordResponse(
                        $responseTime,
                        $statusCode,
                        strlen((string) $response->getBody()),
                        $batchRequests[$index]['request']->getUri()->getPath()
                    );
                },
                'rejected' => function (RequestException $reason, $index) use (&$results, $batchRequests, $batch, $batchSize) {
                    $requestTime = microtime(true);
                    $requestIndex = $batch * $batchSize + $index;

                    // Get response if available
                    $response = $reason->getResponse();
                    $statusCode = $response ? $response->getStatusCode() : 0;

                    // Store error data
                    $responseTime = (microtime(true) - $requestTime) * 1000; // in ms
                    $this->metricsCollector->recordResponse(
                        $responseTime,
                        $statusCode,
                        0,
                        $batchRequests[$index]['request']->getUri()->getPath(),
                        $reason->getMessage()
                    );
                },
            ]);

            // Execute the pool of requests
            $pool->promise()->wait();

            // Implement ramp-up by introducing delay between batches
            if ($batch < $batches - 1 && microtime(true) - $startTime < $rampUp) {
                $currentRampUp = microtime(true) - $startTime;
                $rampUpFactor = $currentRampUp / $rampUp;
                $adjustedDelay = (1 - $rampUpFactor) * $batchDelay;

                if ($adjustedDelay > 0) {
                    usleep((int) $adjustedDelay);
                }
            }
        }

        // Record the results
        $this->results = $results;
    }

    /**
     * Get authentication options for a specific user.
     */
    protected function getAuthOptionsForUser($userIndex)
    {
        if (!$this->config['auth']['enabled']) {
            return [];
        }

        // Get auth options from the AuthManager
        return $this->authManager->getAuthForRequest();
    }

    /**
     * Replace route parameters with random values.
     */
    protected function replaceRouteParameters($uri)
    {
        return preg_replace_callback('/{([^}]+)}/', function ($matches) {
            $param = $matches[1];

            // Generate a random value based on the parameter name
            switch ($param) {
                case 'id':
                case strpos($param, '_id') !== false:
                    return rand(1, 100);
                case 'slug':
                    return 'test-slug-' . rand(1, 100);
                default:
                    return 'test-' . rand(1, 1000);
            }
        }, $uri);
    }

    /**
     * Generate reports from the test results.
     */
    protected function generateReports()
    {
        $outputDir = $this->config['reporting']['output_dir'];
        $outputPath = storage_path($outputDir);

        if (!File::exists($outputPath)) {
            File::makeDirectory($outputPath, 0755, true);
        }

        // Process the results
        $summary = $this->processResults();

        // Store results in database if enabled
        if ($this->config['monitoring']['store_results']) {
            $this->storeResultsInDatabase($summary);
        }

        // Generate HTML report
        if ($this->config['reporting']['html']) {
            $this->generateHtmlReport($summary, $outputPath);
        }

        // Generate JSON report
        if ($this->config['reporting']['json']) {
            $this->generateJsonReport($summary, $outputPath);
        }
    }

    /**
     * Process the raw results into meaningful statistics.
     */
    protected function processResults()
    {
        // Get metrics from the collector
        $metrics = $this->metricsCollector->getMetrics();

        // Add database query metrics
        $metrics['database'] = $this->queryLog;

        // Add resource usage
        $metrics['resources'] = $this->resourceUsage;

        // Add test duration
        $metrics['duration'] = $this->endTime - $this->startTime;

        // Update the results
        $this->results = $metrics;

        return $metrics;
    }

    /**
     * Store results in the database.
     */
    protected function storeResultsInDatabase($summary)
    {
        // Use the metrics collector to store results
        $this->metricsCollector->persistMetrics();
    }

    /**
     * Generate an HTML report.
     */
    protected function generateHtmlReport($summary, $outputPath)
    {
        $view = view('load-testing::dashboard', $summary)->render();
        File::put($outputPath . '/report.html', $view);
    }

    /**
     * Generate a JSON report.
     */
    protected function generateJsonReport($summary, $outputPath)
    {
        File::put($outputPath . '/report.json', json_encode($summary, JSON_PRETTY_PRINT));
    }

    /**
     * Validate Laravel compatibility and environment.
     *
     * @return array
     */
    public function validateLaravelCompatibility(): array
    {
        $issues = [];
        $warnings = [];

        // Check Laravel version
        $laravelVersion = app()->version();
        if (version_compare($laravelVersion, '9.0', '<')) {
            $issues[] = "Laravel version {$laravelVersion} is not supported. Minimum required: 9.0";
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
            }
        }

        // Check database connection
        try {
            DB::connection()->getPdo();
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
            }
        } catch (\Exception $e) {
            $issues[] = "Failed to access application routes: " . $e->getMessage();
        }

        // Check storage permissions
        $storageDir = storage_path('load-testing');
        if (!File::exists($storageDir)) {
            try {
                File::makeDirectory($storageDir, 0755, true);
            } catch (\Exception $e) {
                $issues[] = "Cannot create storage directory: " . $e->getMessage();
            }
        }

        if (!is_writable(storage_path())) {
            $issues[] = "Storage directory is not writable";
        }

        // Check memory limit
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = $this->convertToBytes($memoryLimit);
        $recommendedMemory = 512 * 1024 * 1024; // 512MB

        if ($memoryLimitBytes < $recommendedMemory) {
            $warnings[] = "Memory limit ({$memoryLimit}) is below recommended 512M for load testing";
        }

        // Check max execution time
        $maxExecutionTime = ini_get('max_execution_time');
        if ($maxExecutionTime > 0 && $maxExecutionTime < 300) {
            $warnings[] = "Max execution time ({$maxExecutionTime}s) might be too low for load testing";
        }

        // Check if authentication is properly configured
        if ($this->config['auth']['enabled']) {
            $authIssues = $this->validateAuthConfiguration();
            $issues = array_merge($issues, $authIssues);
        }

        return [
            'compatible' => empty($issues),
            'issues' => $issues,
            'warnings' => $warnings,
            'laravel_version' => $laravelVersion,
            'php_version' => PHP_VERSION,
        ];
    }

    /**
     * Validate authentication configuration.
     *
     * @return array
     */
    protected function validateAuthConfiguration(): array
    {
        $issues = [];
        $authMethod = $this->config['auth']['method'];

        // Check if users table exists
        try {
            if (!Schema::hasTable($this->config['auth']['table'])) {
                $issues[] = "Authentication table '{$this->config['auth']['table']}' does not exist";
            }
        } catch (\Exception $e) {
            $issues[] = "Cannot check authentication table: " . $e->getMessage();
        }

        // Validate specific auth method requirements
        switch ($authMethod) {
            case 'sanctum':
                if (!class_exists('\Laravel\Sanctum\Sanctum')) {
                    $issues[] = "Sanctum package not installed but auth method is set to 'sanctum'";
                }
                break;

            case 'passport':
                if (!class_exists('\Laravel\Passport\Passport')) {
                    $issues[] = "Passport package not installed but auth method is set to 'passport'";
                }
                break;

            case 'jwt':
                $jwtClasses = [
                    '\Tymon\JWTAuth\JWTAuth',
                    '\PHPOpenSourceSaver\JWTAuth\JWTAuth',
                ];
                $jwtFound = false;
                foreach ($jwtClasses as $class) {
                    if (class_exists($class)) {
                        $jwtFound = true;
                        break;
                    }
                }
                if (!$jwtFound) {
                    $issues[] = "JWT package not installed but auth method is set to 'jwt'";
                }
                break;
        }

        return $issues;
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
}
