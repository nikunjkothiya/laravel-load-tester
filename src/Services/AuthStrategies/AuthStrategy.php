<?php

namespace NikunjKothiya\LaravelLoadTesting\Services\AuthStrategies;

interface AuthStrategy
{
    /**
     * Authenticate a user with the given credentials
     *
     * @param array $credentials The user credentials
     * @return array Authentication data (tokens, cookies, etc.)
     */
    public function authenticate(array $credentials): array;
    
    /**
     * Get the authentication headers or cookies for requests
     *
     * @return array Headers or cookies for authenticated requests
     */
    public function getAuthHeaders(): array;
    
    /**
     * Refresh the authentication token if needed
     *
     * @return bool Success status
     */
    public function refreshToken(): bool;
} 