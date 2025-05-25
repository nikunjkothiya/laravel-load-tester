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
     * @var ReactBrowser|null Async HTTP client
     */
    protected $asyncClient;
    
    /**
     * @var \React\EventLoop\LoopInterface|null Event loop for async operations
     */
    protected $eventLoop;
    
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
            $this->eventLoop = ReactFactory::create();
            $this->asyncClient = new ReactBrowser($this->eventLoop);
            
            // Register for cleanup
            $this->resourceManager->registerResource('event_loop', $this->eventLoop);
        }
    }
    
    /**
     * Prepare the environment for load testing.
     */
    public function prepare()
    {
        // Get all application routes
        $this->discoverRoutes();
        
        // Prepare database if needed
        $this->databaseManager->prepare();
        
        // Create test users if authentication is enabled
        if ($this->config['auth']['enabled']) {
            $this->createTestUsers();
        }
        
        return $this;
    }
    
    /**
     * Run the load test.
     */
    public function run()
    {
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
    }
    
    /**
     * Discover application routes.
     */
    protected function discoverRoutes()
    {
        $routes = Route::getRoutes();
        $testableRoutes = [];
        
        foreach ($routes as $route) {
            $uri = $route->uri();
            $methods = $route->methods();
            
            // Skip routes that should be excluded
            if ($this->shouldExcludeRoute($uri)) {
                continue;
            }
            
            // Skip non-GET routes for basic testing
            if (!in_array('GET', $methods)) {
                continue;
            }
            
            $testableRoutes[] = [
                'uri' => $uri,
                'method' => 'GET',
                'name' => $route->getName()
            ];
        }
        
        $this->routes = $testableRoutes;
        return $this;
    }
    
    /**
     * Check if a route should be excluded from testing.
     */
    protected function shouldExcludeRoute($uri)
    {
        $excludePatterns = $this->config['routes']['exclude'] ?? [];
        
        foreach ($excludePatterns as $pattern) {
            if (fnmatch($pattern, $uri)) {
                return true;
            }
        }
        
        // Include only specific routes if provided
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
                return true;
            }
        }
        
        return false;
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
            &$startTime
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
}