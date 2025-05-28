<?php

namespace NikunjKothiya\LaravelLoadTesting;

use Illuminate\Support\ServiceProvider;
use NikunjKothiya\LaravelLoadTesting\Commands\RunLoadTestCommand;
use NikunjKothiya\LaravelLoadTesting\Commands\PrepareLoadTestCommand;
use NikunjKothiya\LaravelLoadTesting\Commands\ConfigTestCommand;
use NikunjKothiya\LaravelLoadTesting\Commands\InitLoadTestingCommand;
use NikunjKothiya\LaravelLoadTesting\Commands\ConfigValidationCommand;
use NikunjKothiya\LaravelLoadTesting\Services\AuthDetector;
use NikunjKothiya\LaravelLoadTesting\Services\AuthManager;
use NikunjKothiya\LaravelLoadTesting\Services\CircuitBreaker;
use NikunjKothiya\LaravelLoadTesting\Services\DatabaseManager;
use NikunjKothiya\LaravelLoadTesting\Services\DashboardWebSocket;
use NikunjKothiya\LaravelLoadTesting\Services\LoadTestingService;
use NikunjKothiya\LaravelLoadTesting\Services\MetricsCollector;
use NikunjKothiya\LaravelLoadTesting\Services\ResourceManager;
use NikunjKothiya\LaravelLoadTesting\Services\RetryHandler;
use NikunjKothiya\LaravelLoadTesting\Http\Middleware\LoadTestingMiddleware;
use React\EventLoop\Factory as ReactFactory;

class LoadTestingServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'load-testing');

        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/load-testing.php' => config_path('load-testing.php'),
        ], 'config');

        // Publish views
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/load-testing'),
        ], 'views');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                RunLoadTestCommand::class,
                PrepareLoadTestCommand::class,
                ConfigTestCommand::class,
                InitLoadTestingCommand::class,
                ConfigValidationCommand::class,
                \NikunjKothiya\LaravelLoadTesting\Commands\StartDashboardServerCommand::class,
            ]);
        }

        // Load routes if package is enabled and dashboard is configured
        if (config('load-testing.enabled', true) && config('load-testing.reporting.dashboard.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/load-testing.php');

            // Register the middleware
            $this->app['router']->aliasMiddleware('load-testing', LoadTestingMiddleware::class);
        }
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        // Merge config
        $this->mergeConfigFrom(__DIR__ . '/../config/load-testing.php', 'load-testing');

        // Register EventLoop
        $this->app->singleton('load-testing.event-loop', function ($app) {
            return ReactFactory::create();
        });

        // Register services
        $this->app->singleton(LoadTestingService::class, function ($app) {
            return new LoadTestingService(
                $app->make(AuthManager::class),
                $app->make(DatabaseManager::class),
                $app->make(MetricsCollector::class),
                $app->make(ResourceManager::class)
            );
        });

        $this->app->singleton(AuthDetector::class, function ($app) {
            return new AuthDetector();
        });

        $this->app->singleton(AuthManager::class, function ($app) {
            return new AuthManager(
                config('load-testing'),
                $app->make(AuthDetector::class)
            );
        });

        $this->app->singleton(DatabaseManager::class, function ($app) {
            return new DatabaseManager(config('load-testing'));
        });

        $this->app->singleton(MetricsCollector::class, function ($app) {
            return new MetricsCollector(config('load-testing'));
        });

        $this->app->singleton(ResourceManager::class, function ($app) {
            return new ResourceManager();
        });

        $this->app->singleton(RetryHandler::class, function ($app) {
            return new RetryHandler(config('load-testing.retry') ?? []);
        });

        $this->app->singleton(DashboardWebSocket::class, function ($app) {
            return new DashboardWebSocket(
                $app->make(MetricsCollector::class),
                $app->make('load-testing.event-loop')
            );
        });

        // Circuit Breaker is not a singleton as it's used per service
        $this->app->bind(CircuitBreaker::class, function ($app) {
            return new CircuitBreaker('default', config('load-testing.circuit_breaker') ?? []);
        });

        // Legacy singleton for backward compatibility
        $this->app->singleton('load-testing', function ($app) {
            return $app->make(LoadTestingService::class);
        });
    }
}
