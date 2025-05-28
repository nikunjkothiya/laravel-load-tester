# Laravel Load Testing Package

High-performance load testing package for Laravel applications with real-time monitoring, intelligent route discovery, and comprehensive authentication support.

## 🚀 Features

- **Real-time Load Testing**: Concurrent user simulation with configurable parameters
- **Live Dashboard**: Real-time metrics with WebSocket support for instant updates
- **Authentication Support**: Auto-detection and support for Session, Sanctum, Passport, JWT, and custom auth
- **Database Monitoring**: Query performance tracking and slow query detection
- **Resource Monitoring**: CPU, memory, and system resource tracking
- **Comprehensive Reporting**: HTML, JSON, and console export options
- **Intelligent Route Discovery**: Automatic route detection with smart filtering
- **Environment Validation**: Configuration validation and optimization suggestions
- **Circuit Breaker**: Automatic failure detection and recovery
- **Async Processing**: ReactPHP-based async HTTP client for better performance

## 📋 Requirements

- **PHP**: ^8.0
- **Laravel**: ^8.0|^9.0|^10.0
- **Memory**: Minimum 512MB recommended (2GB+ for high load tests)
- **Extensions**: `curl`, `json`, `mbstring`

## 🔧 Installation

### Method 1: Composer Install (Recommended)

```bash
composer require nikunjkothiya/laravel-load-testing
php artisan vendor:publish --provider="NikunjKothiya\LaravelLoadTesting\LoadTestingServiceProvider"
php artisan load-test:init
```

### Method 2: Local Development

1. **Add to your Laravel project's `composer.json`:**

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-load-tester"
        }
    ],
    "require": {
        "nikunjkothiya/laravel-load-testing": "@dev"
    }
}
```

2. **Install and setup:**
```bash
composer require nikunjkothiya/laravel-load-testing:@dev
php artisan vendor:publish --provider="NikunjKothiya\LaravelLoadTesting\LoadTestingServiceProvider"
php artisan load-test:init
```

## 🎯 Quick Start Guide

### Step 1: Initialize Configuration

**Interactive Setup (Recommended):**
```bash
php artisan load-test:init
```

This wizard will:
- ✅ Create `.env.loadtesting` file
- 🔍 Auto-detect your authentication method
- 👤 Ask for test user credentials
- ⚙️ Configure optimal settings
- 🧪 Verify your setup

**Quick Setup (Use Defaults):**
```bash
php artisan load-test:init --quick
```

### Step 2: Verify Configuration

```bash
php artisan load-test:config
```

This command checks:
- ✅ All required variables are set
- 🔐 Authentication credentials are valid
- 🗄️ Database connectivity
- ⚡ Performance settings
- 🌐 Application accessibility

### Step 3: Run Your First Test

```bash
# Quick test with 5 users for 30 seconds
php artisan load-testing:run --users=5 --duration=30

# Full test with your configured settings
php artisan load-testing:run
```

### Step 4: View Results

The dashboard URL will be displayed in the terminal:
```
📊 Dashboard: http://your-app-url/load-testing-dashboard
```

## ⚙️ Configuration

### Environment Variables (.env.loadtesting)

Create a `.env.loadtesting` file in your project root with the following variables:

#### Core Configuration
```bash
# Package Control
LOAD_TESTING_ENABLED=true
LOAD_TESTING_SECRET_KEY=your-secret-key-here

# Base Configuration
LOAD_TESTING_BASE_URL=http://localhost
LOAD_TESTING_DASHBOARD_URL=load-testing-dashboard

