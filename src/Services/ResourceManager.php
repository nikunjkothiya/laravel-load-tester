<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Illuminate\Support\Facades\Log;
use Exception;

class ResourceManager
{
    /**
     * @var array Registered resources for cleanup
     */
    public $resources = [];

    /**
     * @var array Process IDs to track
     */
    protected $processes = [];

    /**
     * @var array File handles to close
     */
    protected $fileHandles = [];

    /**
     * @var array Temporary files to delete
     */
    protected $tempFiles = [];

    /**
     * @var array Circuit breakers
     */
    protected $circuitBreakers = [];

    /**
     * @var bool Whether the cleanup has been registered
     */
    protected $cleanupRegistered = false;

    /**
     * Create a new ResourceManager instance
     */
    public function __construct()
    {
        $this->registerShutdownHandler();
    }

    /**
     * Register a resource for cleanup.
     *
     * @param string $name
     * @param mixed $resource
     * @param string $type
     * @return void
     */
    public function registerResource(string $name, $resource, string $type = 'general'): void
    {
        $this->resources[$name] = [
            'resource' => $resource,
            'type' => $type,
            'created_at' => microtime(true)
        ];

        // Track specific resource types
        switch ($type) {
            case 'process':
                $this->processes[] = $resource;
                break;
            case 'file_handle':
                $this->fileHandles[] = $resource;
                break;
            case 'temp_file':
                $this->tempFiles[] = $resource;
                break;
        }
    }

    /**
     * Unregister a resource.
     *
     * @param string $name
     * @return void
     */
    public function unregisterResource(string $name): void
    {
        if (isset($this->resources[$name])) {
            $resource = $this->resources[$name];

            // Remove from specific tracking arrays
            switch ($resource['type']) {
                case 'process':
                    $this->processes = array_filter($this->processes, function ($pid) use ($resource) {
                        return $pid !== $resource['resource'];
                    });
                    break;
                case 'file_handle':
                    $this->fileHandles = array_filter($this->fileHandles, function ($handle) use ($resource) {
                        return $handle !== $resource['resource'];
                    });
                    break;
                case 'temp_file':
                    $this->tempFiles = array_filter($this->tempFiles, function ($file) use ($resource) {
                        return $file !== $resource['resource'];
                    });
                    break;
            }

            unset($this->resources[$name]);
        }
    }

    /**
     * Clean up all registered resources.
     *
     * @return void
     */
    public function cleanup(): void
    {
        Log::info('Starting resource cleanup...');

        // Clean up processes
        $this->cleanupProcesses();

        // Clean up file handles
        $this->cleanupFileHandles();

        // Clean up temporary files
        $this->cleanupTempFiles();

        // Clean up other resources
        $this->cleanupOtherResources();

        // Clear all resource tracking
        $this->resources = [];
        $this->processes = [];
        $this->fileHandles = [];
        $this->tempFiles = [];

        Log::info('Resource cleanup completed');
    }

    /**
     * Clean up running processes.
     *
     * @return void
     */
    protected function cleanupProcesses(): void
    {
        foreach ($this->processes as $pid) {
            try {
                if (function_exists('posix_kill') && posix_kill($pid, 0)) {
                    // Process is running, terminate it
                    posix_kill($pid, SIGTERM);

                    // Wait a bit for graceful shutdown
                    usleep(100000); // 100ms

                    // Force kill if still running
                    if (posix_kill($pid, 0)) {
                        posix_kill($pid, SIGKILL);
                    }

                    Log::info("Terminated process: {$pid}");
                }
            } catch (\Exception $e) {
                Log::warning("Failed to terminate process {$pid}: " . $e->getMessage());
            }
        }
    }

    /**
     * Clean up file handles.
     *
     * @return void
     */
    protected function cleanupFileHandles(): void
    {
        foreach ($this->fileHandles as $handle) {
            try {
                if (is_resource($handle)) {
                    fclose($handle);
                    Log::debug('Closed file handle');
                }
            } catch (\Exception $e) {
                Log::warning("Failed to close file handle: " . $e->getMessage());
            }
        }
    }

