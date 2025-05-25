<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NikunjKothiya\LaravelLoadTesting\Services\AuthStrategies\AuthStrategy;
use NikunjKothiya\LaravelLoadTesting\Services\AuthStrategies\SessionAuthStrategy;
use NikunjKothiya\LaravelLoadTesting\Services\AuthStrategies\TokenAuthStrategy;
use NikunjKothiya\LaravelLoadTesting\Services\AuthStrategies\JWTAuthStrategy;

class AuthManager
{
    /**
     * @var array Configuration
     */
    protected $config;
    
    /**
     * @var AuthStrategy The active authentication strategy
     */
    protected $strategy;
    
    /**
     * @var AuthDetector Auth detection service
     */
    protected $authDetector;
    
    /**
     * Create a new AuthManager instance
     *
     * @param array $config
     * @param AuthDetector|null $authDetector
     */
    public function __construct(array $config, AuthDetector $authDetector = null)
    {
        $this->config = $config;
        $this->authDetector = $authDetector ?? new AuthDetector();
    }
    
    /**
     * Prepare authentication for load testing
     *
     * @return array Authentication result
     */
    public function prepareAuthentication(): array
    {
        $authMethod = $this->config['auth']['method'] ?? 'auto-detect';
        
        if ($authMethod === 'auto-detect') {
            $authMethod = $this->authDetector->detectAuthSystem();
            Log::info("Auto-detected authentication method: $authMethod");
        }
        
        // Get credentials
        $credentials = $this->getCredentials();
        
        // Create appropriate strategy
        $this->strategy = $this->createAuthStrategy($authMethod);
        
        if (!$this->strategy) {
            return [
                'success' => false,
                'error' => "Unsupported authentication method: $authMethod",
            ];
        }
        
        // Authenticate
        $result = $this->strategy->authenticate($credentials);
        
        if ($result['success'] ?? false) {
            Log::info("Authentication successful using $authMethod method");
        } else {
            Log::error("Authentication failed: " . ($result['error'] ?? 'Unknown error'));
        }
        
        return $result;
    }
    
    /**
     * Get authentication headers/cookies for a request
     *
     * @return array Headers or cookies for authenticated requests
     */
    public function getAuthForRequest(): array
    {
        if (!$this->strategy) {
            return [];
        }
        
        return $this->strategy->getAuthHeaders();
    }
    
    /**
     * Get user credentials for authentication
     *
     * @return array
     * @throws InvalidArgumentException
     */
    protected function getCredentials(): array
    {
        $credentials = [
            'username' => $this->config['auth']['credentials']['username'] ?? env('LOAD_TESTING_AUTH_USERNAME'),
            'password' => $this->config['auth']['credentials']['password'] ?? env('LOAD_TESTING_AUTH_PASSWORD'),
            'email' => $this->config['auth']['credentials']['username'] ?? env('LOAD_TESTING_AUTH_USERNAME'),
            'api_token' => env('LOAD_TESTING_API_TOKEN'),
        ];
        
        // Check for required credentials
        if (empty($credentials['username']) && empty($credentials['api_token'])) {
            throw new InvalidArgumentException(
                'Load testing credentials not configured. Please set LOAD_TESTING_AUTH_USERNAME/LOAD_TESTING_API_TOKEN in .env file'
            );
        }
        
        return $credentials;
    }
    
    /**
     * Create the appropriate authentication strategy
     *
     * @param string $method
     * @return AuthStrategy|null
     */
    protected function createAuthStrategy(string $method): ?AuthStrategy
    {
        switch ($method) {
            case 'session':
                return new SessionAuthStrategy($this->config);
                
            case 'token':
                return new TokenAuthStrategy($this->config);
                
            case 'jwt':
                return new JWTAuthStrategy($this->config);
                
            case 'sanctum':
                // Sanctum uses the same auth strategy as token
                return new TokenAuthStrategy($this->config);
                
            case 'passport':
                // For Passport, we use the OAuth token flow via the token strategy
                return new TokenAuthStrategy($this->config);
                
            case 'custom':
                // Custom can be implemented as needed
                // For now, default to token-based auth
                return new TokenAuthStrategy($this->config);
                
            default:
                Log::error("Unsupported authentication method: $method");
                return null;
        }
    }
} 