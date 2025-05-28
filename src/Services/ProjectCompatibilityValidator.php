<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

class ProjectCompatibilityValidator
{
    /**
     * @var array Project analysis results
     */
    protected $analysis = [];

    /**
     * Validate project compatibility and provide recommendations.
     *
     * @return array
     */
    public function validateProject(): array
    {
        $this->analysis = [
            'compatible' => true,
            'warnings' => [],
            'errors' => [],
            'recommendations' => [],
            'project_info' => [],
            'auth_systems' => [],
            'route_analysis' => [],
            'database_analysis' => [],
            'performance_recommendations' => [],
        ];

        // Analyze project structure
        $this->analyzeProjectStructure();

        // Analyze authentication systems
        $this->analyzeAuthSystems();

        // Analyze routes
        $this->analyzeRoutes();

        // Analyze database
        $this->analyzeDatabase();

        // Check Laravel version compatibility
        $this->checkLaravelCompatibility();

        // Check PHP version compatibility
        $this->checkPhpCompatibility();

        // Analyze performance considerations
        $this->analyzePerformanceFactors();

        // Generate recommendations
        $this->generateRecommendations();

        return $this->analysis;
    }

    /**
     * Analyze project structure and type.
     */
    protected function analyzeProjectStructure(): void
    {
        try {
            $structure = [
                'type' => 'standard',
                'packages' => [],
                'frontend_framework' => 'blade',
                'api_enabled' => false,
                'spa_mode' => false,
                'admin_panel' => false,
                'multi_tenant' => false,
            ];

            // Analyze composer.json
            $composerPath = base_path('composer.json');
            if (File::exists($composerPath)) {
                $composer = json_decode(File::get($composerPath), true);
                $packages = array_keys($composer['require'] ?? []);
                $structure['packages'] = $packages;

                // Detect project type based on packages
                if (in_array('laravel/sanctum', $packages) || in_array('laravel/passport', $packages)) {
                    $structure['api_enabled'] = true;
                }

                if (in_array('inertiajs/inertia-laravel', $packages)) {
                    $structure['frontend_framework'] = 'inertia';
                    $structure['spa_mode'] = true;
                }

                if (in_array('livewire/livewire', $packages)) {
                    $structure['frontend_framework'] = 'livewire';
                }

                if (in_array('laravel/nova', $packages) || in_array('filament/filament', $packages)) {
                    $structure['admin_panel'] = true;
                }

                if (in_array('spatie/laravel-multitenancy', $packages)) {
                    $structure['multi_tenant'] = true;
                }
            }

            // Check for frontend assets
            if (File::exists(base_path('resources/js/app.vue'))) {
                $structure['frontend_framework'] = 'vue';
                $structure['spa_mode'] = true;
            } elseif (File::exists(base_path('resources/js/app.jsx'))) {
                $structure['frontend_framework'] = 'react';
                $structure['spa_mode'] = true;
            }

            // Check for API routes
            if (File::exists(base_path('routes/api.php'))) {
                $structure['api_enabled'] = true;
            }

            // Determine overall project type
            if ($structure['api_enabled'] && $structure['spa_mode']) {
                $structure['type'] = 'spa_api';
            } elseif ($structure['api_enabled']) {
                $structure['type'] = 'api';
            } elseif ($structure['spa_mode']) {
                $structure['type'] = 'spa';
            } elseif ($structure['admin_panel']) {
                $structure['type'] = 'admin';
            }

            $this->analysis['project_info'] = $structure;
        } catch (\Exception $e) {
            $this->analysis['errors'][] = 'Failed to analyze project structure: ' . $e->getMessage();
        }
    }