    /**
     * Clean up temporary files.
     *
     * @return void
     */
    protected function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            try {
                if (file_exists($file)) {
                    unlink($file);
                    Log::debug("Deleted temporary file: {$file}");
                }
            } catch (\Exception $e) {
                Log::warning("Failed to delete temporary file {$file}: " . $e->getMessage());
            }
        }
    }

    /**
     * Clean up other registered resources.
     *
     * @return void
     */
    protected function cleanupOtherResources(): void
    {
        foreach ($this->resources as $name => $resource) {
            if (in_array($resource['type'], ['process', 'file_handle', 'temp_file'])) {
                continue; // Already handled above
            }

            try {
                $resourceObj = $resource['resource'];

                // Handle different resource types
                if (is_object($resourceObj)) {
                    // Check for common cleanup methods
                    if (method_exists($resourceObj, 'close')) {
                        $resourceObj->close();
                    } elseif (method_exists($resourceObj, 'disconnect')) {
                        $resourceObj->disconnect();
                    } elseif (method_exists($resourceObj, 'stop')) {
                        $resourceObj->stop();
                    } elseif (method_exists($resourceObj, '__destruct')) {
                        $resourceObj->__destruct();
                    }
                }

                Log::debug("Cleaned up resource: {$name}");
            } catch (\Exception $e) {
                Log::warning("Failed to cleanup resource {$name}: " . $e->getMessage());
            }
        }
    }

    /**
     * Get current resource usage statistics.
     *
     * @return array
     */
    public function getResourceStats(): array
    {
        return [
            'total_resources' => count($this->resources),
            'processes' => count($this->processes),
            'file_handles' => count($this->fileHandles),
            'temp_files' => count($this->tempFiles),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Force cleanup on destruction.
     */
    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * Get CPU usage percentage.
     *
     * @return float
     */
    public function getCpuUsage(): float
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->getCpuUsageWindows();
        } elseif (PHP_OS_FAMILY === 'Linux') {
            return $this->getCpuUsageLinux();
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            return $this->getCpuUsageMacOS();
        }

        return 0.0;
    }

    /**
     * Get CPU usage on Windows.
     *
     * @return float
     */
    protected function getCpuUsageWindows(): float
    {
        try {
            $wmi = new \COM('WbemScripting.SWbemLocator');
            $server = $wmi->ConnectServer('.', 'root\\CIMV2');
            $result = $server->ExecQuery('SELECT LoadPercentage FROM Win32_Processor');

            $cpuUsage = 0;
            $count = 0;
            foreach ($result as $cpu) {
                $cpuUsage += $cpu->LoadPercentage;
                $count++;
            }

            return $count > 0 ? $cpuUsage / $count : 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Get CPU usage on Linux.
     *
     * @return float
     */
    protected function getCpuUsageLinux(): float
    {
        try {
            $load = sys_getloadavg();
            return $load ? $load[0] * 100 : 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Get CPU usage on macOS.
     *
     * @return float
     */
    protected function getCpuUsageMacOS(): float
    {
        try {
            $load = sys_getloadavg();
            return $load ? $load[0] * 100 : 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Monitor resource usage and log warnings if thresholds are exceeded.
     *
     * @param array $thresholds
     * @return void
     */
    public function monitorResources(array $thresholds = []): void
    {
        $defaults = [
            'memory_mb' => 512,
            'cpu_percent' => 80,
            'processes' => 50,
            'file_handles' => 100,
        ];

        $thresholds = array_merge($defaults, $thresholds);

        $stats = $this->getResourceStats();
        $memoryMB = $stats['memory_usage'] / 1024 / 1024;
        $cpuUsage = $this->getCpuUsage();

        // Check memory usage
        if ($memoryMB > $thresholds['memory_mb']) {
            Log::warning("High memory usage: {$memoryMB}MB (threshold: {$thresholds['memory_mb']}MB)");
        }

        // Check CPU usage
        if ($cpuUsage > $thresholds['cpu_percent']) {
            Log::warning("High CPU usage: {$cpuUsage}% (threshold: {$thresholds['cpu_percent']}%)");
        }

        // Check process count
        if ($stats['processes'] > $thresholds['processes']) {
            Log::warning("High process count: {$stats['processes']} (threshold: {$thresholds['processes']})");
        }

        // Check file handle count
        if ($stats['file_handles'] > $thresholds['file_handles']) {
            Log::warning("High file handle count: {$stats['file_handles']} (threshold: {$thresholds['file_handles']})");
        }
    }

    /**
     * Create a circuit breaker for a service
     *
     * @param string $service Service name
     * @param int $failureThreshold Number of failures before opening the circuit
     * @param int $timeout Seconds to wait before trying again
     * @return void
     */
    public function createCircuitBreaker(string $service, int $failureThreshold = 5, int $timeout = 60): void
    {
        $this->circuitBreakers[$service] = [
            'failures' => 0,
            'state' => 'closed', // closed, open, half-open
            'failure_threshold' => $failureThreshold,
            'timeout' => $timeout,
            'last_failure_time' => 0,
        ];
    }

    /**
     * Execute an operation with circuit breaker pattern
     *
     * @param string $service Service name
     * @param callable $operation Operation to execute
     * @param mixed $defaultValue Default value to return if circuit is open
     * @return mixed
     * @throws ResourceManagerCircuitBreakerOpenException
     */
    public function executeWithCircuitBreaker(string $service, callable $operation, $defaultValue = null)
    {
        // Create circuit breaker if it doesn't exist
        if (!isset($this->circuitBreakers[$service])) {
            $this->createCircuitBreaker($service);
        }

        $circuitBreaker = &$this->circuitBreakers[$service];

        // Check if circuit is open
        if ($circuitBreaker['state'] === 'open') {
            // Check if timeout has passed
            if (time() - $circuitBreaker['last_failure_time'] > $circuitBreaker['timeout']) {
                // Move to half-open state
                $circuitBreaker['state'] = 'half-open';
                Log::info("Circuit breaker for {$service} moved to half-open state");
            } else {
                // Circuit is still open
                Log::warning("Circuit breaker for {$service} is open, skipping operation");

                if ($defaultValue !== null) {
                    return $defaultValue;
                }

                throw new ResourceManagerCircuitBreakerOpenException("Circuit breaker for {$service} is open");
            }
        }

        try {
            // Execute the operation
            $result = $operation();

            // If we were in half-open state and succeeded, close the circuit
            if ($circuitBreaker['state'] === 'half-open') {
                $circuitBreaker['state'] = 'closed';
                $circuitBreaker['failures'] = 0;
                Log::info("Circuit breaker for {$service} closed after successful recovery");
            }

            return $result;
        } catch (Exception $e) {
            // Increment failure count
            $circuitBreaker['failures']++;
            $circuitBreaker['last_failure_time'] = time();

            // Check if we need to open the circuit
            if ($circuitBreaker['failures'] >= $circuitBreaker['failure_threshold']) {
                $circuitBreaker['state'] = 'open';
                Log::warning("Circuit breaker for {$service} opened after {$circuitBreaker['failures']} failures");
            }

            // Re-throw the exception
            throw $e;
        }
    }

    /**
     * Execute an operation with retry mechanism
     *
     * @param callable $operation Operation to execute
     * @param int $maxRetries Maximum number of retries
     * @param int $initialDelay Initial delay in milliseconds
     * @param callable|null $shouldRetry Callback to determine if retry should be attempted
     * @return mixed
     * @throws Exception
     */
    public function executeWithRetry(callable $operation, int $maxRetries = 3, int $initialDelay = 500, ?callable $shouldRetry = null)
    {
        $attempt = 0;
        $delay = $initialDelay;

        while (true) {
            try {
                return $operation();
            } catch (Exception $e) {
                $attempt++;

                // Check if we should retry
                if ($attempt >= $maxRetries) {
                    throw $e;
                }

                if ($shouldRetry !== null && !$shouldRetry($e, $attempt)) {
                    throw $e;
                }

                // Exponential backoff
                Log::info("Retrying operation after exception: {$e->getMessage()} (attempt {$attempt} of {$maxRetries})");
                usleep($delay * 1000); // Convert to microseconds
                $delay *= 2; // Exponential backoff
            }
        }
    }

    /**
     * Register a shutdown handler to clean up resources
     *
     * @return void
     */
    public function registerShutdownHandler(): void
    {
        if (!$this->cleanupRegistered) {
            register_shutdown_function([$this, 'cleanup']);
            $this->cleanupRegistered = true;
        }
    }
}

/**
 * Exception thrown when a circuit breaker is open
 */
class ResourceManagerCircuitBreakerOpenException extends \Exception {}
