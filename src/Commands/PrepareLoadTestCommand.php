<?php

namespace NikunjKothiya\LaravelLoadTesting\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use NikunjKothiya\LaravelLoadTesting\Services\LoadTestingService;

class PrepareLoadTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'load-testing:prepare
                            {--users=50 : Number of test users to create}
                            {--table=users : The authentication table name}
                            {--username-field=email : The username field in the authentication table}
                            {--password-field=password : The password field in the authentication table}
                            {--auth-method= : Authentication method (session, token, jwt, sanctum, passport, custom)}
                            {--setup-database : Set up the results database table}
                            {--db-monitoring : Enable database query monitoring}
                            {--db-slow-threshold=100 : Threshold in ms to consider a query as slow}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prepare the environment for load testing';

    /**
     * Execute the console command.
     *
     * @param LoadTestingService $loadTestingService
     * @return int
     */
    public function handle(LoadTestingService $loadTestingService)
    {
        $this->info('Preparing load testing environment...');
        
        // Apply command line options to configuration
        $this->applyCommandOptions();
        
        // Create the results directory if it doesn't exist
        $this->createResultsDirectory();
        
        // Set up the database if requested
        if ($this->option('setup-database')) {
            $this->setupDatabase();
        }
        
        // Create test users if authentication is enabled
        if (config('load-testing.auth.enabled')) {
            $this->createTestUsers();
        }
        
        $this->info('Load testing environment prepared successfully!');
        
        return 0;
    }
    
    /**
     * Apply command line options to the configuration.
     */
    protected function applyCommandOptions()
    {
        // Set test users count
        if ($this->option('users')) {
            config(['load-testing.test.concurrent_users' => (int) $this->option('users')]);
        }
        
        // Set authentication table
        if ($this->option('table')) {
            config(['load-testing.auth.table' => $this->option('table')]);
        }
        
        // Set username field
        if ($this->option('username-field')) {
            config(['load-testing.auth.session.username_field' => $this->option('username-field')]);
            config(['load-testing.auth.token.username_field' => $this->option('username-field')]);
            config(['load-testing.auth.jwt.username_field' => $this->option('username-field')]);
        }
        
        // Set password field
        if ($this->option('password-field')) {
            config(['load-testing.auth.session.password_field' => $this->option('password-field')]);
            config(['load-testing.auth.token.password_field' => $this->option('password-field')]);
            config(['load-testing.auth.jwt.password_field' => $this->option('password-field')]);
        }
        
        // Set authentication method
        if ($this->option('auth-method')) {
            config(['load-testing.auth.method' => $this->option('auth-method')]);
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
     * Create the results directory.
     */
    protected function createResultsDirectory()
    {
        $outputDir = config('load-testing.reporting.output_dir');
        $outputPath = storage_path($outputDir);
        
        if (!File::exists($outputPath)) {
            File::makeDirectory($outputPath, 0755, true);
            $this->info("Created results directory: {$outputPath}");
        } else {
            $this->info("Results directory already exists: {$outputPath}");
        }
    }
    
    /**
     * Set up the database for storing test results.
     */
    protected function setupDatabase()
    {
        $tableName = config('load-testing.monitoring.results_table');
        
        if (Schema::hasTable($tableName)) {
            if ($this->confirm("The table '{$tableName}' already exists. Do you want to drop it and recreate?")) {
                Schema::drop($tableName);
                $this->info("Dropped existing table: {$tableName}");
            } else {
                $this->info("Using existing table: {$tableName}");
                return;
            }
        }
        
        // Create the results table
        Schema::create($tableName, function ($table) {
            $table->id();
            $table->timestamp('test_date');
            $table->integer('concurrent_users');
            $table->integer('total_requests');
            $table->float('avg_response_time');
            $table->float('min_response_time');
            $table->float('max_response_time');
            $table->integer('successful_requests');
            $table->integer('error_requests');
            $table->float('error_rate');
            $table->float('peak_memory');
            $table->float('peak_cpu');
            $table->float('avg_memory');
            $table->float('avg_cpu');
            $table->float('duration');
            $table->json('status_codes')->nullable();
            $table->json('time_series')->nullable();
            $table->json('routes_performance')->nullable();
            $table->timestamps();
        });
        
        $this->info("Created database table: {$tableName}");
    }
    
    /**
     * Create test users for authentication testing.
     */
    protected function createTestUsers()
    {
        $table = config('load-testing.auth.table');
        $concurrentUsers = config('load-testing.test.concurrent_users');
        $usernameField = config('load-testing.auth.session.username_field');
        $passwordField = config('load-testing.auth.session.password_field');
        $passwordHash = config('load-testing.auth.credentials.password_hash');
        
        // Check if table exists
        if (!Schema::hasTable($table)) {
            $this->error("The table '{$table}' does not exist. Cannot create test users.");
            return;
        }
        
        // Check if the required fields exist
        if (!Schema::hasColumn($table, $usernameField) || !Schema::hasColumn($table, $passwordField)) {
            $this->error("The table '{$table}' does not have the required fields ({$usernameField}, {$passwordField}).");
            return;
        }
        
        // Check if test users already exist
        $existingUsers = DB::table($table)
            ->where($usernameField, 'like', 'loadtest_%')
            ->count();
        
        if ($existingUsers > 0) {
            if ($this->confirm("Found {$existingUsers} existing test users. Do you want to remove them and create new ones?")) {
                // Delete existing test users
                DB::table($table)
                    ->where($usernameField, 'like', 'loadtest_%')
                    ->delete();
                $this->info("Deleted {$existingUsers} existing test users.");
                $existingUsers = 0;
            } else {
                $this->info("Using existing test users.");
                
                if ($existingUsers >= $concurrentUsers) {
                    $this->info("You have enough test users ({$existingUsers}) for your concurrent users setting ({$concurrentUsers}).");
                    return;
                } else {
                    $this->info("You need more test users. Creating " . ($concurrentUsers - $existingUsers) . " additional users.");
                }
            }
        }
        
        // Create test users
        $usersToCreate = $concurrentUsers - $existingUsers;
        $progressBar = $this->output->createProgressBar($usersToCreate);
        $progressBar->start();
        
        for ($i = 0; $i < $usersToCreate; $i++) {
            $userData = [
                $usernameField => 'loadtest_' . uniqid(),
                $passwordField => $passwordHash,
                'name' => 'Load Test User ' . ($existingUsers + $i + 1),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            
            DB::table($table)->insert($userData);
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine();
        $this->info("Created {$usersToCreate} test users.");
    }
} 