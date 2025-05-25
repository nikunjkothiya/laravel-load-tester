<?php

namespace NikunjKothiya\LaravelLoadTesting\Services;

use Illuminate\Support\Facades\Log;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use SplObjectStorage;

class DashboardWebSocket implements MessageComponentInterface
{
    /**
     * Connected clients
     *
     * @var \SplObjectStorage
     */
    protected $clients;
    
    /**
     * Metrics collector instance
     *
     * @var \NikunjKothiya\LaravelLoadTesting\Services\MetricsCollector
     */
    protected $metricsCollector;
    
    /**
     * Event loop
     *
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;
    
    /**
     * Whether a test is currently running
     *
     * @var bool
     */
    protected $testRunning = false;
    
    /**
     * Broadcast interval in seconds
     *
     * @var float
     */
    protected $broadcastInterval = 1.0;
    
    /**
     * Timer for periodic broadcasts
     *
     * @var \React\EventLoop\TimerInterface|null
     */
    protected $broadcastTimer;
    
    /**
     * Create a new WebSocket server instance.
     *
     * @param \NikunjKothiya\LaravelLoadTesting\Services\MetricsCollector $metricsCollector
     * @param \React\EventLoop\LoopInterface $loop
     */
    public function __construct(MetricsCollector $metricsCollector, LoopInterface $loop)
    {
        $this->clients = new SplObjectStorage();
        $this->metricsCollector = $metricsCollector;
        $this->loop = $loop;
    }
    
    /**
     * When a new connection is opened.
     *
     * @param \Ratchet\ConnectionInterface $conn
     * @return void
     */
    public function onOpen(ConnectionInterface $conn)
    {
        // Store the new connection
        $this->clients->attach($conn);
        
        Log::info("New dashboard connection: {$conn->resourceId}");
        
        // Send initial metrics to the new client
        $this->sendInitialMetrics($conn);
        
        // Start broadcasting if not already started
        $this->startBroadcasting();
    }
    
    /**
     * When a message is received from a client.
     *
     * @param \Ratchet\ConnectionInterface $from
     * @param string $msg
     * @return void
     */
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        
        if (!$data || !isset($data['action'])) {
            return;
        }
        
