<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use PDO;

class DatabaseManager
{
    /**
     * @var array Configuration
     */
    protected $config;
    
    /**
     * @var string Snapshot identifier
     */
    protected $snapshotId;
    
    /**
     * @var array Database performance metrics
     */
    protected $metrics = [
        'slow_queries' => [],
        'deadlocks' => 0,
        'connections' => 0,
        'max_connections' => 0,
    ];
    
    /**
     * Create a new DatabaseManager instance
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Set up the database for load testing
     *
     * @return bool Success status
     */
    public function prepare(): bool
    {
        // Configure database connection options
        $this->configureConnectionPool();
        
        // Create a snapshot if configured
        if ($this->config['database']['create_snapshots'] ?? false) {
            $this->createSnapshot();
        }
        
        // Use a test database if configured
        if ($this->config['database']['use_test_database'] ?? false) {
            $this->useTestDatabase();
        }
        
        return true;
    }
    
    /**
     * Configure database connection pool
     *
     * @return void
     */
    protected function configureConnectionPool(): void
    {
        // Get default connection
        $connection = DB::connection()->getPdo();
        
        // Set PDO attributes for better performance
        $connection->setAttribute(PDO::ATTR_PERSISTENT, true);
        $connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        
        // Get max connections setting
        $maxConnections = $this->config['database']['pool']['max_connections'] ?? 100;
        
        // Set MySQL connection variables
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            try {
                // Optimize MySQL settings for load testing
                DB::statement("SET SESSION innodb_flush_log_at_trx_commit = 2");
                DB::statement("SET SESSION sync_binlog = 0");
                DB::statement("SET SESSION max_connections = {$maxConnections}");
            } catch (\Exception $e) {
                Log::warning("Could not set MySQL performance settings: " . $e->getMessage());
            }
        }
        
