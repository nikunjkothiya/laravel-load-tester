<?php

namespace NikunjKothiya\LaravelLoadTesting\Commands;

use Illuminate\Console\Command;
use NikunjKothiya\LaravelLoadTesting\Services\DashboardWebSocket;
use NikunjKothiya\LaravelLoadTesting\Services\MetricsCollector;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory as ReactFactory;
use React\Socket\SocketServer;

class StartDashboardServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'load-test:dashboard-server 
                            {--port=8080 : Port to run the WebSocket server on}
                            {--host=0.0.0.0 : Host to bind the server to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the WebSocket server for real-time dashboard updates';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $port = $this->option('port');
        $host = $this->option('host');

        $this->info("Starting Load Testing Dashboard WebSocket Server...");
        $this->info("Server will be available at: ws://{$host}:{$port}");
        $this->info("Press Ctrl+C to stop the server");
        $this->info("");

        try {
            // Create event loop using Factory for better compatibility
            $loop = ReactFactory::create();

            // Create WebSocket handler
            $metricsCollector = app(MetricsCollector::class);
            $webSocketHandler = new DashboardWebSocket($metricsCollector, $loop);

            // Create WebSocket server
            $webSocketServer = new WsServer($webSocketHandler);

            // Create HTTP server to handle WebSocket upgrade
            $httpServer = new HttpServer($webSocketServer);

            // Create socket server
            $socketServer = new SocketServer("{$host}:{$port}", [], $loop);

            // Create IO server
            $server = new IoServer($httpServer, $socketServer, $loop);

            $this->info("✅ WebSocket server started successfully!");
            $this->info("📊 Dashboard clients can connect to: ws://{$host}:{$port}");
            $this->info("🔄 Broadcasting metrics every 1 second");
            $this->info("");

            // Start the event loop
            $loop->run();
        } catch (\Exception $e) {
            $this->error("Failed to start WebSocket server: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return 1;
        }

        return 0;
    }
}
