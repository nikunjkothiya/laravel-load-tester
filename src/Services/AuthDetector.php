<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class AuthDetector
{
    /**
     * Detect the authentication system used in the Laravel application.
     *
     * @return string
     */
    public function detectAuthSystem(): string
    {
        try {
            // Multi-step detection with fallbacks
            $detectionResults = [
                'sanctum' => $this->hasSanctum(),
                'passport' => $this->hasPassport(),
                'jwt' => $this->hasJwtAuth(),
                'token' => $this->hasTokenAuth(),
                'session' => $this->hasSessionAuth(),
            ];

            // Enhanced detection with confidence scoring
            $confidenceScores = [];

            foreach ($detectionResults as $method => $detected) {
                if ($detected) {
                    $confidenceScores[$method] = $this->calculateConfidenceScore($method);
                }
            }

            // Return the method with highest confidence
            if (!empty($confidenceScores)) {
                arsort($confidenceScores);
                $bestMethod = array_key_first($confidenceScores);

                Log::info("Auth system detected: {$bestMethod}", [
                    'confidence_scores' => $confidenceScores,
                    'detection_results' => $detectionResults
                ]);

                return $bestMethod;
            }

            // Fallback to session if nothing else detected
            Log::info("No specific auth system detected, defaulting to session");
            return 'session';
        } catch (\Exception $e) {
            Log::error('Auth detection failed: ' . $e->getMessage());
            return 'session';
        }
    }

    /**
     * Calculate confidence score for detected auth method.
     */
    protected function calculateConfidenceScore(string $method): int
    {
        $score = 0;

        switch ($method) {
            case 'sanctum':
                // Check for Sanctum-specific indicators
                if (class_exists('\Laravel\Sanctum\Sanctum')) $score += 30;
                if ($this->hasMiddleware('auth:sanctum')) $score += 25;
                if ($this->hasConfig('sanctum')) $score += 20;
                if ($this->hasApiRoutes()) $score += 15;
                if (Schema::hasTable('personal_access_tokens')) $score += 10;
                break;

            case 'passport':
                if (class_exists('\Laravel\Passport\Passport')) $score += 30;
                if ($this->hasMiddleware('auth:api')) $score += 25;
                if ($this->hasConfig('passport')) $score += 20;
                if (Schema::hasTable('oauth_clients')) $score += 15;
                if (Schema::hasTable('oauth_access_tokens')) $score += 10;
                break;

            case 'jwt':
                if ($this->hasJwtPackage()) $score += 30;
                if ($this->hasMiddleware('jwt.auth')) $score += 25;
                if ($this->hasConfig('jwt')) $score += 20;
                if ($this->hasApiRoutes()) $score += 15;
                break;

            case 'token':
                if (Schema::hasColumn('users', 'api_token')) $score += 25;
                if ($this->hasMiddleware('auth:api')) $score += 20;
                if ($this->hasApiRoutes()) $score += 15;
                break;

            case 'session':
                if ($this->hasWebRoutes()) $score += 20;
                if ($this->hasMiddleware('auth:web')) $score += 15;
                if ($this->hasLoginRoute()) $score += 10;
                if (Schema::hasTable('sessions')) $score += 5;
                break;
        }

        return $score;
    }

    /**
     * Check if Laravel Sanctum is installed and configured.
     *
     * @return bool
     */
    protected function hasSanctum(): bool
    {
        try {
            // Check if Sanctum is installed via composer
            if (!class_exists('\Laravel\Sanctum\Sanctum')) {
                return false;
            }

            // Check if Sanctum service provider is registered
            $providers = config('app.providers', []);
            foreach ($providers as $provider) {
                if (str_contains($provider, 'Sanctum')) {
                    return true;
                }
            }

            // Check if Sanctum middleware is used
            if ($this->hasMiddleware('auth:sanctum')) {
                return true;
            }

            // Check if personal access tokens table exists
            if (Schema::hasTable('personal_access_tokens')) {
                return true;
            }

            // Check if Sanctum is configured
            return $this->hasConfig('sanctum');
        } catch (\Exception $e) {
            Log::warning('Sanctum detection failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if Laravel Passport is installed and configured.
     *
     * @return bool
     */
    protected function hasPassport(): bool
    {
        // Check if Passport is installed via composer
        if (!class_exists('\Laravel\Passport\Passport')) {
            return false;
        }

        // Check if Passport service provider is registered
        $providers = config('app.providers', []);
        foreach ($providers as $provider) {
            if (str_contains($provider, 'Passport')) {
                return true;
            }
        }

        // Check if Passport routes are registered
        $routes = Route::getRoutes();
        foreach ($routes as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'oauth/')) {
                return true;
            }
        }

        // Check if Passport config is published
        if (File::exists(config_path('passport.php'))) {
            return true;
        }

        // Check if OAuth tables exist
        try {
            $oauthTables = ['oauth_clients', 'oauth_access_tokens', 'oauth_refresh_tokens'];
            foreach ($oauthTables as $table) {
                if (Schema::hasTable($table)) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Ignore database errors during detection
        }

        return false;
    }

    /**
     * Check if JWT Auth is installed and configured.
     *
     * @return bool
     */
    protected function hasJwtAuth(): bool
    {
        // Check for common JWT packages
        $jwtClasses = [
            '\Tymon\JWTAuth\JWTAuth',
            '\PHPOpenSourceSaver\JWTAuth\JWTAuth',
            '\Firebase\JWT\JWT'
        ];

        foreach ($jwtClasses as $class) {
            if (class_exists($class)) {
                return true;
            }
        }

        // Check if JWT config exists
        if (File::exists(config_path('jwt.php'))) {
            return true;
        }

        // Check for JWT middleware in routes
        $routes = Route::getRoutes();
        foreach ($routes as $route) {
            $middleware = $route->middleware();
            foreach ($middleware as $mw) {
                if (str_contains($mw, 'jwt') || str_contains($mw, 'auth:jwt')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if token-based authentication is used.
     *
     * @return bool
     */
    protected function hasTokenAuth(): bool
    {
        // Check for API token middleware
        $routes = Route::getRoutes();
        foreach ($routes as $route) {
            $middleware = $route->middleware();
            if (in_array('auth:api', $middleware)) {
                return true;
            }
        }

        // Check for Bearer token usage in controllers
        $controllerPath = app_path('Http/Controllers');
        if (File::exists($controllerPath)) {
            $files = File::allFiles($controllerPath);
            foreach ($files as $file) {
                $content = File::get($file);
                if (str_contains($content, 'Bearer') || str_contains($content, 'api_token')) {
                    return true;
                }
            }
        }

        // Check if users table has api_token column
        try {
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'api_token')) {
                return true;
            }
        } catch (\Exception $e) {
            // Ignore database errors during detection
        }

        return false;
    }

    /**
     * Check if session-based authentication is used.
     *
     * @return bool
     */
    protected function hasSessionAuth(): bool
    {
        // Check for web middleware with auth
        $routes = Route::getRoutes();
        foreach ($routes as $route) {
            $middleware = $route->middleware();
            if (in_array('auth', $middleware) && in_array('web', $middleware)) {
                return true;
            }
        }

        // Check for login routes
        foreach ($routes as $route) {
            $uri = $route->uri();
            $name = $route->getName();

            if ($uri === 'login' || $name === 'login' || str_contains($uri, 'login')) {
                return true;
            }
        }

        // Check if Auth controllers exist
        $authPath = app_path('Http/Controllers/Auth');
        if (File::exists($authPath)) {
            return true;
        }

        // Check for Laravel Breeze/Jetstream/UI
        $authViews = [
            'auth.login',
            'auth/login',
            'login'
        ];

        foreach ($authViews as $view) {
            if (view()->exists($view)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get authentication configuration suggestions based on detected system.
     *
     * @return array
     */
    public function getAuthConfigSuggestions(): array
    {
        $detectedAuth = $this->detectAuthSystem();

        $suggestions = [
            'method' => $detectedAuth,
            'config' => []
        ];

        switch ($detectedAuth) {
            case 'sanctum':
                $suggestions['config'] = [
                    'LOAD_TESTING_AUTH_METHOD' => 'sanctum',
                    'LOAD_TESTING_AUTH_USERNAME' => 'test@example.com',
                    'LOAD_TESTING_AUTH_PASSWORD' => 'password',
                ];
                break;

            case 'passport':
                $suggestions['config'] = [
                    'LOAD_TESTING_AUTH_METHOD' => 'passport',
                    'LOAD_TESTING_AUTH_USERNAME' => 'test@example.com',
                    'LOAD_TESTING_AUTH_PASSWORD' => 'password',
                    'LOAD_TESTING_PASSPORT_CLIENT_ID' => 'your-client-id',
                    'LOAD_TESTING_PASSPORT_CLIENT_SECRET' => 'your-client-secret',
                ];
                break;

            case 'jwt':
                $suggestions['config'] = [
                    'LOAD_TESTING_AUTH_METHOD' => 'jwt',
                    'LOAD_TESTING_AUTH_USERNAME' => 'test@example.com',
                    'LOAD_TESTING_AUTH_PASSWORD' => 'password',
                    'LOAD_TESTING_JWT_ENDPOINT' => '/api/auth/login',
                ];
                break;

            case 'token':
                $suggestions['config'] = [
                    'LOAD_TESTING_AUTH_METHOD' => 'token',
                    'LOAD_TESTING_AUTH_USERNAME' => 'test@example.com',
                    'LOAD_TESTING_AUTH_PASSWORD' => 'password',
                    'LOAD_TESTING_TOKEN_ENDPOINT' => '/api/auth/token',
                ];
                break;

            case 'session':
            default:
                $suggestions['config'] = [
                    'LOAD_TESTING_AUTH_METHOD' => 'session',
                    'LOAD_TESTING_AUTH_USERNAME' => 'test@example.com',
                    'LOAD_TESTING_AUTH_PASSWORD' => 'password',
                    'LOAD_TESTING_SESSION_LOGIN_ROUTE' => '/login',
                ];
                break;
        }

        return $suggestions;
    }

    /**
     * Get detailed authentication detection results.
     *
     * @return array
     */
    public function getDetectionDetails(): array
    {
        return [
            'sanctum' => [
                'detected' => $this->hasSanctum(),
                'indicators' => $this->getSanctumIndicators(),
            ],
            'passport' => [
                'detected' => $this->hasPassport(),
                'indicators' => $this->getPassportIndicators(),
            ],
            'jwt' => [
                'detected' => $this->hasJwtAuth(),
                'indicators' => $this->getJwtIndicators(),
            ],
            'token' => [
                'detected' => $this->hasTokenAuth(),
                'indicators' => $this->getTokenIndicators(),
            ],
            'session' => [
                'detected' => $this->hasSessionAuth(),
                'indicators' => $this->getSessionIndicators(),
            ],
        ];
    }

    /**
     * Get Sanctum detection indicators.
     */
    protected function getSanctumIndicators(): array
    {
        $indicators = [];

        if (class_exists('\Laravel\Sanctum\Sanctum')) {
            $indicators[] = 'Sanctum package installed';
        }

        try {
            if (function_exists('config_path') && File::exists(config_path('sanctum.php'))) {
                $indicators[] = 'Sanctum config published';
            }
        } catch (\Exception $e) {
            // Ignore when not in Laravel context
        }

        try {
            if (Schema::hasTable('personal_access_tokens')) {
                $indicators[] = 'Personal access tokens table exists';
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $indicators;
    }

    /**
     * Get Passport detection indicators.
     */
    protected function getPassportIndicators(): array
    {
        $indicators = [];

        if (class_exists('\Laravel\Passport\Passport')) {
            $indicators[] = 'Passport package installed';
        }

        try {
            if (function_exists('config_path') && File::exists(config_path('passport.php'))) {
                $indicators[] = 'Passport config published';
            }
        } catch (\Exception $e) {
            // Ignore when not in Laravel context
        }

        try {
            $oauthTables = ['oauth_clients', 'oauth_access_tokens'];
            foreach ($oauthTables as $table) {
                if (Schema::hasTable($table)) {
                    $indicators[] = "OAuth table '{$table}' exists";
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $indicators;
    }

    /**
     * Get JWT detection indicators.
     */
    protected function getJwtIndicators(): array
    {
        $indicators = [];

        $jwtClasses = [
            '\Tymon\JWTAuth\JWTAuth' => 'tymon/jwt-auth',
            '\PHPOpenSourceSaver\JWTAuth\JWTAuth' => 'php-open-source-saver/jwt-auth',
            '\Firebase\JWT\JWT' => 'firebase/php-jwt'
        ];

        foreach ($jwtClasses as $class => $package) {
            if (class_exists($class)) {
                $indicators[] = "JWT package '{$package}' installed";
            }
        }

        try {
            if (function_exists('config_path') && File::exists(config_path('jwt.php'))) {
                $indicators[] = 'JWT config file exists';
            }
        } catch (\Exception $e) {
            // Ignore when not in Laravel context
        }

        return $indicators;
    }

    /**
     * Get token authentication indicators.
     */
    protected function getTokenIndicators(): array
    {
        $indicators = [];

        try {
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'api_token')) {
                $indicators[] = 'Users table has api_token column';
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $indicators;
    }

    /**
     * Get session authentication indicators.
     */
    protected function getSessionIndicators(): array
    {
        $indicators = [];

        try {
            if (function_exists('app_path') && File::exists(app_path('Http/Controllers/Auth'))) {
                $indicators[] = 'Auth controllers directory exists';
            }
        } catch (\Exception $e) {
            // Ignore when not in Laravel context
        }

        $authViews = ['auth.login', 'auth/login', 'login'];
        foreach ($authViews as $view) {
            try {
                if (function_exists('view') && view()->exists($view)) {
                    $indicators[] = "Auth view '{$view}' exists";
                }
            } catch (\Exception $e) {
                // Ignore view errors
            }
        }

        return $indicators;
    }

    /**
     * Check if specific middleware exists in routes.
     */
    protected function hasMiddleware(string $middleware): bool
    {
        try {
            $routes = Route::getRoutes();
            foreach ($routes as $route) {
                $routeMiddleware = $route->middleware();
                if (in_array($middleware, $routeMiddleware)) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
        return false;
    }

    /**
     * Check if config file exists.
     */
    protected function hasConfig(string $configName): bool
    {
        try {
            return File::exists(config_path($configName . '.php'));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if API routes exist.
     */
    protected function hasApiRoutes(): bool
    {
        try {
            return File::exists(base_path('routes/api.php'));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if web routes exist.
     */
    protected function hasWebRoutes(): bool
    {
        try {
            return File::exists(base_path('routes/web.php'));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if JWT package is installed.
     */
    protected function hasJwtPackage(): bool
    {
        return class_exists('\Tymon\JWTAuth\JWTAuth') ||
            class_exists('\PHPOpenSourceSaver\JWTAuth\JWTAuth');
    }

    /**
     * Check if login route exists.
     */
    protected function hasLoginRoute(): bool
    {
        try {
            $routes = Route::getRoutes();
            foreach ($routes as $route) {
                $uri = $route->uri();
                if ($uri === 'login' || str_contains($uri, 'login')) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
        return false;
    }
}