    /**
     * Analyze authentication systems.
     */
    protected function analyzeAuthSystems(): void
    {
        try {
            $authSystems = [];

            // Check for session auth
            if (Schema::hasTable('sessions') || config('session.driver') !== null) {
                $authSystems['session'] = [
                    'available' => true,
                    'confidence' => 90,
                    'indicators' => ['sessions table exists', 'session driver configured'],
                ];
            }

            // Check for Sanctum
            if (class_exists('\Laravel\Sanctum\Sanctum')) {
                $indicators = ['Sanctum package installed'];
                $confidence = 70;

                if (Schema::hasTable('personal_access_tokens')) {
                    $indicators[] = 'personal_access_tokens table exists';
                    $confidence += 20;
                }

                if ($this->hasMiddleware('auth:sanctum')) {
                    $indicators[] = 'auth:sanctum middleware in use';
                    $confidence += 10;
                }

                $authSystems['sanctum'] = [
                    'available' => true,
                    'confidence' => min(100, $confidence),
                    'indicators' => $indicators,
                ];
            }

            // Check for Passport
            if (class_exists('\Laravel\Passport\Passport')) {
                $indicators = ['Passport package installed'];
                $confidence = 70;

                if (Schema::hasTable('oauth_clients')) {
                    $indicators[] = 'oauth_clients table exists';
                    $confidence += 15;
                }

                if (Schema::hasTable('oauth_access_tokens')) {
                    $indicators[] = 'oauth_access_tokens table exists';
                    $confidence += 15;
                }

                $authSystems['passport'] = [
                    'available' => true,
                    'confidence' => min(100, $confidence),
                    'indicators' => $indicators,
                ];
            }

            // Check for JWT
            if (class_exists('\Tymon\JWTAuth\JWTAuth') || class_exists('\PHPOpenSourceSaver\JWTAuth\JWTAuth')) {
                $authSystems['jwt'] = [
                    'available' => true,
                    'confidence' => 80,
                    'indicators' => ['JWT package installed'],
                ];
            }

            // Check for custom token auth
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'api_token')) {
                $authSystems['token'] = [
                    'available' => true,
                    'confidence' => 60,
                    'indicators' => ['api_token column in users table'],
                ];
            }

            $this->analysis['auth_systems'] = $authSystems;

