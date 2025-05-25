<?php

namespace NikunjKothiya\LaravelLoadTesting\Commands;

use Illuminate\Console\Command;
use NikunjKothiya\LaravelLoadTesting\Services\LoadTestingService;

class RunLoadTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'load-testing:run
                            {--users= : Number of concurrent users}
                            {--duration= : Duration of the test in seconds}
                            {--ramp-up= : Ramp-up time in seconds}
                            {--auth : Enable authentication testing}
                            {--auth-method= : Authentication method (session, token, jwt, sanctum, passport, custom)}
                            {--url= : Base URL for testing}
                            {--include= : Routes to include (comma-separated)}
                            {--exclude= : Routes to exclude (comma-separated)}
                            {--db-monitoring : Enable database query monitoring}
                            {--db-slow-threshold= : Threshold in ms to consider a query as slow}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a load test on your Laravel application';

    /**
     * Execute the console command.
     *
     * @param LoadTestingService $loadTestingService
     * @return int
     */
    public function handle(LoadTestingService $loadTestingService)
    {
        // Apply command line options to configuration
        $this->applyCommandOptions();
        
        $this->info('Starting load testing...');
        
        // Prepare the environment
        $this->info('Preparing environment...');
        $loadTestingService->prepare();
        
        // Display test parameters
        $this->displayTestParameters();
        
        // Run the test
        $this->info('Running load test...');
        $this->output->progressStart(config('load-testing.test.duration'));
        
        $startTime = microtime(true);
        $results = $loadTestingService->run();
        $elapsedTime = microtime(true) - $startTime;
        
        $this->output->progressFinish();
        
        // Display results summary
        $this->displayResultsSummary($results);
        
        $this->info('Load test completed in ' . round($elapsedTime, 2) . ' seconds.');
        $this->info('Detailed reports are available in storage/load-testing/');
        
        return 0;
    }
    
    /**
     * Apply command line options to the configuration.
     */
    protected function applyCommandOptions()
    {
        // Update concurrent users
        if ($this->option('users')) {
            config(['load-testing.test.concurrent_users' => (int) $this->option('users')]);
        }
        
        // Update test duration
        if ($this->option('duration')) {
            config(['load-testing.test.duration' => (int) $this->option('duration')]);
        }
        
        // Update ramp-up time
        if ($this->option('ramp-up')) {
            config(['load-testing.test.ramp_up' => (int) $this->option('ramp-up')]);
        }
        
        // Enable authentication if requested
        if ($this->option('auth')) {
            config(['load-testing.auth.enabled' => true]);
        }
        
        // Set authentication method if provided
        if ($this->option('auth-method')) {
            config(['load-testing.auth.method' => $this->option('auth-method')]);
        }
        
        // Set base URL if provided
        if ($this->option('url')) {
            config(['load-testing.base_url' => $this->option('url')]);
        }
        
        // Set routes to include if provided
        if ($this->option('include')) {
            $includeRoutes = explode(',', $this->option('include'));
            config(['load-testing.routes.include' => $includeRoutes]);
        }
        
        // Set routes to exclude if provided
        if ($this->option('exclude')) {
            $excludeRoutes = explode(',', $this->option('exclude'));
            config(['load-testing.routes.exclude' => $excludeRoutes]);
        }
        
        // Enable database monitoring if requested
        if ($this->option('db-monitoring')) {
            config(['load-testing.monitoring.database.enabled' => true]);
        }
        
        // Set database slow query threshold if provided
        if ($this->option('db-slow-threshold')) {
            config(['load-testing.monitoring.database.slow_threshold' => (int) $this->option('db-slow-threshold')]);
        }
    }
    
    /**
     * Display test parameters.
     */
    protected function displayTestParameters()
    {
        $this->info('Test Parameters:');
        $this->table(
            ['Parameter', 'Value'],
            [
                ['Concurrent Users', config('load-testing.test.concurrent_users')],
                ['Duration', config('load-testing.test.duration') . ' seconds'],
                ['Ramp-up Time', config('load-testing.test.ramp_up') . ' seconds'],
                ['Authentication', config('load-testing.auth.enabled') ? 'Enabled' : 'Disabled'],
                ['Authentication Method', config('load-testing.auth.method')],
                ['Base URL', config('load-testing.base_url')],
                ['DB Query Monitoring', config('load-testing.monitoring.database.enabled') ? 'Enabled' : 'Disabled'],
                ['DB Slow Query Threshold', config('load-testing.monitoring.database.slow_threshold') . ' ms'],
            ]
        );
    }
    
    /**
     * Display a summary of the test results.
     */
    protected function displayResultsSummary($results)
    {
        if (!isset($results['summary'])) {
            $this->error('No results available.');
            return;
        }
        
        $summary = $results['summary'];
        
        $this->info('Test Results Summary:');
        $metrics = [
            ['Total Requests', $summary['total_requests']],
            ['Average Response Time', $summary['avg_response_time'] . ' ms'],
            ['Min Response Time', $summary['min_response_time'] . ' ms'],
            ['Max Response Time', $summary['max_response_time'] . ' ms'],
            ['Successful Requests', $summary['successful_requests']],
            ['Error Requests', $summary['error_requests']],
            ['Error Rate', $summary['error_rate'] . '%'],
            ['Peak Memory Usage', $summary['peak_memory'] . ' MB'],
            ['Peak CPU Usage', $summary['peak_cpu'] . '%'],
        ];
        
        // Add database metrics if available
        if (isset($summary['total_db_queries'])) {
            $metrics = array_merge($metrics, [
                ['Total DB Queries', $summary['total_db_queries']],
                ['Average Query Time', $summary['avg_query_time'] . ' ms'],
                ['Min Query Time', $summary['min_query_time'] . ' ms'],
                ['Max Query Time', $summary['max_query_time'] . ' ms'],
                ['Slow Queries', $summary['slow_queries_count'] . ' (>' . $summary['slow_threshold'] . ' ms)'],
            ]);
        }
        
        $this->table(['Metric', 'Value'], $metrics);
        
        // Display status code breakdown
        if (isset($results['status_codes']) && !empty($results['status_codes'])) {
            $this->info('Status Code Breakdown:');
            $statusCodeRows = [];
            
            foreach ($results['status_codes'] as $code => $count) {
                $statusCodeRows[] = [$code, $count];
            }
            
            $this->table(['Status Code', 'Count'], $statusCodeRows);
        }
        
        // Display database query type breakdown
        if (isset($results['database']['query_types']) && !empty($results['database']['query_types'])) {
            $this->info('Database Query Type Breakdown:');
            $queryTypeRows = [];
            
            foreach ($results['database']['query_types'] as $type => $count) {
                $queryTypeRows[] = [$type, $count];
            }
            
            $this->table(['Query Type', 'Count'], $queryTypeRows);
        }
        
        // Display top slow queries
        if (isset($results['database']['top_slow_queries']) && !empty($results['database']['top_slow_queries'])) {
            $this->info('Top 5 Slowest Queries:');
            $slowQueryRows = [];
            $counter = 0;
            
            foreach ($results['database']['top_slow_queries'] as $query) {
                // Limit to showing just the first 5 slow queries in the console
                if ($counter >= 5) break;
                
                // Truncate long queries for better display
                $sql = substr($query['sql'], 0, 100) . (strlen($query['sql']) > 100 ? '...' : '');
                $slowQueryRows[] = [$sql, $query['time'] . ' ms'];
                $counter++;
            }
            
            $this->table(['Query (truncated)', 'Time'], $slowQueryRows);
            $this->info('Full query details are available in the HTML/JSON reports.');
        }
    }
} 