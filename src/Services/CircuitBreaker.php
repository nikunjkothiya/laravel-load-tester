<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CircuitBreaker
{
    /**
     * Circuit states
     */
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';
    
    /**
     * Service name (used for identifying the circuit)
     *
     * @var string
     */
    protected $serviceName;
    
    /**
     * Number of failures before opening the circuit
     *
     * @var int
     */
    protected $failureThreshold;
    
    /**
     * Time window in seconds to track failures
     *
     * @var int
     */
    protected $failureWindow;
    
    /**
     * Time in seconds to wait before transitioning from open to half-open
     *
     * @var int
     */
    protected $resetTimeout;
    
    /**
     * Number of successful attempts needed in half-open state to close the circuit
     *
     * @var int
     */
    protected $successThreshold;
    
    /**
     * Cache prefix for circuit breaker state
     *
     * @var string
     */
    protected $cachePrefix = 'circuit_breaker_';
    
    /**
     * Create a new CircuitBreaker instance.
     *
     * @param string $serviceName
     * @param array $config
     */
    public function __construct(string $serviceName, array $config = [])
    {
        $this->serviceName = $serviceName;
        $this->failureThreshold = $config['failure_threshold'] ?? 5;
        $this->failureWindow = $config['failure_window'] ?? 60; // 1 minute
        $this->resetTimeout = $config['reset_timeout'] ?? 60; // 1 minute
        $this->successThreshold = $config['success_threshold'] ?? 2;
    }
    
    /**
     * Execute an operation with circuit breaker protection.
     *
     * @param callable $operation
     * @param callable|null $fallback
     * @return mixed
     * @throws Exception
     */
    public function execute(callable $operation, callable $fallback = null)
    {
        $state = $this->getState();
        
        if ($state === self::STATE_OPEN) {
            // Circuit is open, check if reset timeout has passed
            if ($this->shouldAttemptReset()) {
                $this->setState(self::STATE_HALF_OPEN);
                return $this->executeHalfOpen($operation, $fallback);
            }
            
            // Circuit is open and reset timeout hasn't passed
            return $this->handleOpenCircuit($fallback);
        }
        
        if ($state === self::STATE_HALF_OPEN) {
            return $this->executeHalfOpen($operation, $fallback);
        }
        
        // Circuit is closed, proceed normally
        try {
            $result = $operation();
            $this->recordSuccess();
            return $result;
        } catch (Exception $exception) {
            $this->recordFailure();
            
            // Check if we need to open the circuit
            if ($this->getFailureCount() >= $this->failureThreshold) {
                $this->tripCircuit();
            }
            
            // If there's a fallback, use it
            if ($fallback) {
                return $fallback($exception);
            }
            
            // Otherwise, re-throw the exception
            throw $exception;
        }
    }
    
    /**
     * Execute operation in half-open state.
     *
     * @param callable $operation
     * @param callable|null $fallback
     * @return mixed
     */
    protected function executeHalfOpen(callable $operation, callable $fallback = null)
    {
        try {
            $result = $operation();
            $this->recordHalfOpenSuccess();
            return $result;
        } catch (Exception $exception) {
            $this->tripCircuit();
            
            // If there's a fallback, use it
            if ($fallback) {
                return $fallback($exception);
            }
            
            // Otherwise, re-throw the exception
            throw $exception;
        }
    }
    
    /**
     * Handle an open circuit.
     *
     * @param callable|null $fallback
     * @return mixed
     * @throws CircuitBreakerOpenException
     */
    protected function handleOpenCircuit(callable $fallback = null)
    {
        $exception = new CircuitBreakerOpenException("Circuit for {$this->serviceName} is open");
        
        if ($fallback) {
            return $fallback($exception);
        }
        
        throw $exception;
    }
    
    /**
     * Get the current state of the circuit.
     *
     * @return string
     */
    public function getState(): string
    {
        return Cache::get($this->getStateKey(), self::STATE_CLOSED);
    }
    
    /**
     * Set the state of the circuit.
     *
     * @param string $state
     * @return void
     */
    protected function setState(string $state): void
    {
        Cache::put($this->getStateKey(), $state);
    }
    
    /**
     * Trip the circuit (change state to open).
     *
     * @return void
     */
    protected function tripCircuit(): void
    {
        $this->setState(self::STATE_OPEN);
        $this->setLastTripped(time());
        $this->resetHalfOpenSuccessCount();
        Log::warning("Circuit for {$this->serviceName} tripped open");
    }
    
    /**
     * Record a successful operation.
     *
     * @return void
     */
    protected function recordSuccess(): void
    {
        // In closed state, we don't need to track successes
    }
    
    /**
     * Record a successful operation in half-open state.
     *
     * @return void
     */
    protected function recordHalfOpenSuccess(): void
    {
        $currentCount = $this->getHalfOpenSuccessCount();
        $newCount = $currentCount + 1;
        
        Cache::put($this->getHalfOpenSuccessKey(), $newCount);
        
        // If we've reached the success threshold, close the circuit
        if ($newCount >= $this->successThreshold) {
            $this->setState(self::STATE_CLOSED);
            $this->resetFailureCount();
            $this->resetHalfOpenSuccessCount();
            Log::info("Circuit for {$this->serviceName} closed after successful half-open operations");
        }
    }
    
    /**
     * Record a failed operation.
     *
     * @return void
     */
    protected function recordFailure(): void
    {
        $failures = $this->getFailureCount();
        Cache::put($this->getFailureKey(), $failures + 1, $this->failureWindow);
    }
    
    /**
     * Get the current failure count.
     *
     * @return int
     */
    protected function getFailureCount(): int
    {
        return Cache::get($this->getFailureKey(), 0);
    }
    
    /**
     * Reset the failure count.
     *
     * @return void
     */
    protected function resetFailureCount(): void
    {
        Cache::forget($this->getFailureKey());
    }
    
    /**
     * Get the success count in half-open state.
     *
     * @return int
     */
    protected function getHalfOpenSuccessCount(): int
    {
        return Cache::get($this->getHalfOpenSuccessKey(), 0);
    }
    
    /**
     * Reset the half-open success count.
     *
     * @return void
     */
    protected function resetHalfOpenSuccessCount(): void
    {
        Cache::forget($this->getHalfOpenSuccessKey());
    }
    
    /**
     * Set the last time the circuit was tripped.
     *
     * @param int $timestamp
     * @return void
     */
    protected function setLastTripped(int $timestamp): void
    {
        Cache::put($this->getLastTrippedKey(), $timestamp);
    }
    
    /**
     * Get the last time the circuit was tripped.
     *
     * @return int
     */
    protected function getLastTripped(): int
    {
        return Cache::get($this->getLastTrippedKey(), 0);
    }
    
    /**
     * Determine if we should attempt to reset the circuit.
     *
     * @return bool
     */
    protected function shouldAttemptReset(): bool
    {
        $lastTripped = $this->getLastTripped();
        $now = time();
        
        return ($now - $lastTripped) >= $this->resetTimeout;
    }
    
    /**
     * Get the state cache key.
     *
     * @return string
     */
    protected function getStateKey(): string
    {
        return $this->cachePrefix . 'state_' . $this->serviceName;
    }
    
    /**
     * Get the failure count cache key.
     *
     * @return string
     */
    protected function getFailureKey(): string
    {
        return $this->cachePrefix . 'failures_' . $this->serviceName;
    }
    
    /**
     * Get the last tripped cache key.
     *
     * @return string
     */
    protected function getLastTrippedKey(): string
    {
        return $this->cachePrefix . 'last_tripped_' . $this->serviceName;
    }
    
    /**
     * Get the half-open success count cache key.
     *
     * @return string
     */
    protected function getHalfOpenSuccessKey(): string
    {
        return $this->cachePrefix . 'half_open_success_' . $this->serviceName;
    }
}

/**
 * Exception thrown when a circuit is open.
 */
class CircuitBreakerOpenException extends Exception
{
} 