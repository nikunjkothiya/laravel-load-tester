<?php

namespace NikunjKothiya\LaravelLoadTesting\Services\AuthStrategies;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class JWTAuthStrategy implements AuthStrategy
{
    /**
     * @var array Configuration
     */
    protected $config;
    
    /**
     * @var string JWT token
     */
    protected $token;
    
    /**
     * @var string Token type
     */
    protected $tokenType;
    
    /**
     * @var int Expiration timestamp
     */
    protected $expiresAt;
    
    /**
     * @var array User credentials for refreshing
     */
    protected $credentials;
    
    /**
     * Create a new JWTAuthStrategy instance
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->tokenType = $config['auth']['jwt']['token_type'] ?? 'Bearer';
    }
    
    /**
     * Authenticate using JWT
     *
     * @param array $credentials
     * @return array
     */
    public function authenticate(array $credentials): array
    {
        $this->credentials = $credentials;
        
        $endpoint = $this->config['auth']['jwt']['endpoint'] ?? '/api/auth/login';
        $usernameField = $this->config['auth']['jwt']['username_field'] ?? 'email';
        $passwordField = $this->config['auth']['jwt']['password_field'] ?? 'password';
        $tokenResponseField = $this->config['auth']['jwt']['token_response_field'] ?? 'access_token';
        
        $client = new Client([
            'base_uri' => $this->config['base_url'],
            'timeout' => $this->config['test']['timeout'] ?? 30,
            'verify' => false,
            'http_errors' => false,
        ]);
        
        try {
            // Get JWT token
            $response = $client->post($endpoint, [
                'json' => [
                    $usernameField => $credentials[$usernameField] ?? $credentials['username'] ?? '',
                    $passwordField => $credentials[$passwordField] ?? $credentials['password'] ?? '',
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
            
            // Handle response
            $responseData = json_decode((string) $response->getBody(), true);
            
            if (!$responseData) {
                Log::error("Invalid JSON response from JWT endpoint: " . $response->getBody());
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
                Log::error("JWT token field not found in response. Available fields: " . implode(', ', array_keys($responseData)));
                return ['success' => false, 'error' => 'Token not found in response'];
            }
            
            // Set token expiration (JWT tokens typically expire after a set time)
            // Use expiration from response or default to 1 hour
            $this->expiresAt = time() + ($responseData['expires_in'] ?? 3600);
            
            return [
                'success' => true,
                'token' => $this->token,
                'token_type' => $this->tokenType,
                'expires_at' => $this->expiresAt,
                'response' => $responseData,
            ];
            
        } catch (\Exception $e) {
            Log::error('JWT authentication failed: ' . $e->getMessage());
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
        
        // Check if token needs refreshing
        if ($this->expiresAt && time() > $this->expiresAt - 300) { // Refresh 5 minutes before expiry
            $this->refreshToken();
        }
        
        return [
            'headers' => [
                'Authorization' => $this->tokenType . ' ' . $this->token
            ]
        ];
    }
    
    /**
     * Refresh the JWT token if it's expired or about to expire
     *
     * @return bool
     */
    public function refreshToken(): bool
    {
        if (empty($this->credentials)) {
            return false;
        }
        
        $refreshEndpoint = $this->config['auth']['jwt']['refresh_endpoint'] ?? '/api/auth/refresh';
        
        try {
            $client = new Client([
                'base_uri' => $this->config['base_url'],
                'timeout' => $this->config['test']['timeout'] ?? 30,
                'verify' => false,
                'http_errors' => false,
            ]);
            
            // Some JWT implementations allow refreshing with the existing token
            $response = $client->post($refreshEndpoint, [
                'headers' => [
                    'Authorization' => $this->tokenType . ' ' . $this->token,
                    'Accept' => 'application/json',
                ],
            ]);
            
            $responseData = json_decode((string) $response->getBody(), true);
            
            if ($responseData && isset($responseData['access_token'])) {
                $this->token = $responseData['access_token'];
                $this->expiresAt = time() + ($responseData['expires_in'] ?? 3600);
                return true;
            }
            
            // If refresh fails, try re-authenticating
            $result = $this->authenticate($this->credentials);
            return $result['success'] ?? false;
            
        } catch (\Exception $e) {
            Log::error('JWT token refresh failed: ' . $e->getMessage());
            
            // Try re-authenticating as fallback
            $result = $this->authenticate($this->credentials);
            return $result['success'] ?? false;
        }
    }
    
    /**
     * Decode JWT token to get payload data
     *
     * @param string $token
     * @return array|null
     */
    protected function decodeJwtToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        $payload = $parts[1];
        $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload));
        
        return json_decode($payload, true);
    }
} 