<?php

namespace NikunjKothiya\LaravelLoadTesting\Services\AuthStrategies;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;

class SessionAuthStrategy implements AuthStrategy
{
    /**
     * @var array Configuration
     */
    protected $config;
    
    /**
     * @var CookieJar Session cookies
     */
    protected $cookies;
    
    /**
     * @var string CSRF token
     */
    protected $csrfToken;
    
    /**
     * Create a new SessionAuthStrategy instance
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->cookies = new CookieJar();
    }
    
    /**
     * Authenticate using session-based auth
     *
     * @param array $credentials
     * @return array
     */
    public function authenticate(array $credentials): array
    {
        $loginRoute = $this->config['auth']['session']['login_route'] ?? '/login';
        $usernameField = $this->config['auth']['session']['username_field'] ?? 'email';
        $passwordField = $this->config['auth']['session']['password_field'] ?? 'password';
        $csrfField = $this->config['auth']['session']['csrf_field'] ?? '_token';
        
        $client = new Client([
            'base_uri' => $this->config['base_url'],
            'timeout' => $this->config['test']['timeout'] ?? 30,
            'cookies' => $this->cookies,
            'verify' => false,
            'http_errors' => false,
        ]);
        
        try {
            // Get CSRF token first
            $response = $client->get($loginRoute);
            $body = (string) $response->getBody();
            
            // Extract CSRF token
            preg_match('/<input type="hidden" name="'.$csrfField.'" value="([^"]+)"/', $body, $matches);
            $this->csrfToken = $matches[1] ?? null;
            
            if (!$this->csrfToken) {
                // Try JSON meta tag for SPA apps
                preg_match('/<meta name="csrf-token" content="([^"]+)"/', $body, $matches);
                $this->csrfToken = $matches[1] ?? null;
                
                if (!$this->csrfToken) {
                    Log::warning("CSRF token not found for session authentication. Using empty token.");
                }
            }
            
            // Login the user
            $response = $client->post($loginRoute, [
                'form_params' => [
                    $csrfField => $this->csrfToken ?? '',
                    $usernameField => $credentials[$usernameField] ?? $credentials['username'] ?? '',
                    $passwordField => $credentials[$passwordField] ?? $credentials['password'] ?? '',
                ],
                'allow_redirects' => true,
            ]);
            
            // Check if login was successful (status 2xx or redirect to dashboard)
            $success = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
            
            return [
                'success' => $success,
                'cookies' => $this->cookies,
                'csrf_token' => $this->csrfToken,
            ];
            
        } catch (\Exception $e) {
            Log::error('Session authentication failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get authentication headers/cookies for requests
     *
     * @return array
     */
    public function getAuthHeaders(): array
    {
        $headers = [];
        
        // Add CSRF token for headers if available
        if ($this->csrfToken) {
            $headers['X-CSRF-TOKEN'] = $this->csrfToken;
        }
        
        return [
            'cookies' => $this->cookies,
            'headers' => $headers,
        ];
    }
    
    /**
     * Refresh the session if needed
     *
     * @return bool
     */
    public function refreshToken(): bool
    {
        // Sessions typically don't need manual refreshing as they're handled by cookies
        // But we could implement a check to see if the session is still valid
        return true;
    }
} 