        // Handle client commands
        switch ($data['action']) {
            case 'start_test':
                $this->handleStartTest($data, $from);
                break;
                
            case 'stop_test':
                $this->handleStopTest($from);
                break;
                
            case 'get_history':
                $this->sendTestHistory($from);
                break;
        }
    }
    
    /**
     * When a connection is closed.
     *
     * @param \Ratchet\ConnectionInterface $conn
     * @return void
     */
    public function onClose(ConnectionInterface $conn)
    {
        // Remove the connection
        $this->clients->detach($conn);
        
        Log::info("Dashboard connection closed: {$conn->resourceId}");
        
        // If no more clients and test is not running, stop broadcasting
        if ($this->clients->count() == 0 && !$this->testRunning) {
            $this->stopBroadcasting();
        }
    }
    
    /**
     * When an error occurs.
     *
     * @param \Ratchet\ConnectionInterface $conn
     * @param \Exception $e
     * @return void
     */
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        Log::error("WebSocket error: {$e->getMessage()}");
        $conn->close();
    }
    
    /**
     * Broadcast metrics to all connected clients.
     *
     * @param array $metrics
     * @return void
     */
    public function broadcastMetrics(array $metrics = null)
    {
        if (empty($metrics)) {
            $metrics = $this->metricsCollector->getRealtimeMetrics();
        }
        
        $payload = json_encode([
            'type' => 'metrics',
            'data' => $metrics,
            'timestamp' => time()
        ]);
        
        foreach ($this->clients as $client) {
            try {
                $client->send($payload);
            } catch (\Exception $e) {
                Log::error("Error sending to client: {$e->getMessage()}");
            }
        }
    }
    
    /**
     * Send a notification to all connected clients.
     *
     * @param string $message
     * @param string $level
     * @return void
     */
    public function broadcastNotification(string $message, string $level = 'info')
    {
        $payload = json_encode([
            'type' => 'notification',
            'message' => $message,
            'level' => $level,
            'timestamp' => time()
        ]);
        
        foreach ($this->clients as $client) {
            try {
                $client->send($payload);
            } catch (\Exception $e) {
                Log::error("Error sending notification: {$e->getMessage()}");
            }
        }
    }
    
    /**
     * Send initial metrics data to a new client.
     *
     * @param \Ratchet\ConnectionInterface $conn
     * @return void
     */
    protected function sendInitialMetrics(ConnectionInterface $conn)
    {
        try {
            $metrics = $this->metricsCollector->getRealtimeMetrics();
            
            $payload = json_encode([
                'type' => 'initial_metrics',
                'data' => $metrics,
                'test_running' => $this->testRunning,
                'timestamp' => time()
            ]);
            
            $conn->send($payload);
        } catch (\Exception $e) {
            Log::error("Error sending initial metrics: {$e->getMessage()}");
        }
    }
    
    /**
     * Send test history to a client.
     *
     * @param \Ratchet\ConnectionInterface $conn
     * @return void
     */
    protected function sendTestHistory(ConnectionInterface $conn)
    {
        try {
            $history = $this->metricsCollector->getTestHistory();
            
            $payload = json_encode([
                'type' => 'test_history',
                'data' => $history,
                'timestamp' => time()
            ]);
            
            $conn->send($payload);
        } catch (\Exception $e) {
            Log::error("Error sending test history: {$e->getMessage()}");
        }
    }
    
    /**
     * Handle a client request to start a test.
     *
     * @param array $data
     * @param \Ratchet\ConnectionInterface $conn
     * @return void
     */
    protected function handleStartTest(array $data, ConnectionInterface $conn)
    {
        // This would integrate with your load testing service to start a test
        // For now, we'll just simulate it with a notification
        $this->testRunning = true;
        $this->broadcastNotification('Load test started');
        
        // Make sure broadcasting is active
        $this->startBroadcasting();
    }
    
    /**
     * Handle a client request to stop a test.
     *
     * @param \Ratchet\ConnectionInterface $conn
     * @return void
     */
    protected function handleStopTest(ConnectionInterface $conn)
    {
        // This would integrate with your load testing service to stop a test
        // For now, we'll just simulate it with a notification
        $this->testRunning = false;
        $this->broadcastNotification('Load test stopped');
        
        // If no clients, stop broadcasting
        if ($this->clients->count() == 0) {
            $this->stopBroadcasting();
        }
    }
    
    /**
     * Start the periodic broadcasting of metrics.
     *
     * @return void
     */
    protected function startBroadcasting()
    {
        if ($this->broadcastTimer) {
            return;
        }
        
        $this->broadcastTimer = $this->loop->addPeriodicTimer($this->broadcastInterval, function () {
            $this->broadcastMetrics();
        });
    }
    
    /**
     * Stop the periodic broadcasting of metrics.
     *
     * @return void
     */
    protected function stopBroadcasting()
    {
        if (!$this->broadcastTimer) {
            return;
        }
        
        $this->loop->cancelTimer($this->broadcastTimer);
        $this->broadcastTimer = null;
    }
    
    /**
     * Set the broadcast interval.
     *
     * @param float $interval
     * @return self
     */
    public function setBroadcastInterval(float $interval): self
    {
        $this->broadcastInterval = $interval;
        
        // Restart broadcasting if it's active
        if ($this->broadcastTimer) {
            $this->stopBroadcasting();
            $this->startBroadcasting();
        }
        
        return $this;
    }
    
    /**
     * Set the test running state.
     *
     * @param bool $running
     * @return self
     */
    public function setTestRunning(bool $running): self
    {
        $this->testRunning = $running;
        
        if ($running) {
            $this->startBroadcasting();
        } elseif ($this->clients->count() == 0) {
            $this->stopBroadcasting();
        }
        
        return $this;
    }
} 