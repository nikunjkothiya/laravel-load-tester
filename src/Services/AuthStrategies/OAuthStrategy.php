<?php

namespace NikunjKothiya\LaravelLoadTesting\Services\AuthStrategies;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OAuthStrategy implements AuthStrategy
{
    /**
     * OAuth configuration
     *
     * @var array
     */
    protected $config;
    
    /**
     * OAuth access token
     *
     * @var string|null
     */
    protected $accessToken;
    
    /**
     * OAuth refresh token
     *
     * @var string|null
     */
    protected $refreshToken;
    
    /**
     * Token expiration timestamp
     *
     * @var int|null
     */
    protected $expiresAt;
    
    /**
     * HTTP client
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Create a new OAuth strategy instance.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new Client([
            'base_uri' => $config['base_url'] ?? config('load-testing.base_url'),
            'verify' => false,
        ]);
    }

    /**
     * Authenticate using OAuth2.
     *
     * @param array $credentials
     * @return array
     * @throws \Exception
     */
    public function authenticate(array $credentials): array
    {
        $grantType = $this->config['grant_type'] ?? 'password';
        
        try {
            switch ($grantType) {
                case 'password':
                    return $this->passwordGrant($credentials);
                
                case 'client_credentials':
                    return $this->clientCredentialsGrant();
                
                case 'authorization_code':
                    return $this->authorizationCodeGrant($credentials);
                
                default:
                    throw new \InvalidArgumentException("Unsupported grant type: {$grantType}");
            }
        } catch (\Exception $e) {
            Log::error('OAuth authentication failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get authentication headers for requests.
     *
     * @return array
     */
    public function getAuthHeaders(): array
    {
        if (empty($this->accessToken)) {
            throw new \RuntimeException('No access token available. Authentication required.');
        }
        
        if ($this->expiresAt && time() > $this->expiresAt) {
            $this->refreshToken();
        }
        
        return [
            'Authorization' => 'Bearer ' . $this->accessToken
        ];
    }

    /**
     * Refresh the access token.
     *
     * @return bool
     */
    public function refreshToken(): bool
    {
        if (empty($this->refreshToken)) {
            throw new \RuntimeException('No refresh token available. Re-authentication required.');
        }
        
        try {
            $response = $this->client->post($this->config['token_endpoint'], [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refreshToken,
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'scope' => $this->config['scope'] ?? '',
                ],
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            $this->accessToken = $data['access_token'];
            $this->refreshToken = $data['refresh_token'] ?? $this->refreshToken;
            $this->expiresAt = time() + ($data['expires_in'] ?? 3600);
            
            return true;
        } catch (\Exception $e) {
            Log::error('OAuth token refresh failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Authenticate using password grant.
     *
     * @param array $credentials
     * @return array
     */
    protected function passwordGrant(array $credentials): array
    {
        $response = $this->client->post($this->config['token_endpoint'], [
            'form_params' => [
                'grant_type' => 'password',
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'username' => $credentials['username'] ?? $credentials['email'],
                'password' => $credentials['password'],
                'scope' => $this->config['scope'] ?? '',
            ],
        ]);
        
        $data = json_decode($response->getBody(), true);
        
        $this->accessToken = $data['access_token'];
        $this->refreshToken = $data['refresh_token'] ?? null;
        $this->expiresAt = time() + ($data['expires_in'] ?? 3600);
        
        return [
            'token' => $this->accessToken,
            'headers' => $this->getAuthHeaders(),
        ];
    }

    /**
     * Authenticate using client credentials grant.
     *
     * @return array
     */
    protected function clientCredentialsGrant(): array
    {
        $response = $this->client->post($this->config['token_endpoint'], [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'scope' => $this->config['scope'] ?? '',
            ],
        ]);
        
        $data = json_decode($response->getBody(), true);
        
        $this->accessToken = $data['access_token'];
        $this->expiresAt = time() + ($data['expires_in'] ?? 3600);
        
        return [
            'token' => $this->accessToken,
            'headers' => $this->getAuthHeaders(),
        ];
    }

    /**
     * Authenticate using authorization code grant.
     *
     * @param array $credentials
     * @return array
     */
    protected function authorizationCodeGrant(array $credentials): array
    {
        // Generate state for CSRF protection
        $state = Str::random(40);
        
        // Store state in session (or other storage) for verification
        session(['oauth_state' => $state]);
        
        // Build the authorization URL
        $authUrl = $this->config['auth_endpoint'] . '?' . http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => $this->config['scope'] ?? '',
            'state' => $state,
        ]);
        
        // For load testing, we can't actually redirect the user, so we'll use a pre-obtained code
        if (empty($credentials['code'])) {
            throw new \InvalidArgumentException('Authorization code required for OAuth authorization_code grant');
        }
        
        // Exchange the authorization code for an access token
        $response = $this->client->post($this->config['token_endpoint'], [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'redirect_uri' => $this->config['redirect_uri'],
                'code' => $credentials['code'],
            ],
        ]);
        
        $data = json_decode($response->getBody(), true);
        
        $this->accessToken = $data['access_token'];
        $this->refreshToken = $data['refresh_token'] ?? null;
        $this->expiresAt = time() + ($data['expires_in'] ?? 3600);
        
        return [
            'token' => $this->accessToken,
            'headers' => $this->getAuthHeaders(),
        ];
    }
} 