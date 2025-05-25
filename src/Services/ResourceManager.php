<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Illuminate\Support\Facades\Log;
use Exception;

class ResourceManager
{
    /**
     * @var array Resources that need to be cleaned up
     */
    protected $resources = [];
    
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
     * Register a resource for cleanup
     *
     * @param string $name Resource name
     * @param mixed $resource Resource to clean up
     * @param callable|null $cleanupCallback Callback for custom cleanup
     * @return void
     */
    public function registerResource(string $name, $resource, ?callable $cleanupCallback = null): void
    {
        $this->resources[$name] = [
            'resource' => $resource,
            'cleanup' => $cleanupCallback,
        ];
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
     * @throws CircuitBreakerOpenException
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
                
                throw new CircuitBreakerOpenException("Circuit breaker for {$service} is open");
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
     * Clean up all registered resources
     *
     * @return void
     */
    public function cleanup(): void
    {
        foreach ($this->resources as $name => $resource) {
            try {
                if (isset($resource['cleanup']) && is_callable($resource['cleanup'])) {
                    // Use custom cleanup callback
                    call_user_func($resource['cleanup'], $resource['resource']);
                } elseif (is_resource($resource['resource'])) {
                    // Generic resource cleanup
                    $this->cleanupResource($resource['resource']);
                } elseif (method_exists($resource['resource'], 'close')) {
                    // Object with close method
                    $resource['resource']->close();
                } elseif (method_exists($resource['resource'], 'disconnect')) {
                    // Object with disconnect method
                    $resource['resource']->disconnect();
                }
                
                Log::debug("Cleaned up resource: {$name}");
            } catch (Exception $e) {
                Log::error("Error cleaning up resource {$name}: {$e->getMessage()}");
            }
        }
        
        // Clear resources array
        $this->resources = [];
    }
    
    /**
     * Clean up a generic PHP resource
     *
     * @param resource $resource
     * @return void
     */
    protected function cleanupResource($resource): void
    {
        $type = get_resource_type($resource);
        
        switch ($type) {
            case 'stream':
            case 'persistent stream':
            case 'stream-context':
                fclose($resource);
                break;
            case 'curl':
                curl_close($resource);
                break;
            case 'GdImage':
            case 'gd':
                imagedestroy($resource);
                break;
            case 'pgsql link':
            case 'pgsql link persistent':
            case 'pgsql result':
                pg_close($resource);
                break;
            case 'mysql link':
            case 'mysql link persistent':
            case 'mysql result':
                mysql_close($resource);
                break;
            case 'SQLite database':
            case 'SQLite3':
                sqlite_close($resource);
                break;
            case 'Memcached':
                $resource->quit();
                break;
            case 'Redis':
                $resource->close();
                break;
            default:
                Log::warning("Unknown resource type: {$type}, could not clean up properly");
                break;
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
    
    /**
     * Clean up resources when the object is destroyed
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}

/**
 * Exception thrown when a circuit breaker is open
 */
class CircuitBreakerOpenException extends Exception
{
} 