        // Log the configuration
        Log::info("Database connection pool configured with max_connections: {$maxConnections}");
    }
    
    /**
     * Create a database snapshot before testing
     *
     * @return string Snapshot ID
     */
    public function createSnapshot(): string
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        
        // Generate a snapshot ID
        $snapshotId = 'load_test_' . time() . '_' . uniqid();
        
        switch ($driver) {
            case 'mysql':
                return $this->createMySQLSnapshot($snapshotId);
            
            case 'pgsql':
                return $this->createPostgresSnapshot($snapshotId);
            
            case 'sqlite':
                return $this->createSQLiteSnapshot($snapshotId);
            
            default:
                throw new \Exception("Database driver {$driver} does not support snapshots");
        }
    }
    
    /**
     * Restore the database from a snapshot
     *
     * @param string|null $snapshotId Snapshot ID to restore (default: last created)
     * @return bool Success status
     */
    public function restoreSnapshot(?string $snapshotId = null): bool
    {
        $snapshotId = $snapshotId ?? $this->snapshotId;
        
        if (!$snapshotId) {
            Log::warning("No snapshot ID provided or created to restore from.");
            return false;
        }
        
        $dbName = DB::connection()->getDatabaseName();
        $snapshotName = $dbName . '_' . $snapshotId;
        
        try {
            // For MySQL, restore from the snapshot database
            if (DB::connection()->getDriverName() === 'mysql') {
                // Check if the snapshot database exists
                $exists = DB::select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$snapshotName}'");
                
                if (!empty($exists)) {
                    // Drop all tables in the current database
                    $tables = DB::select('SHOW TABLES');
                    
                    // Disable foreign key checks
                    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
                    
                    foreach ($tables as $table) {
                        $tableName = array_values((array) $table)[0];
                        DB::statement("DROP TABLE IF EXISTS {$tableName}");
                    }
                    
                    // Copy tables from snapshot to current database
                    DB::statement("USE {$snapshotName}");
                    $snapshotTables = DB::select('SHOW TABLES');
                    
                    foreach ($snapshotTables as $table) {
                        $tableName = array_values((array) $table)[0];
                        
                        // Create the table in the main database
                        $createTableSql = DB::select("SHOW CREATE TABLE {$tableName}")[0]->{'Create Table'};
                        DB::statement("USE {$dbName}");
                        DB::statement($createTableSql);
                    }
                    
                    // Re-enable foreign key checks
                    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
                    DB::statement("USE {$dbName}");
                    
                    Log::info("Restored database from snapshot: {$snapshotName}");
                    return true;
                } else {
                    // Try file-based snapshot restore
                    $snapshotDir = storage_path('load-testing/snapshots/' . $snapshotId);
                    
                    if (File::exists($snapshotDir)) {
                        // Disable foreign key checks
                        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
                        
                        // Restore from file-based snapshot
                        $structureFiles = File::glob($snapshotDir . '/*_structure.sql');
                        
                        foreach ($structureFiles as $file) {
                            $sql = File::get($file);
                            DB::statement($sql);
                            
                            // Get table name from filename
                            $tableName = str_replace('_structure.sql', '', basename($file));
                            
                            // Restore data if available
                            $dataFile = $snapshotDir . '/' . $tableName . '_data.json';
                            if (File::exists($dataFile)) {
                                $data = json_decode(File::get($dataFile), true);
                                
                                if (is_array($data) && !empty($data)) {
                                    foreach ($data as $row) {
                                        DB::table($tableName)->insert((array) $row);
                                    }
                                }
                            }
                        }
                        
                        // Re-enable foreign key checks
                        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
                        
                        Log::info("Restored database from file-based snapshot: {$snapshotId}");
                        return true;
                    }
                }
            } else {
                // For other database types
                Log::warning("Database restore for " . DB::connection()->getDriverName() . " is not fully implemented.");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Error restoring database snapshot: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Switch to a test database for load testing
     *
     * @return bool Success status
     */
    public function useTestDatabase(): bool
    {
        $testDbName = $this->config['database']['test_database_name'] ?? null;
        
        if (!$testDbName) {
            Log::warning("No test database name configured.");
            return false;
        }
        
        try {
            // Check if the test database exists
            $exists = DB::select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$testDbName}'");
            
            if (empty($exists)) {
                // Create the test database
                DB::statement("CREATE DATABASE {$testDbName}");
                Log::info("Created test database: {$testDbName}");
            }
            
            // Change the database connection to use the test database
            config(['database.connections.' . DB::getDefaultConnection() . '.database' => $testDbName]);
            DB::purge(DB::getDefaultConnection());
            DB::reconnect();
            
            Log::info("Switched to test database: {$testDbName}");
            return true;
        } catch (\Exception $e) {
            Log::error("Error switching to test database: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Monitor database performance during load testing
     *
     * @return array Performance metrics
     */
    public function monitorPerformance(): array
    {
        try {
            // For MySQL, get performance metrics
            if (DB::connection()->getDriverName() === 'mysql') {
                // Get status variables
                $statusVars = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Max_used_connections', 'Slow_queries', 'Innodb_deadlocks')");
                
                // Process results
                foreach ($statusVars as $var) {
                    switch ($var->Variable_name) {
                        case 'Threads_connected':
                            $this->metrics['connections'] = (int) $var->Value;
                            break;
                        case 'Max_used_connections':
                            $this->metrics['max_connections'] = (int) $var->Value;
                            break;
                        case 'Slow_queries':
                            $this->metrics['total_slow_queries'] = (int) $var->Value;
                            break;
                        case 'Innodb_deadlocks':
                            $this->metrics['deadlocks'] = (int) $var->Value;
                            break;
                    }
                }
                
                // Get slow queries if possible
                try {
                    $slowQueries = DB::select("SELECT * FROM mysql.slow_log ORDER BY start_time DESC LIMIT 10");
                    $this->metrics['slow_queries'] = $slowQueries;
                } catch (\Exception $e) {
                    // Slow query log might not be accessible
                    Log::warning("Could not access slow query log: " . $e->getMessage());
                }
                
                // Check if query logging is enabled
                if ($this->config['monitoring']['database']['enabled'] ?? false) {
                    // Get the query log from the connection
                    $this->metrics['logged_queries'] = DB::getQueryLog();
                }
            }
        } catch (\Exception $e) {
            Log::error("Error monitoring database performance: " . $e->getMessage());
        }
        
        return $this->metrics;
    }
    
    /**
     * Optimize database connection for load testing
     *
     * @return void
     */
    public function optimizeForLoadTesting(): void
    {
        // Configure MySQL for load testing if applicable
        if (DB::connection()->getDriverName() === 'mysql') {
            try {
                // Disable query cache for testing
                DB::statement("SET SESSION query_cache_type = OFF");
                
                // Set InnoDB flush log at transaction commit for better performance
                DB::statement("SET SESSION innodb_flush_log_at_trx_commit = 2");
                
                // Disable binary logging for testing
                DB::statement("SET SESSION sync_binlog = 0");
                
                // Increase temp table size
                DB::statement("SET SESSION tmp_table_size = 64M");
                DB::statement("SET SESSION max_heap_table_size = 64M");
                
                Log::info("Database optimized for load testing");
            } catch (\Exception $e) {
                Log::warning("Could not optimize database for load testing: " . $e->getMessage());
            }
        }
    }

    /**
     * Create a MySQL database snapshot.
     *
     * @param string $snapshotId
     * @return string
     * @throws \Exception
     */
    protected function createMySQLSnapshot(string $snapshotId): string
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $username = config("database.connections.{$connection}.username");
        $password = config("database.connections.{$connection}.password");
        $host = config("database.connections.{$connection}.host");
        
        $backupFile = storage_path("load-testing/db_snapshots/{$snapshotId}.sql");
        
        // Create directory if it doesn't exist
        if (!file_exists(dirname($backupFile))) {
            mkdir(dirname($backupFile), 0755, true);
        }
        
        // Create the backup using mysqldump
        $command = "mysqldump -h{$host} -u{$username} -p{$password} {$database} > {$backupFile}";
        
        // We'll use exec for simplicity, but ideally would use a more robust solution
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new \Exception("Failed to create MySQL snapshot: " . implode("\n", $output));
        }
        
        return $snapshotId;
    }

    /**
     * Restore a MySQL database snapshot.
     *
     * @param string $snapshotId
     * @return void
     * @throws \Exception
     */
    protected function restoreMySQLSnapshot(string $snapshotId): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $username = config("database.connections.{$connection}.username");
        $password = config("database.connections.{$connection}.password");
        $host = config("database.connections.{$connection}.host");
        
        $backupFile = storage_path("load-testing/db_snapshots/{$snapshotId}.sql");
        
        if (!file_exists($backupFile)) {
            throw new \Exception("Snapshot file not found: {$backupFile}");
        }
        
        // Restore the backup
        $command = "mysql -h{$host} -u{$username} -p{$password} {$database} < {$backupFile}";
        
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new \Exception("Failed to restore MySQL snapshot: " . implode("\n", $output));
        }
    }

    /**
     * Create a PostgreSQL database snapshot.
     *
     * @param string $snapshotId
     * @return string
     * @throws \Exception
     */
    protected function createPostgresSnapshot(string $snapshotId): string
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $username = config("database.connections.{$connection}.username");
        $password = config("database.connections.{$connection}.password");
        $host = config("database.connections.{$connection}.host");
        
        $backupFile = storage_path("load-testing/db_snapshots/{$snapshotId}.sql");
        
        // Create directory if it doesn't exist
        if (!file_exists(dirname($backupFile))) {
            mkdir(dirname($backupFile), 0755, true);
        }
        
        // Set password environment variable for pg_dump
        putenv("PGPASSWORD={$password}");
        
        // Create the backup using pg_dump
        $command = "pg_dump -h {$host} -U {$username} {$database} > {$backupFile}";
        
        exec($command, $output, $returnVar);
        
        // Reset environment variable
        putenv("PGPASSWORD");
        
        if ($returnVar !== 0) {
            throw new \Exception("Failed to create PostgreSQL snapshot: " . implode("\n", $output));
        }
        
        return $snapshotId;
    }

    /**
     * Restore a PostgreSQL database snapshot.
     *
     * @param string $snapshotId
     * @return void
     * @throws \Exception
     */
    protected function restorePostgresSnapshot(string $snapshotId): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $username = config("database.connections.{$connection}.username");
        $password = config("database.connections.{$connection}.password");
        $host = config("database.connections.{$connection}.host");
        
        $backupFile = storage_path("load-testing/db_snapshots/{$snapshotId}.sql");
        
        if (!file_exists($backupFile)) {
            throw new \Exception("Snapshot file not found: {$backupFile}");
        }
        
        // Set password environment variable for psql
        putenv("PGPASSWORD={$password}");
        
        // Drop all tables and recreate from backup
        $command = "psql -h {$host} -U {$username} -d {$database} -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;' && psql -h {$host} -U {$username} -d {$database} -f {$backupFile}";
        
        exec($command, $output, $returnVar);
        
        // Reset environment variable
        putenv("PGPASSWORD");
        
        if ($returnVar !== 0) {
            throw new \Exception("Failed to restore PostgreSQL snapshot: " . implode("\n", $output));
        }
    }

    /**
     * Create a SQLite database snapshot.
     *
     * @param string $snapshotId
     * @return string
     * @throws \Exception
     */
    protected function createSQLiteSnapshot(string $snapshotId): string
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        
        $backupFile = storage_path("load-testing/db_snapshots/{$snapshotId}.sqlite");
        
        // Create directory if it doesn't exist
        if (!file_exists(dirname($backupFile))) {
            mkdir(dirname($backupFile), 0755, true);
        }
        
        // Simply copy the SQLite file
        if (!copy($database, $backupFile)) {
            throw new \Exception("Failed to create SQLite snapshot");
        }
        
        return $snapshotId;
    }

    /**
     * Restore a SQLite database snapshot.
     *
     * @param string $snapshotId
     * @return void
     * @throws \Exception
     */
    protected function restoreSQLiteSnapshot(string $snapshotId): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        
        $backupFile = storage_path("load-testing/db_snapshots/{$snapshotId}.sqlite");
        
        if (!file_exists($backupFile)) {
            throw new \Exception("Snapshot file not found: {$backupFile}");
        }
        
        // Close any open connections
        DB::disconnect();
        
        // Replace the database file
        if (!copy($backupFile, $database)) {
            throw new \Exception("Failed to restore SQLite snapshot");
        }
    }
} 