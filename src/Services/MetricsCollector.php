<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Carbon;

class MetricsCollector
{
    /**
     * @var array Configuration
     */
    protected $config;
    
    /**
     * @var array Collected metrics
     */
    protected $metrics = [
        'response_times' => [],
        'throughput' => 0,
        'error_rates' => [],
        'concurrent_users' => 0,
        'database_queries' => [],
        'memory_usage' => [],
        'cpu_usage' => [],
        'percentiles' => [],
        'status_codes' => [],
        'start_time' => null,
        'end_time' => null,
        'total_requests' => 0,
        'failed_requests' => 0,
    ];
    
    /**
     * @var string Test identifier
     */
    protected $testId;
    
    /**
     * @var bool Whether metrics storage is Redis or file-based
     */
    protected $useRedis = false;
    
    /**
     * Create a new MetricsCollector instance
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->testId = 'loadtest_' . time();
        $this->metrics['start_time'] = microtime(true);
        
        // Determine storage method
        $this->useRedis = $this->config['metrics']['storage'] === 'redis' && class_exists('Illuminate\Support\Facades\Redis');
        
        // Create output directory if needed
        if (!$this->useRedis) {
            $storagePath = $this->config['metrics']['storage_path'] ?? storage_path('load-testing');
            if (!File::exists($storagePath)) {
                File::makeDirectory($storagePath, 0755, true);
            }
        }
    }
    
    /**
     * Record a response from the load test
     *
     * @param float $responseTime Response time in milliseconds
     * @param int $statusCode HTTP status code
     * @param int $size Response size in bytes
     * @param string|null $url The requested URL
     * @param string|null $error Error message if any
     * @return void
     */
    public function recordResponse(float $responseTime, int $statusCode, int $size = 0, ?string $url = null, ?string $error = null): void
    {
        $timestamp = microtime(true);
        
        $dataPoint = [
            'timestamp' => $timestamp,
            'response_time' => $responseTime,
            'status_code' => $statusCode,
            'size' => $size,
            'url' => $url,
            'error' => $error,
        ];
        
        // Update metrics
        $this->metrics['total_requests']++;
        $this->metrics['response_times'][] = $responseTime;
        
        // Track status codes
        $statusCodeKey = (string) $statusCode;
        if (!isset($this->metrics['status_codes'][$statusCodeKey])) {
            $this->metrics['status_codes'][$statusCodeKey] = 0;
        }
        $this->metrics['status_codes'][$statusCodeKey]++;
        
        // Track errors (non-2xx responses)
        if ($statusCode < 200 || $statusCode >= 300) {
            $this->metrics['failed_requests']++;
        }
        
        // Store the data
        $this->storeRealtimeMetrics($dataPoint);
    }
    
    /**
     * Store real-time metrics data
     *
     * @param array $dataPoint
     * @return void
     */
    protected function storeRealtimeMetrics(array $dataPoint): void
    {
        if ($this->useRedis) {
            // Store in Redis for real-time access
            try {
                $redisKey = "loadtest:{$this->testId}:responses";
                Redis::connection($this->config['metrics']['redis_connection'] ?? 'default')
                    ->rpush($redisKey, json_encode($dataPoint));
            } catch (\Exception $e) {
                Log::error("Redis metrics storage failed: " . $e->getMessage());
                $this->useRedis = false; // Fall back to file storage
            }
        }
        
        // If not using Redis or Redis failed, append to file
        if (!$this->useRedis) {
            // Store in file for later analysis
            try {
                $storagePath = $this->config['metrics']['storage_path'] ?? storage_path('load-testing');
                $responseLogFile = $storagePath . "/responses_{$this->testId}.json";
                
                // Append to file
                File::append($responseLogFile, json_encode($dataPoint) . "\n");
            } catch (\Exception $e) {
                Log::error("File metrics storage failed: " . $e->getMessage());
            }
        }
        
        // Periodically persist the entire metrics state if needed
        if (count($this->metrics['response_times']) % 100 === 0) {
            $this->persistMetrics();
        }
    }
    