# Test Parameters
LOAD_TESTING_CONCURRENT_USERS=10
LOAD_TESTING_DURATION=60
LOAD_TESTING_RAMP_UP=10
LOAD_TESTING_TIMEOUT=30
LOAD_TESTING_DELAY=1
LOAD_TESTING_THINK_TIME=0
LOAD_TESTING_MAX_REDIRECTS=5
LOAD_TESTING_VERIFY_SSL=false
LOAD_TESTING_USER_AGENT=Laravel-Load-Tester/1.0
```

#### Authentication Configuration
```bash
# Authentication
LOAD_TESTING_AUTH_ENABLED=false
LOAD_TESTING_AUTH_METHOD=auto-detect
LOAD_TESTING_AUTH_USERNAME=test@example.com
LOAD_TESTING_AUTH_PASSWORD=password
LOAD_TESTING_EMAIL=test@example.com
LOAD_TESTING_CREATE_TEST_USERS=false
LOAD_TESTING_TEST_USER_COUNT=10
LOAD_TESTING_CLEANUP_TEST_USERS=true

# Session Authentication
LOAD_TESTING_SESSION_LOGIN_ROUTE=/login
LOAD_TESTING_SESSION_USERNAME_FIELD=email
LOAD_TESTING_SESSION_PASSWORD_FIELD=password
LOAD_TESTING_SESSION_CSRF_FIELD=_token
LOAD_TESTING_SESSION_REMEMBER_FIELD=remember
LOAD_TESTING_SESSION_LOGOUT_ROUTE=/logout

# API Token Authentication
LOAD_TESTING_TOKEN_HEADER=Authorization
LOAD_TESTING_TOKEN_PREFIX=Bearer
LOAD_TESTING_TOKEN_FIELD=api_token

# Laravel Sanctum
LOAD_TESTING_SANCTUM_TOKEN_NAME=load-testing-token
LOAD_TESTING_SANCTUM_ABILITIES=*
LOAD_TESTING_SANCTUM_CSRF_ROUTE=/sanctum/csrf-cookie

# Laravel Passport
LOAD_TESTING_PASSPORT_CLIENT_ID=your-client-id
LOAD_TESTING_PASSPORT_CLIENT_SECRET=your-client-secret
LOAD_TESTING_PASSPORT_SCOPE=*
LOAD_TESTING_PASSPORT_TOKEN_URL=/oauth/token