            // Add warnings for missing auth systems in API projects
            if ($this->analysis['project_info']['api_enabled'] && empty($authSystems)) {
                $this->analysis['warnings'][] = 'API routes detected but no API authentication system found';
            }
        } catch (\Exception $e) {
            $this->analysis['errors'][] = 'Failed to analyze authentication systems: ' . $e->getMessage();
        }
    }

    /**
     * Analyze routes for load testing compatibility.
     */
    protected function analyzeRoutes(): void
    {
        try {
            $routeAnalysis = [
                'total_routes' => 0,
                'testable_routes' => 0,
                'auth_required_routes' => 0,
                'api_routes' => 0,
                'web_routes' => 0,
                'complex_routes' => 0,
                'dangerous_routes' => 0,
                'route_types' => [],
            ];

            $routes = Route::getRoutes();
            $routeArray = [];
            foreach ($routes as $route) {
                $routeArray[] = $route;
            }
            $routeAnalysis['total_routes'] = count($routeArray);

            foreach ($routes as $route) {
                $uri = $route->uri();
                $methods = $route->methods();
                $middleware = $route->middleware();

                // Count API vs Web routes
                if (str_starts_with($uri, 'api/')) {
                    $routeAnalysis['api_routes']++;
                } else {
                    $routeAnalysis['web_routes']++;
                }

                // Check for auth requirements
                if ($this->routeRequiresAuth($middleware)) {
                    $routeAnalysis['auth_required_routes']++;
                }

                // Check for complex routes (with parameters)
                if (preg_match('/\{[^}]+\}/', $uri)) {
                    $routeAnalysis['complex_routes']++;
                }

                // Check for dangerous routes
                if ($this->isDangerousRoute($uri, $middleware)) {
                    $routeAnalysis['dangerous_routes']++;
                } else {
                    $routeAnalysis['testable_routes']++;
                }

                // Categorize route types
                foreach ($methods as $method) {
                    if (!isset($routeAnalysis['route_types'][$method])) {
                        $routeAnalysis['route_types'][$method] = 0;
                    }
                    $routeAnalysis['route_types'][$method]++;
                }
            }

            $this->analysis['route_analysis'] = $routeAnalysis;

            // Add warnings based on route analysis
            if ($routeAnalysis['testable_routes'] === 0) {
                $this->analysis['errors'][] = 'No testable routes found. All routes are excluded or dangerous.';
                $this->analysis['compatible'] = false;
            } elseif ($routeAnalysis['testable_routes'] < 5) {
                $this->analysis['warnings'][] = 'Very few testable routes found (' . $routeAnalysis['testable_routes'] . '). Consider reviewing route exclusions.';
            }

            if ($routeAnalysis['complex_routes'] > $routeAnalysis['testable_routes'] * 0.5) {
                $this->analysis['warnings'][] = 'Many routes have parameters. Ensure realistic sample data is available.';
            }
        } catch (\Exception $e) {
            $this->analysis['errors'][] = 'Failed to analyze routes: ' . $e->getMessage();
        }
    }

    /**
     * Analyze database for load testing compatibility.
     */
    protected function analyzeDatabase(): void
    {
        try {
            $dbAnalysis = [
                'connection_available' => false,
                'driver' => null,
                'tables_count' => 0,
                'users_table_exists' => false,
                'sessions_table_exists' => false,
                'migrations_up_to_date' => false,
                'test_data_available' => false,
            ];

            // Test database connection
            try {
                DB::connection()->getPdo();
                $dbAnalysis['connection_available'] = true;
                $dbAnalysis['driver'] = DB::connection()->getDriverName();
            } catch (\Exception $e) {
                $this->analysis['errors'][] = 'Database connection failed: ' . $e->getMessage();
                $this->analysis['compatible'] = false;
                return;
            }

            // Count tables
            $tables = DB::select('SHOW TABLES');
            $dbAnalysis['tables_count'] = count($tables);

            // Check for essential tables
            $dbAnalysis['users_table_exists'] = Schema::hasTable('users');
            $dbAnalysis['sessions_table_exists'] = Schema::hasTable('sessions');

            // Check for test data
            if ($dbAnalysis['users_table_exists']) {
                $userCount = DB::table('users')->count();
                $dbAnalysis['test_data_available'] = $userCount > 0;

                if ($userCount === 0) {
                    $this->analysis['warnings'][] = 'No users found in database. Authentication testing may not work properly.';
                }
            }

            // Check migrations status
            try {
                $pendingMigrations = \Illuminate\Support\Facades\Artisan::call('migrate:status');
                $dbAnalysis['migrations_up_to_date'] = true;
            } catch (\Exception $e) {
                $this->analysis['warnings'][] = 'Could not check migration status: ' . $e->getMessage();
            }

            $this->analysis['database_analysis'] = $dbAnalysis;
        } catch (\Exception $e) {
            $this->analysis['errors'][] = 'Failed to analyze database: ' . $e->getMessage();
        }
    }

    /**
     * Check Laravel version compatibility.
     */
    protected function checkLaravelCompatibility(): void
    {
        try {
            $laravelVersion = app()->version();
            $majorVersion = (int) explode('.', $laravelVersion)[0];

            if ($majorVersion < 9) {
                $this->analysis['errors'][] = "Laravel version {$laravelVersion} is not supported. Minimum required: 9.0";
                $this->analysis['compatible'] = false;
            } elseif ($majorVersion >= 11) {
                $this->analysis['recommendations'][] = "Laravel {$laravelVersion} detected. All features fully supported.";
            } else {
                $this->analysis['recommendations'][] = "Laravel {$laravelVersion} is compatible.";
            }
        } catch (\Exception $e) {
            $this->analysis['warnings'][] = 'Could not determine Laravel version: ' . $e->getMessage();
        }
    }

    /**
     * Check PHP version compatibility.
     */
    protected function checkPhpCompatibility(): void
    {
        $phpVersion = PHP_VERSION;
        $majorMinor = implode('.', array_slice(explode('.', $phpVersion), 0, 2));

        if (version_compare($phpVersion, '8.0', '<')) {
            $this->analysis['errors'][] = "PHP version {$phpVersion} is not supported. Minimum required: 8.0";
            $this->analysis['compatible'] = false;
        } elseif (version_compare($phpVersion, '8.2', '>=')) {
            $this->analysis['recommendations'][] = "PHP {$phpVersion} detected. Excellent performance expected.";
        } else {
            $this->analysis['recommendations'][] = "PHP {$phpVersion} is compatible.";
        }
    }

    /**
     * Analyze performance factors.
     */
    protected function analyzePerformanceFactors(): void
    {
        $recommendations = [];

        // Check memory limit
        $memoryLimit = ini_get('memory_limit');
        $memoryBytes = $this->convertToBytes($memoryLimit);

        if ($memoryBytes < 256 * 1024 * 1024) { // Less than 256MB
            $recommendations[] = "Consider increasing memory_limit from {$memoryLimit} to at least 256M for better performance.";
        }

        // Check max execution time
        $maxExecutionTime = ini_get('max_execution_time');
        if ($maxExecutionTime > 0 && $maxExecutionTime < 300) {
            $recommendations[] = "Consider increasing max_execution_time from {$maxExecutionTime}s to 300s or 0 (unlimited) for load testing.";
        }

        // Check for opcache
        if (!extension_loaded('opcache') || !ini_get('opcache.enable')) {
            $recommendations[] = "Enable OPcache for better PHP performance during load testing.";
        }

        // Check for async support
        if (!extension_loaded('curl')) {
            $this->analysis['warnings'][] = "cURL extension not found. Async load testing may not work properly.";
        }

        $this->analysis['performance_recommendations'] = $recommendations;
    }

    /**
     * Generate final recommendations.
     */
    protected function generateRecommendations(): void
    {
        $recommendations = [];

        // Project type specific recommendations
        $projectType = $this->analysis['project_info']['type'] ?? 'standard';

        switch ($projectType) {
            case 'api':
                $recommendations[] = "API project detected. Focus on API endpoint testing with proper authentication.";
                $recommendations[] = "Consider testing with different payload sizes and authentication methods.";
                break;

            case 'spa':
                $recommendations[] = "SPA project detected. Ensure CSRF protection is properly handled.";
                $recommendations[] = "Test both initial page load and AJAX requests.";
                break;

            case 'spa_api':
                $recommendations[] = "SPA+API project detected. Test both frontend and API endpoints.";
                $recommendations[] = "Ensure proper CORS and authentication handling.";
                break;

            case 'admin':
                $recommendations[] = "Admin panel detected. Be extra careful with route exclusions.";
                $recommendations[] = "Consider testing with admin-level authentication.";
                break;
        }

        // Authentication recommendations
        $authSystems = $this->analysis['auth_systems'];
        if (count($authSystems) > 1) {
            $recommendations[] = "Multiple authentication systems detected. Test with the most appropriate one for your use case.";
        }

        // Route recommendations
        $routeAnalysis = $this->analysis['route_analysis'];
        if ($routeAnalysis['complex_routes'] > 10) {
            $recommendations[] = "Many routes with parameters detected. Ensure realistic test data is available.";
        }

        if ($routeAnalysis['api_routes'] > $routeAnalysis['web_routes']) {
            $recommendations[] = "API-heavy project detected. Focus on API performance testing.";
        }

        $this->analysis['recommendations'] = array_merge($this->analysis['recommendations'], $recommendations);
    }

    /**
     * Helper methods
     */
    protected function hasMiddleware(string $middleware): bool
    {
        try {
            $routes = Route::getRoutes();
            foreach ($routes as $route) {
                if (in_array($middleware, $route->middleware())) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
        return false;
    }

    protected function routeRequiresAuth(array $middleware): bool
    {
        $authMiddleware = ['auth', 'auth:web', 'auth:api', 'auth:sanctum', 'auth:passport'];

        foreach ($middleware as $mw) {
            if (in_array($mw, $authMiddleware) || str_starts_with($mw, 'auth:')) {
                return true;
            }
        }

        return false;
    }

    protected function isDangerousRoute(string $uri, array $middleware): bool
    {
        $dangerousPatterns = [
            'admin*',
            'password/*',
            'email/verify*',
            'logout',
            'telescope*',
            'horizon*',
            '_debugbar*',
            'nova*',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (fnmatch($pattern, $uri)) {
                return true;
            }
        }

        $dangerousMiddleware = ['verified', 'password.confirm', 'signed', 'can:', 'role:', 'permission:'];

        foreach ($middleware as $mw) {
            foreach ($dangerousMiddleware as $dangerous) {
                if (str_starts_with($mw, $dangerous)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function convertToBytes(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int) $val;

        switch ($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }
}