    /**
     * Update system resource usage metrics
     *
     * @param float $memory Memory usage in MB
     * @param float $cpu CPU usage percentage
     * @return void
     */
    public function updateResourceUsage(float $memory, float $cpu): void
    {
        $timestamp = microtime(true);
        
        $dataPoint = [
            'timestamp' => $timestamp,
            'memory' => $memory,
            'cpu' => $cpu,
        ];
        
        // Update metrics
        $this->metrics['memory_usage'][] = $memory;
        $this->metrics['cpu_usage'][] = $cpu;
        
        // Store the data
        if ($this->useRedis) {
            try {
                $redisKey = "loadtest:{$this->testId}:resources";
                Redis::connection($this->config['metrics']['redis_connection'] ?? 'default')
                    ->rpush($redisKey, json_encode($dataPoint));
            } catch (\Exception $e) {
                Log::error("Redis resource metrics storage failed: " . $e->getMessage());
            }
        } else {
            // Store in file
            try {
                $storagePath = $this->config['metrics']['storage_path'] ?? storage_path('load-testing');
                $resourceLogFile = $storagePath . "/resources_{$this->testId}.json";
                
                // Append to file
                File::append($resourceLogFile, json_encode($dataPoint) . "\n");
            } catch (\Exception $e) {
                Log::error("File resource metrics storage failed: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Update database query metrics
     *
     * @param array $queryData Query data including SQL, time, etc.
     * @return void
     */
    public function recordDatabaseQuery(array $queryData): void
    {
        $timestamp = microtime(true);
        
        $dataPoint = array_merge(['timestamp' => $timestamp], $queryData);
        
        // Track slow queries
        $slowThreshold = $this->config['monitoring']['database']['slow_threshold'] ?? 100;
        if ($queryData['time'] >= $slowThreshold) {
            $this->metrics['database_queries']['slow'][] = $dataPoint;
        }
        
        // Store the data
        if ($this->useRedis) {
            try {
                $redisKey = "loadtest:{$this->testId}:queries";
                Redis::connection($this->config['metrics']['redis_connection'] ?? 'default')
                    ->rpush($redisKey, json_encode($dataPoint));
            } catch (\Exception $e) {
                Log::error("Redis query metrics storage failed: " . $e->getMessage());
            }
        } else {
            // Store in file
            try {
                $storagePath = $this->config['metrics']['storage_path'] ?? storage_path('load-testing');
                $queryLogFile = $storagePath . "/queries_{$this->testId}.json";
                
                // Append to file
                File::append($queryLogFile, json_encode($dataPoint) . "\n");
            } catch (\Exception $e) {
                Log::error("File query metrics storage failed: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Calculate response time percentiles
     *
     * @return array Percentiles (50th, 90th, 95th, 99th)
     */
    public function calculatePercentiles(): array
    {
        $responseTimes = $this->metrics['response_times'];
        
        if (empty($responseTimes)) {
            return [
                '50th' => 0,
                '90th' => 0,
                '95th' => 0,
                '99th' => 0,
            ];
        }
        
        sort($responseTimes);
        $count = count($responseTimes);
        
        $percentiles = [
            '50th' => $this->getPercentile($responseTimes, 0.5),
            '90th' => $this->getPercentile($responseTimes, 0.9),
            '95th' => $this->getPercentile($responseTimes, 0.95),
            '99th' => $this->getPercentile($responseTimes, 0.99),
        ];
        
        $this->metrics['percentiles'] = $percentiles;
        
        return $percentiles;
    }
    
    /**
     * Get a specific percentile from an ordered array
     *
     * @param array $data Ordered array of values
     * @param float $percentile Percentile to calculate (0.0 - 1.0)
     * @return float
     */
    protected function getPercentile(array $data, float $percentile): float
    {
        $count = count($data);
        $index = ceil($percentile * $count) - 1;
        return $data[$index];
    }
    
    /**
     * Calculate current throughput (requests per second)
     *
     * @return float
     */
    public function calculateThroughput(): float
    {
        $currentTime = microtime(true);
        $elapsedTime = $currentTime - $this->metrics['start_time'];
        
        if ($elapsedTime <= 0) {
            return 0;
        }
        
        $throughput = $this->metrics['total_requests'] / $elapsedTime;
        $this->metrics['throughput'] = $throughput;
        
        return $throughput;
    }
    
    /**
     * Calculate error rate percentage
     *
     * @return float
     */
    public function calculateErrorRate(): float
    {
        if ($this->metrics['total_requests'] === 0) {
            return 0;
        }
        
        $errorRate = ($this->metrics['failed_requests'] / $this->metrics['total_requests']) * 100;
        $this->metrics['error_rate'] = $errorRate;
        
        return $errorRate;
    }
    
    /**
     * Get all collected metrics
     *
     * @return array
     */
    public function getMetrics(): array
    {
        // Calculate final metrics
        $this->metrics['end_time'] = microtime(true);
        $this->calculatePercentiles();
        $this->calculateThroughput();
        $this->calculateErrorRate();
        
        // Calculate averages for resource usage
        if (!empty($this->metrics['memory_usage'])) {
            $this->metrics['avg_memory'] = array_sum($this->metrics['memory_usage']) / count($this->metrics['memory_usage']);
            $this->metrics['max_memory'] = max($this->metrics['memory_usage']);
        }
        
        if (!empty($this->metrics['cpu_usage'])) {
            $this->metrics['avg_cpu'] = array_sum($this->metrics['cpu_usage']) / count($this->metrics['cpu_usage']);
            $this->metrics['max_cpu'] = max($this->metrics['cpu_usage']);
        }
        
        // Clean up large arrays to avoid memory issues
        $this->metrics['response_times'] = $this->summarizeResponseTimes($this->metrics['response_times']);
        $this->metrics['memory_usage'] = $this->summarizeResourceData($this->metrics['memory_usage']);
        $this->metrics['cpu_usage'] = $this->summarizeResourceData($this->metrics['cpu_usage']);
        
        return $this->metrics;
    }
    
    /**
     * Get a summary of real-time metrics for dashboard updates
     *
     * @return array
     */
    public function getRealtimeMetrics(): array
    {
        return [
            'total_requests' => $this->metrics['total_requests'],
            'failed_requests' => $this->metrics['failed_requests'],
            'throughput' => $this->calculateThroughput(),
            'error_rate' => $this->calculateErrorRate(),
            'percentiles' => $this->calculatePercentiles(),
            'status_codes' => $this->metrics['status_codes'],
            'memory' => end($this->metrics['memory_usage']) ?: 0,
            'cpu' => end($this->metrics['cpu_usage']) ?: 0,
            'duration' => microtime(true) - $this->metrics['start_time'],
        ];
    }
    
    /**
     * Summarize response times to reduce memory usage
     *
     * @param array $responseTimes
     * @return array
     */
    protected function summarizeResponseTimes(array $responseTimes): array
    {
        // If there are too many data points, summarize them
        if (count($responseTimes) > 1000) {
            // Store key percentiles
            $summary = $this->calculatePercentiles();
            
            // Store min, max, avg
            $summary['min'] = min($responseTimes);
            $summary['max'] = max($responseTimes);
            $summary['avg'] = array_sum($responseTimes) / count($responseTimes);
            $summary['count'] = count($responseTimes);
            
            return $summary;
        }
        
        return $responseTimes;
    }
    
    /**
     * Summarize resource usage data to reduce memory usage
     *
     * @param array $data
     * @return array
     */
    protected function summarizeResourceData(array $data): array
    {
        // If there are too many data points, summarize them
        if (count($data) > 1000) {
            // Sample the data at regular intervals
            $sampled = [];
            $interval = intval(count($data) / 100); // Take ~100 samples
            
            for ($i = 0; $i < count($data); $i += $interval) {
                $sampled[] = $data[$i];
            }
            
            return $sampled;
        }
        
        return $data;
    }
    
    /**
     * Persist all metrics to storage (database or file)
     *
     * @return void
     */
    public function persistMetrics(): void
    {
        $metrics = $this->getMetrics();
        
        try {
            $storagePath = $this->config['metrics']['storage_path'] ?? storage_path('load-testing');
            $summaryFile = $storagePath . "/summary_{$this->testId}.json";
            
            // Save metrics summary
            File::put($summaryFile, json_encode($metrics, JSON_PRETTY_PRINT));
            
            // Store in database if configured
            if ($this->config['monitoring']['store_results'] ?? false) {
                $this->storeResultsInDatabase($metrics);
            }
        } catch (\Exception $e) {
            Log::error("Failed to persist metrics: " . $e->getMessage());
        }
    }
    
    /**
     * Store results in the database
     *
     * @param array $metrics
     * @return void
     */
    protected function storeResultsInDatabase(array $metrics): void
    {
        try {
            $tableName = $this->config['monitoring']['results_table'] ?? 'load_test_results';
            
            // Check if table exists and create if it doesn't
            if (!\Schema::hasTable($tableName)) {
                \Schema::create($tableName, function ($table) {
                    $table->id();
                    $table->timestamp('test_date');
                    $table->string('test_id');
                    $table->integer('concurrent_users');
                    $table->integer('total_requests');
                    $table->float('avg_response_time');
                    $table->float('min_response_time');
                    $table->float('max_response_time');
                    $table->integer('successful_requests');
                    $table->integer('error_requests');
                    $table->float('error_rate');
                    $table->float('throughput');
                    $table->float('peak_memory');
                    $table->float('peak_cpu');
                    $table->float('avg_memory');
                    $table->float('avg_cpu');
                    $table->float('duration');
                    $table->json('percentiles')->nullable();
                    $table->json('status_codes')->nullable();
                    $table->timestamps();
                });
            }
            
            // Insert the record
            \DB::table($tableName)->insert([
                'test_date' => Carbon::now(),
                'test_id' => $this->testId,
                'concurrent_users' => $this->config['test']['concurrent_users'] ?? 0,
                'total_requests' => $metrics['total_requests'],
                'avg_response_time' => $metrics['percentiles']['50th'] ?? 0,
                'min_response_time' => $metrics['min'] ?? min($metrics['response_times'] ?: [0]),
                'max_response_time' => $metrics['max'] ?? max($metrics['response_times'] ?: [0]),
                'successful_requests' => $metrics['total_requests'] - $metrics['failed_requests'],
                'error_requests' => $metrics['failed_requests'],
                'error_rate' => $metrics['error_rate'] ?? 0,
                'throughput' => $metrics['throughput'] ?? 0,
                'peak_memory' => $metrics['max_memory'] ?? 0,
                'peak_cpu' => $metrics['max_cpu'] ?? 0,
                'avg_memory' => $metrics['avg_memory'] ?? 0,
                'avg_cpu' => $metrics['avg_cpu'] ?? 0,
                'duration' => $metrics['end_time'] - $metrics['start_time'],
                'percentiles' => json_encode($metrics['percentiles'] ?? []),
                'status_codes' => json_encode($metrics['status_codes'] ?? []),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to store results in database: " . $e->getMessage());
        }
    }
    
    /**
     * Get time series data for specific metrics
     *
     * @param string $metric Metric name (response_times, memory, cpu)
     * @param int $duration Duration in seconds (0 for all data)
     * @return array
     */
    public function getTimeSeriesData(string $metric, int $duration = 0): array
    {
        // Implementation depends on the storage method
        $startTime = $duration > 0 ? microtime(true) - $duration : 0;
        
        if ($this->useRedis) {
            return $this->getTimeSeriesFromRedis($metric, $startTime);
        } else {
            return $this->getTimeSeriesFromFiles($metric, $startTime);
        }
    }
    
    /**
     * Get time series data from Redis
     *
     * @param string $metric
     * @param float $startTime
     * @return array
     */
    protected function getTimeSeriesFromRedis(string $metric, float $startTime): array
    {
        try {
            $redisKey = "loadtest:{$this->testId}:";
            
            switch ($metric) {
                case 'response_times':
                    $redisKey .= 'responses';
                    break;
                case 'memory':
                case 'cpu':
                    $redisKey .= 'resources';
                    break;
                case 'queries':
                    $redisKey .= 'queries';
                    break;
                default:
                    return [];
            }
            
            $redis = Redis::connection($this->config['metrics']['redis_connection'] ?? 'default');
            $data = $redis->lrange($redisKey, 0, -1);
            
            $result = [];
            foreach ($data as $item) {
                $item = json_decode($item, true);
                
                if ($item['timestamp'] >= $startTime) {
                    $result[] = $item;
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::error("Failed to get time series data from Redis: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get time series data from files
     *
     * @param string $metric
     * @param float $startTime
     * @return array
     */
    protected function getTimeSeriesFromFiles(string $metric, float $startTime): array
    {
        try {
            $storagePath = $this->config['metrics']['storage_path'] ?? storage_path('load-testing');
            $fileName = '';
            
            switch ($metric) {
                case 'response_times':
                    $fileName = "responses_{$this->testId}.json";
                    break;
                case 'memory':
                case 'cpu':
                    $fileName = "resources_{$this->testId}.json";
                    break;
                case 'queries':
                    $fileName = "queries_{$this->testId}.json";
                    break;
                default:
                    return [];
            }
            
            $filePath = $storagePath . '/' . $fileName;
            
            if (!File::exists($filePath)) {
                return [];
            }
            
            $result = [];
            $handle = fopen($filePath, 'r');
            
            while (($line = fgets($handle)) !== false) {
                $item = json_decode($line, true);
                
                if ($item && $item['timestamp'] >= $startTime) {
                    $result[] = $item;
                }
            }
            
            fclose($handle);
            return $result;
        } catch (\Exception $e) {
            Log::error("Failed to get time series data from files: " . $e->getMessage());
            return [];
        }
    }
} 