# JWT Authentication
LOAD_TESTING_JWT_LOGIN_ROUTE=/api/auth/login
LOAD_TESTING_JWT_REFRESH_ROUTE=/api/auth/refresh
LOAD_TESTING_JWT_HEADER=Authorization
LOAD_TESTING_JWT_PREFIX=Bearer
```

#### Route Discovery Configuration
```bash
# Route Discovery
LOAD_TESTING_MAX_ROUTES=100
LOAD_TESTING_ROUTE_DISCOVERY=intelligent
LOAD_TESTING_INCLUDE_ROUTES=
LOAD_TESTING_EXCLUDE_ROUTES=admin/*,api/admin/*
LOAD_TESTING_EXCLUDE_ADMIN=true
LOAD_TESTING_EXCLUDE_AUTH=true
LOAD_TESTING_EXCLUDE_DANGEROUS=true
LOAD_TESTING_USE_REAL_DATA=true
```

#### Database Monitoring
```bash
# Database Monitoring
LOAD_TESTING_MONITOR_DATABASE=true
LOAD_TESTING_CREATE_SNAPSHOTS=false
LOAD_TESTING_USE_TEST_DATABASE=false
LOAD_TESTING_TEST_DB_SUFFIX=_load_test
LOAD_TESTING_DB_MAX_CONNECTIONS=100
LOAD_TESTING_DB_TIMEOUT=30
LOAD_TESTING_SLOW_QUERY_THRESHOLD=1000
LOAD_TESTING_LOG_QUERIES=false
LOAD_TESTING_TRACK_DEADLOCKS=true
```

#### Resource Monitoring
```bash
# Resource Monitoring
LOAD_TESTING_MONITORING_ENABLED=true
LOAD_TESTING_MONITORING_INTERVAL=5
LOAD_TESTING_RESULTS_TABLE=load_testing_results
LOAD_TESTING_DATABASE_MONITORING_ENABLED=true
LOAD_TESTING_MONITOR_CPU=true
LOAD_TESTING_MONITOR_MEMORY=true
LOAD_TESTING_MONITOR_DISK=true
LOAD_TESTING_MONITOR_NETWORK=false
LOAD_TESTING_CPU_WARNING=80
LOAD_TESTING_CPU_CRITICAL=95
LOAD_TESTING_MEMORY_WARNING=80
LOAD_TESTING_MEMORY_CRITICAL=95
```

#### Reporting Configuration
```bash
# Reporting
LOAD_TESTING_REPORTING_ENABLED=true
LOAD_TESTING_REPORT_FORMATS=html,json,console
LOAD_TESTING_OUTPUT_PATH=storage/load-testing
LOAD_TESTING_STORE_IN_DB=true
LOAD_TESTING_KEEP_REPORTS=30
LOAD_TESTING_DASHBOARD_ENABLED=true
LOAD_TESTING_DASHBOARD_ROUTE=/load-testing-dashboard
LOAD_TESTING_DASHBOARD_MIDDLEWARE=web
LOAD_TESTING_DASHBOARD_REFRESH=5
```

#### Advanced Configuration
```bash
# Advanced Settings
LOAD_TESTING_ASYNC_MODE=true
LOAD_TESTING_CONNECTION_POOLING=true
LOAD_TESTING_KEEP_ALIVE=true
LOAD_TESTING_GZIP=true

# Retry Configuration
LOAD_TESTING_RETRY_ENABLED=true
LOAD_TESTING_MAX_RETRIES=3
LOAD_TESTING_RETRY_DELAY=1000

# Circuit Breaker
LOAD_TESTING_CIRCUIT_BREAKER=true
LOAD_TESTING_FAILURE_THRESHOLD=50
LOAD_TESTING_RECOVERY_TIMEOUT=30

# Rate Limiting
LOAD_TESTING_RESPECT_RATE_LIMITS=true
LOAD_TESTING_REQUESTS_PER_MINUTE=0
LOAD_TESTING_BURST_SIZE=10

# Project Adaptation
LOAD_TESTING_AUTO_DETECT_PROJECT=true
LOAD_TESTING_AUTO_CONFIGURE_AUTH=true
LOAD_TESTING_AUTO_EXCLUDE_DANGEROUS=true

# Security
LOAD_TESTING_PREVENT_DATA_MODIFICATION=true
LOAD_TESTING_READ_ONLY_MODE=false
LOAD_TESTING_ALLOWED_ENVIRONMENTS=local,testing,staging
LOAD_TESTING_CHECK_ENVIRONMENT=true
LOAD_TESTING_REQUIRE_CONFIRMATION=false
LOAD_TESTING_BACKUP_BEFORE_TEST=false
```

## 🔐 Authentication Setup

### Auto-Detection Process

The package intelligently detects your authentication system:

1. **Detects** installed authentication packages (Sanctum, Passport, JWT, etc.)
2. **Asks** for test user credentials during setup
3. **Verifies** the user exists in your database
4. **Creates** the user if needed (with permission)
5. **Tests** authentication before saving configuration

### Setting Up Test User

#### Option 1: Interactive Setup (Recommended)
```bash
php artisan load-test:init
# Follow the prompts to enter credentials
```

#### Option 2: Manual Configuration
Edit `.env.loadtesting`:
```bash
LOAD_TESTING_AUTH_ENABLED=true
LOAD_TESTING_AUTH_METHOD=sanctum
LOAD_TESTING_AUTH_USERNAME=test@example.com
LOAD_TESTING_AUTH_PASSWORD=your-password
```

#### Option 3: Create Test User First
```bash
# Create user in your application
php artisan tinker
>>> User::create(['name' => 'Test User', 'email' => 'test@example.com', 'password' => Hash::make('password')])

# Then configure load testing
php artisan load-test:init
```

### Supported Authentication Methods

| Method | Description | Required Settings |
|--------|-------------|-------------------|
| `session` | Laravel default web auth | Username + Password |
| `sanctum` | Laravel Sanctum API tokens | Username + Password |
| `passport` | Laravel Passport OAuth2 | Username + Password + Client ID/Secret |
| `jwt` | JWT token authentication | Username + Password |
| `token` | Custom API token | Token value |
| `auto-detect` | Automatically detect method | Username + Password |

## 📊 Real-Time Dashboard with WebSocket

### Setting Up Real-Time Dashboard

#### 1. Start the WebSocket Server

Open a terminal and run:

```bash
php artisan load-test:dashboard-server
```

By default, the server starts on `ws://localhost:8080`. Customize with:

```bash
# Custom port
php artisan load-test:dashboard-server --port=9090

# Custom host and port
php artisan load-test:dashboard-server --host=0.0.0.0 --port=8080
```

#### 2. Keep the Server Running

The WebSocket server provides real-time updates. You'll see:

```
Starting Load Testing Dashboard WebSocket Server...
Server will be available at: ws://0.0.0.0:8080
Press Ctrl+C to stop the server

✅ WebSocket server started successfully!
📊 Dashboard clients can connect to: ws://0.0.0.0:8080
🔄 Broadcasting metrics every 1 second
```

#### 3. Access the Dashboard

Navigate to your dashboard URL in your browser:
```
http://your-app-url/load-testing-dashboard
```

### Real-Time Features

- **🟢 Live Updates** indicator in the top-right corner
- Real-time metric updates every second
- Live chart updates without page refresh
- Instant notifications for test events

### Connection Status Indicators

- **🟢 Live Updates** - Connected and receiving real-time data
- **🔴 Disconnected** - WebSocket connection lost (will auto-reconnect)
- **⚠️ Connection Error** - Connection failed

### Running Tests with Real-Time Monitoring

#### Terminal 1: Start WebSocket Server
```bash
php artisan load-test:dashboard-server
```

#### Browser: Open Dashboard
Navigate to `/load-testing-dashboard`

#### Terminal 2: Run Load Test
```bash
php artisan load-testing:run --users=10 --duration=60
```

#### Watch Real-Time Updates
- Live response time charts
- Real-time memory and CPU usage
- Status code distribution updates
- Request count incrementing
- Performance metrics updating

### WebSocket Configuration

Configure in `config/load-testing.php` or `.env.loadtesting`:

```bash
# WebSocket Server
LOAD_TEST_WS_HOST=0.0.0.0
LOAD_TEST_WS_PORT=8080
LOAD_TESTING_DASHBOARD_REFRESH=1
```

## 🚀 Usage

### Available Commands

#### Initialize Configuration
```bash
php artisan load-test:init [--quick]
```

#### Validate Configuration
```bash
php artisan load-test:config
```

#### Run Load Test
```bash
php artisan load-testing:run [options]
```

**Options:**
- `--users=N` - Number of concurrent users (default: 10)
- `--duration=N` - Test duration in seconds (default: 60)
- `--timeout=N` - Request timeout in seconds (default: 30)
- `--ramp-up=N` - Ramp-up time in seconds (default: 10)
- `--base-url=URL` - Base URL to test (default: from config)

#### Start Dashboard Server
```bash
php artisan load-test:dashboard-server [--host=HOST] [--port=PORT]
```

#### Prepare Environment
```bash
php artisan load-test:prepare
```

### Integration Types

#### Type 1: Package Integration (Recommended)

Install the package directly in your Laravel application:

```bash
# 1. Install package
composer require nikunjkothiya/laravel-load-testing

# 2. Publish configuration
php artisan vendor:publish --provider="NikunjKothiya\LaravelLoadTesting\LoadTestingServiceProvider"

# 3. Initialize
php artisan load-test:init

# 4. Run tests
php artisan load-testing:run
```

**Pros:**
- Direct integration with your application
- Access to all routes and middleware
- Real database and authentication testing
- Full feature access

**Cons:**
- Adds dependencies to your main application
- May affect production if not properly configured

#### Type 2: Standalone Integration

Use the package as a standalone testing tool:

```bash
# 1. Clone/download package to separate directory
git clone https://github.com/nikunjkothiya/laravel-load-testing.git

# 2. Install dependencies
composer install

# 3. Configure for external testing
cp .env.example .env.loadtesting

# 4. Edit configuration to point to your application
LOAD_TESTING_BASE_URL=https://your-app.com
LOAD_TESTING_AUTH_ENABLED=false  # or configure external auth

# 5. Run tests
php artisan load-testing:run
```

**Pros:**
- No impact on your main application
- Can test multiple applications
- Isolated testing environment

**Cons:**
- Limited to external HTTP testing
- No access to internal routes or middleware
- Authentication may be more complex

## 📈 Performance Optimization

### Recommended Settings

#### For Small Applications (< 1000 users)
```bash
LOAD_TESTING_CONCURRENT_USERS=50
LOAD_TESTING_DURATION=300
LOAD_TESTING_ASYNC_MODE=true
LOAD_TESTING_CONNECTION_POOLING=true
```

#### For Medium Applications (1000-10000 users)
```bash
LOAD_TESTING_CONCURRENT_USERS=200
LOAD_TESTING_DURATION=600
LOAD_TESTING_ASYNC_MODE=true
LOAD_TESTING_CONNECTION_POOLING=true
LOAD_TESTING_KEEP_ALIVE=true
```

#### For Large Applications (> 10000 users)
```bash
LOAD_TESTING_CONCURRENT_USERS=500
LOAD_TESTING_DURATION=1800
LOAD_TESTING_ASYNC_MODE=true
LOAD_TESTING_CONNECTION_POOLING=true
LOAD_TESTING_KEEP_ALIVE=true
LOAD_TESTING_GZIP=true
```

### System Requirements by Load

| Concurrent Users | RAM Required | CPU Cores | Notes |
|------------------|--------------|-----------|-------|
| 1-50 | 512MB | 1 | Basic testing |
| 51-200 | 1GB | 2 | Medium load |
| 201-500 | 2GB | 4 | High load |
| 500+ | 4GB+ | 8+ | Enterprise load |

## 🔧 Production Deployment

### Process Manager (Supervisor)

For production WebSocket server:

```ini
[program:laravel-load-test-websocket]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/app/artisan load-test:dashboard-server
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/your/app/storage/logs/websocket.log
```

### Reverse Proxy (Nginx)

Configure Nginx for WebSocket:

```nginx
location /ws {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

### Firewall Configuration

```bash
# UFW
sudo ufw allow 8080

# iptables
sudo iptables -A INPUT -p tcp --dport 8080 -j ACCEPT
```

## 🐛 Troubleshooting

### Common Issues

#### 1. WebSocket Connection Failed
```bash
# Check if port is in use
lsof -i :8080

# Use different port
php artisan load-test:dashboard-server --port=9090
```

#### 2. Authentication Errors
```bash
# Validate configuration
php artisan load-test:config

# Check user exists
php artisan tinker
>>> User::where('email', 'test@example.com')->first()
```

#### 3. Memory Issues
```bash
# Increase PHP memory limit
php -d memory_limit=2G artisan load-testing:run

# Or in .env.loadtesting
LOAD_TESTING_MEMORY_LIMIT_MB=2048
```

#### 4. Permission Errors
```bash
# Fix storage permissions
chmod -R 755 storage/
chown -R www-data:www-data storage/
```

### Debug Mode

Enable debug logging:

```bash
LOAD_TESTING_DEBUG=true
LOAD_TESTING_LOG_QUERIES=true
```

## 📝 License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## 👨‍💻 Author

**Nikunj Kothiya**
- Email: nikunjkothiya401@gmail.com
- GitHub: [@nikunjkothiya](https://github.com/nikunjkothiya)

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📞 Support

If you encounter any issues or have questions:

1. Check the troubleshooting section above
2. Run `php artisan load-test:config` to validate your setup
3. Check the logs in `storage/logs/`
4. Create an issue on GitHub with detailed information

---

**Happy Load Testing! 🚀** 