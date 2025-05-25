<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class AuthDetector
{
    /**
     * Detect the authentication system used in the Laravel application
     *
     * @return string The detected auth system: 'sanctum', 'passport', 'jwt', 'token', 'session', or 'custom'
     */
    public function detectAuthSystem(): string
    {
        // Check for Sanctum
        if ($this->isSanctumInstalled()) {
            Log::info('Detected Sanctum authentication system');
            return 'sanctum';
        }
        
        // Check for Passport
        if ($this->isPassportInstalled()) {
            Log::info('Detected Passport authentication system');
            return 'passport';
        }
        
        // Check for JWT
        if ($this->isJwtInstalled()) {
            Log::info('Detected JWT authentication system');
            return 'jwt';
        }
        
        // Check for Token (API) authentication
        if ($this->isApiTokenAuthEnabled()) {
            Log::info('Detected API Token authentication system');
            return 'token';
        }
        
        // Default to session
        Log::info('Defaulting to session-based authentication system');
        return 'session';
    }
    
    /**
     * Check if Laravel Sanctum is installed
     *
     * @return bool
     */
    protected function isSanctumInstalled(): bool
    {
        return class_exists('Laravel\Sanctum\Sanctum') || 
               $this->isPackageInComposerJson('laravel/sanctum');
    }
    
    /**
     * Check if Laravel Passport is installed
     *
     * @return bool
     */
    protected function isPassportInstalled(): bool
    {
        return class_exists('Laravel\Passport\Passport') || 
               $this->isPackageInComposerJson('laravel/passport');
    }
    
    /**
     * Check if JWT Auth is installed
     *
     * @return bool
     */
    protected function isJwtInstalled(): bool
    {
        return class_exists('Tymon\JWTAuth\JWTAuth') || 
               class_exists('PHPOpenSourceSaver\JWTAuth\JWTAuth') ||
               $this->isPackageInComposerJson('tymon/jwt-auth') || 
               $this->isPackageInComposerJson('php-open-source-saver/jwt-auth');
    }
    
    /**
     * Check if API Token authentication is enabled
     *
     * @return bool
     */
    protected function isApiTokenAuthEnabled(): bool
    {
        // Check auth config for token driver
        $guards = config('auth.guards', []);
        
        foreach ($guards as $guard) {
            if (isset($guard['driver']) && $guard['driver'] === 'token') {
                return true;
            }
        }
        
        // Check if api.php exists and has token-based routes
        $apiRoutesPath = base_path('routes/api.php');
        if (File::exists($apiRoutesPath)) {
            $contents = File::get($apiRoutesPath);
            // Look for common API auth middleware
            if (strpos($contents, 'auth:api') !== false || 
                strpos($contents, 'auth:token') !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if a package is listed in composer.json
     *
     * @param string $packageName
     * @return bool
     */
    protected function isPackageInComposerJson(string $packageName): bool
    {
        $composerJsonPath = base_path('composer.json');
        if (!File::exists($composerJsonPath)) {
            return false;
        }
        
        $composerJson = json_decode(File::get($composerJsonPath), true);
        if (!$composerJson) {
            return false;
        }
        
        // Check both require and require-dev
        return isset($composerJson['require'][$packageName]) || 
               isset($composerJson['require-dev'][$packageName]);
    }
} 