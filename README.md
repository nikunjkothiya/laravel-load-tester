# Laravel Load Testing

A comprehensive load testing package for Laravel applications. Monitor performance, memory, and CPU usage under high load conditions with advanced resilience features.

## Features

- Simulate concurrent user traffic on your Laravel application
- Test authenticated routes with automatic authentication detection
- Support for multiple authentication methods (Session, Token, JWT, OAuth)
- Database management with connection pooling and snapshots
- Error handling with Circuit Breaker pattern and RetryHandler
- Real-time metrics collection and visualization
- Resource management for proper cleanup
- Interactive dashboard with WebSocket updates
- Comprehensive reporting
- Laravel 8+ compatibility

## Requirements

- PHP 8.0+
- Laravel 8+
- GuzzleHttp 7+
- React PHP (for WebSocket dashboard)

## Installation & Quick Setup

### Step 1: Install the package

```bash
composer require nikunjkothiya/laravel-load-testing --dev
```

### Step 2: Initialize the load testing environment

```bash
php artisan load-test:init
```

This single command automatically:
- Creates a `.env.loadtesting` file with optimal settings
- Detects your database, authentication, and server resources
- Configures all necessary parameters based on your environment
- Generates random security keys for safe load testing

### Step 3: Run your first load test

```bash
php artisan load-test:run
```

That's it! No manual configuration needed.

## Configuration Details

The initialization process (`php artisan load-test:init`) analyzes your application and sets up everything for immediate testing. If you need to re-initialize with new settings:

```bash
php artisan load-test:init --force
```

### Publishing Configuration (Optional)

If you need to customize the package further, you can publish the configuration:

```bash
php artisan vendor:publish --provider="NikunjKothiya\LaravelLoadTesting\LoadTestingServiceProvider" --tag="config"
```

## Running Load Tests

The package uses your auto-detected settings to:
1. Prepare your environment for testing
2. Configure the appropriate authentication method
3. Set up database safeguards
4. Run the load test with optimized settings
5. Generate a comprehensive report

### Command Options

You can override the auto-detected settings via command line:

```bash
# Override number of concurrent users
php artisan load-test:run --users=100

# Override test duration
php artisan load-test:run --duration=300

# Focus on specific routes
php artisan load-test:run --routes="/api/*"

# Save results to a specific file
php artisan load-test:run --output=results.html
```

## Real-time Dashboard

The dashboard is automatically configured during initialization:

1. Access at `http://your-app.test/load-testing-dashboard` (configurable in .env.loadtesting)
2. View real-time metrics with WebSocket updates
3. Monitor test progress with detailed statistics
4. Review historical test results

## Under the Hood: What Gets Configured

The initialization process automatically configures:

### Authentication
- Detects Laravel's authentication system
- Identifies Sanctum, Passport, or JWT if installed
- Configures proper credentials and methods

### Database Management
- Detects your database type and optimizes connection settings
- Configures appropriate pool sizes based on your database limits
- Sets up snapshot capabilities for clean testing

### Performance Settings
- Analyzes your server resources
- Configures concurrent user count based on available memory
- Sets optimal timeout and retry settings

### Error Handling
- Configures circuit breaker thresholds
- Sets up retry mechanisms with exponential backoff
- Establishes proper error logging and reporting

## Best Practices

1. **Use a dedicated environment**: Never run load tests against production.

2. **Start small**: The auto-detection sets reasonable defaults, but start with fewer users for initial tests.

3. **Run regularly**: Incorporate load testing into your development pipeline.

4. **Review dashboard metrics**: Identify performance bottlenecks using the real-time dashboard.

5. **Update when needed**: Run `php artisan load-test:init --force` to recreate the configuration with optimal settings after major application changes.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information. 