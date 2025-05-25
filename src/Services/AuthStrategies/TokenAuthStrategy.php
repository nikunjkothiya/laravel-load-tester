<?php

namespace NikunjKothiya\LaravelLoadTesting\Services\AuthStrategies;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class TokenAuthStrategy implements AuthStrategy
{
    /**
     * @var array Configuration
     */
    protected $config;
    
    /**
     * @var string API token
     */
    protected $token;
    
    /**
     * @var string Token type (Bearer, Token, etc.)
     */
    protected $tokenType;
    
    /**
     * @var string Token header name
     */
    protected $tokenHeader;
    
    /**
     * Create a new TokenAuthStrategy instance
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->tokenType = $config['auth']['token']['token_type'] ?? 'Bearer';
        $this->tokenHeader = $config['auth']['token']['token_header'] ?? 'Authorization';
    }
    
    /**
     * Authenticate using token-based auth
     *
     * @param array $credentials
     * @return array
     */
    public function authenticate(array $credentials): array
    {
        // If we already have a token provided in credentials, use that
        if (!empty($credentials['api_token'])) {
            $this->token = $credentials['api_token'];
            return [
                'success' => true,
                'token' => $this->token,
                'token_type' => $this->tokenType,
            ];
        }
        
        $endpoint = $this->config['auth']['token']['endpoint'] ?? '/api/login';
        $usernameField = $this->config['auth']['token']['username_field'] ?? 'email';
        $passwordField = $this->config['auth']['token']['password_field'] ?? 'password';
        $tokenResponseField = $this->config['auth']['token']['token_response_field'] ?? 'token';
        
        $client = new Client([
            'base_uri' => $this->config['base_url'],
            'timeout' => $this->config['test']['timeout'] ?? 30,
            'verify' => false,
            'http_errors' => false,
        ]);
        
        try {
            // Get token
            $response = $client->post($endpoint, [
                'json' => [
                    $usernameField => $credentials[$usernameField] ?? $credentials['username'] ?? '',
                    $passwordField => $credentials[$passwordField] ?? $credentials['password'] ?? '',
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            
            // Handle response
            $responseData = json_decode((string) $response->getBody(), true);
            
            if (!$responseData) {
                Log::error("Invalid JSON response from token endpoint: " . $response->getBody());
                return ['success' => false, 'error' => 'Invalid response format'];
            }
            
            // Extract token from different possible response formats
            if (isset($responseData[$tokenResponseField])) {
                $this->token = $responseData[$tokenResponseField];
            } elseif (isset($responseData['data'][$tokenResponseField])) {
                $this->token = $responseData['data'][$tokenResponseField];
            } elseif (isset($responseData['token'])) {
                $this->token = $responseData['token'];
            } elseif (isset($responseData['access_token'])) {
                $this->token = $responseData['access_token'];
            } else {
                Log::error("Token field not found in response. Available fields: " . implode(', ', array_keys($responseData)));
                return ['success' => false, 'error' => 'Token not found in response'];
            }
            
            return [
                'success' => true,
                'token' => $this->token,
                'token_type' => $this->tokenType,
                'response' => $responseData,
            ];
            
        } catch (\Exception $e) {
            Log::error('Token authentication failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get authentication headers for requests
     *
     * @return array
     */
    public function getAuthHeaders(): array
    {
        if (empty($this->token)) {
            return [];
        }
        
        return [
            'headers' => [
                $this->tokenHeader => $this->tokenType . ' ' . $this->token
            ]
        ];
    }
    
    /**
     * Refresh the token if needed
     *
     * @return bool
     */
    public function refreshToken(): bool
    {
        // For simple tokens, refresh is typically not needed
        // But could be implemented for specific token types that expire
        return !empty($this->token);
    }
} 