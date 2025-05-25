<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class RetryHandler
{
    /**
     * Default max retries
     *
     * @var int
     */
    protected $defaultMaxRetries = 3;
    
    /**
     * Default initial delay in seconds
     *
     * @var int
     */
    protected $defaultInitialDelay = 1;
    
    /**
     * Default maximum delay in seconds
     *
     * @var int
     */
    protected $defaultMaxDelay = 30;
    
    /**
     * Default jitter factor (0-1)
     *
     * @var float
     */
    protected $defaultJitter = 0.1;
    
    /**
     * Default backoff factor
     *
     * @var int
     */
    protected $defaultBackoffFactor = 2;
    
    /**
     * Retry configuration
     *
     * @var array
     */
    protected $config;
    
    /**
     * Create a new RetryHandler instance.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }
    
    /**
     * Execute a callable with retry logic.
     *
     * @param callable $operation The operation to execute
     * @param int|null $maxRetries Maximum number of retries
     * @param array $retryableExceptions Exceptions that should trigger a retry
     * @param callable|null $shouldRetry Custom callback to determine if retry should happen
     * @return mixed The result of the operation
     * @throws Exception If all retries fail
     */
    public function executeWithRetry(
        callable $operation, 
        ?int $maxRetries = null, 
        array $retryableExceptions = [], 
        ?callable $shouldRetry = null
    ): mixed {
        $maxRetries = $maxRetries ?? $this->config['max_retries'] ?? $this->defaultMaxRetries;
        $initialDelay = $this->config['initial_delay'] ?? $this->defaultInitialDelay;
        $maxDelay = $this->config['max_delay'] ?? $this->defaultMaxDelay;
        $backoffFactor = $this->config['backoff_factor'] ?? $this->defaultBackoffFactor;
        $jitter = $this->config['jitter'] ?? $this->defaultJitter;
        
        $attempt = 0;
        $lastException = null;
        
        while ($attempt <= $maxRetries) {
            try {
                return $operation();
            } catch (Exception $exception) {
                $lastException = $exception;
                $attempt++;
                
                // If we've reached the maximum number of retries, throw the exception
                if ($attempt > $maxRetries) {
                    throw $exception;
                }
                
                // Determine if we should retry based on the exception type or custom callback
                $shouldRetryForException = empty($retryableExceptions) || in_array(get_class($exception), $retryableExceptions);
                $shouldRetryCustom = $shouldRetry ? call_user_func($shouldRetry, $exception, $attempt) : true;
                
                if (!$shouldRetryForException || !$shouldRetryCustom) {
                    throw $exception;
                }
                
                // Calculate delay with exponential backoff and jitter
                $delay = min($maxDelay, $initialDelay * pow($backoffFactor, $attempt - 1));
                
                // Add jitter to prevent thundering herd
                if ($jitter > 0) {
                    $delay += $delay * $jitter * (mt_rand(0, 1000) / 1000);
                }
                
                Log::debug("Retry attempt {$attempt}/{$maxRetries} after {$delay}s", [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ]);
                
                // Sleep for the calculated delay
                usleep($delay * 1000000);
            }
        }
        
        // This should never be reached, but just in case
        throw $lastException ?? new Exception('All retries failed');
    }
    
    /**
     * Execute with custom retry policy.
     *
     * @param callable $operation
     * @param array $retryPolicy
     * @return mixed
     */
    public function executeWithRetryPolicy(callable $operation, array $retryPolicy): mixed
    {
        return $this->executeWithRetry(
            $operation,
            $retryPolicy['max_retries'] ?? null,
            $retryPolicy['retryable_exceptions'] ?? [],
            $retryPolicy['should_retry'] ?? null
        );
    }
    
    /**
     * Set the default maximum number of retries.
     *
     * @param int $maxRetries
     * @return self
     */
    public function setDefaultMaxRetries(int $maxRetries): self
    {
        $this->defaultMaxRetries = $maxRetries;
        return $this;
    }
    
    /**
     * Set the default initial delay.
     *
     * @param int $initialDelay
     * @return self
     */
    public function setDefaultInitialDelay(int $initialDelay): self
    {
        $this->defaultInitialDelay = $initialDelay;
        return $this;
    }
    
    /**
     * Set the default maximum delay.
     *
     * @param int $maxDelay
     * @return self
     */
    public function setDefaultMaxDelay(int $maxDelay): self
    {
        $this->defaultMaxDelay = $maxDelay;
        return $this;
    }
} 