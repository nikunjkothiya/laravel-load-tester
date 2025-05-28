<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Load Testing Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .dashboard-container {
      padding: 20px;
    }

    .card {
      margin-bottom: 20px;
    }

    .metric-value {
      font-size: 2rem;
      font-weight: bold;
    }

    .metric-label {
      font-size: 1rem;
      color: #6c757d;
    }
  </style>
</head>

<body>
  <div class="container dashboard-container">
    <h1 class="mb-4">Load Testing Dashboard</h1>

    <div class="row">
      <div class="col-md-3">
        <div class="card">
          <div class="card-body text-center">
            <div class="metric-value" data-metric="total_requests">{{ $summary['total_requests'] ?? 0 }}</div>
            <div class="metric-label">Total Requests</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card">
          <div class="card-body text-center">
            <div class="metric-value" data-metric="avg_response_time">{{ $summary['avg_response_time'] ?? 0 }} ms</div>
            <div class="metric-label">Avg Response Time</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card">
          <div class="card-body text-center">
            <div class="metric-value" data-metric="peak_memory">{{ $summary['peak_memory'] ?? 0 }} MB</div>
            <div class="metric-label">Peak Memory</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card">
          <div class="card-body text-center">
            <div class="metric-value" data-metric="peak_cpu">{{ $summary['peak_cpu'] ?? 0 }}%</div>
            <div class="metric-label">Peak CPU</div>
          </div>
        </div>
      </div>
    </div>

    <div class="row mt-4">
      <div class="col-md-6">
        <div class="card">
          <div class="card-header">
            Response Time Over Time
          </div>
          <div class="card-body">
            <canvas id="responseTimeChart"></canvas>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card">
          <div class="card-header">
            Memory & CPU Usage
          </div>
          <div class="card-body">
            <canvas id="resourcesChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="row mt-4">
      <div class="col-12">
        <div class="card">
          <div class="card-header">
            Response Status Codes
          </div>
          <div class="card-body">
            <canvas id="statusCodesChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="row mt-4">
      <div class="col-12">
        <div class="card">
          <div class="card-header">
            Routes Performance
          </div>
          <div class="card-body">
            <table class="table table-striped">
              <thead>
                <tr>
                  <th>Route</th>
                  <th>Method</th>
                  <th>Requests</th>
                  <th>Avg Response Time (ms)</th>
                  <th>Min (ms)</th>
                  <th>Max (ms)</th>
                  <th>Error Rate (%)</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($routes_performance ?? [] as $route)
                  <tr>
                    <td>{{ $route['path'] }}</td>
                    <td>{{ $route['method'] }}</td>
                    <td>{{ $route['requests'] }}</td>
                    <td>{{ $route['avg_response_time'] }}</td>
                    <td>{{ $route['min_response_time'] }}</td>
                    <td>{{ $route['max_response_time'] }}</td>
                    <td>{{ $route['error_rate'] }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Initialize charts
      let responseTimeChart, resourcesChart, statusCodesChart;

      // Response Time Chart
      const responseTimeCtx = document.getElementById('responseTimeChart').getContext('2d');
      responseTimeChart = new Chart(responseTimeCtx, {
        type: 'line',
        data: {
          labels: {!! json_encode($time_series['labels'] ?? []) !!},
          datasets: [{
            label: 'Response Time (ms)',
            data: {!! json_encode($time_series['response_times'] ?? []) !!},
            borderColor: 'rgb(75, 192, 192)',
            tension: 0.1
          }]
        },
        options: {
          scales: {
            y: {
              beginAtZero: true
            }
          },
          animation: {
            duration: 0 // Disable animation for real-time updates
          }
        }
      });

      // Resources Chart
      const resourcesCtx = document.getElementById('resourcesChart').getContext('2d');
      resourcesChart = new Chart(resourcesCtx, {
        type: 'line',
        data: {
          labels: {!! json_encode($time_series['labels'] ?? []) !!},
          datasets: [{
              label: 'Memory (MB)',
              data: {!! json_encode($time_series['memory'] ?? []) !!},
              borderColor: 'rgb(54, 162, 235)',
              yAxisID: 'y'
            },
            {
              label: 'CPU (%)',
              data: {!! json_encode($time_series['cpu'] ?? []) !!},
              borderColor: 'rgb(255, 99, 132)',
              yAxisID: 'y1'
            }
          ]
        },
        options: {
          scales: {
            y: {
              type: 'linear',
              display: true,
              position: 'left',
              title: {
                display: true,
                text: 'Memory (MB)'
              }
            },
            y1: {
              type: 'linear',
              display: true,
              position: 'right',
              title: {
                display: true,
                text: 'CPU (%)'
              },
              grid: {
                drawOnChartArea: false
              }
            }
          },
          animation: {
            duration: 0 // Disable animation for real-time updates
          }
        }
      });

      // Status Codes Chart
      const statusCodesCtx = document.getElementById('statusCodesChart').getContext('2d');
      statusCodesChart = new Chart(statusCodesCtx, {
        type: 'pie',
        data: {
          labels: Object.keys({!! json_encode($summary['status_codes'] ?? []) !!}),
          datasets: [{
            data: Object.values({!! json_encode($summary['status_codes'] ?? []) !!}),
            backgroundColor: [
              'rgb(75, 192, 192)',
              'rgb(255, 205, 86)',
              'rgb(255, 99, 132)',
              'rgb(54, 162, 235)',
              'rgb(153, 102, 255)'
            ]
          }]
        },
        options: {
          animation: {
            duration: 0 // Disable animation for real-time updates
          }
        }
      });

      // WebSocket connection for real-time updates
      let websocket = null;
      let reconnectInterval = null;
      let isConnected = false;

      function connectWebSocket() {
        const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const wsHost = window.location.hostname;
        const wsPort = 8080; // Default WebSocket port
        const wsUrl = `${wsProtocol}//${wsHost}:${wsPort}`;

        try {
          websocket = new WebSocket(wsUrl);

          websocket.onopen = function(event) {
            console.log('WebSocket connected');
            isConnected = true;
            showConnectionStatus('connected');

            // Clear reconnect interval if it exists
            if (reconnectInterval) {
              clearInterval(reconnectInterval);
              reconnectInterval = null;
            }
          };

          websocket.onmessage = function(event) {
            try {
              const data = JSON.parse(event.data);
              handleWebSocketMessage(data);
            } catch (e) {
              console.error('Error parsing WebSocket message:', e);
            }
          };

          websocket.onclose = function(event) {
            console.log('WebSocket disconnected');
            isConnected = false;
            showConnectionStatus('disconnected');

            // Attempt to reconnect every 5 seconds
            if (!reconnectInterval) {
              reconnectInterval = setInterval(connectWebSocket, 5000);
            }
          };

          websocket.onerror = function(error) {
            console.error('WebSocket error:', error);
            showConnectionStatus('error');
          };

        } catch (e) {
          console.error('Failed to create WebSocket connection:', e);
          showConnectionStatus('error');
        }
      }

      function handleWebSocketMessage(data) {
        switch (data.type) {
          case 'metrics':
          case 'initial_metrics':
            updateDashboard(data.data);
            break;
          case 'notification':
            showNotification(data.message, data.level);
            break;
          case 'test_history':
            // Handle test history if needed
            break;
        }
      }

      function updateDashboard(metrics) {
        // Update summary metrics
        updateSummaryMetrics(metrics);

        // Update charts
        updateCharts(metrics);
      }

      function updateSummaryMetrics(metrics) {
        // Update metric values in the dashboard
        const elements = {
          'total_requests': metrics.total_requests || 0,
          'avg_response_time': Math.round(metrics.avg_response_time || 0),
          'peak_memory': Math.round(metrics.peak_memory || 0),
          'peak_cpu': Math.round(metrics.peak_cpu || 0)
        };

        Object.keys(elements).forEach(key => {
          const element = document.querySelector(`[data-metric="${key}"]`);
          if (element) {
            element.textContent = elements[key] + (key.includes('time') ? ' ms' : key.includes('memory') ? ' MB' : key.includes('cpu') ? '%' : '');
          }
        });
      }

      function updateCharts(metrics) {
        // Update response time chart
        if (metrics.response_times && responseTimeChart) {
          const labels = metrics.response_times.map((_, index) => index);
          responseTimeChart.data.labels = labels.slice(-50); // Keep last 50 points
          responseTimeChart.data.datasets[0].data = metrics.response_times.slice(-50);
          responseTimeChart.update('none');
        }

        // Update resources chart
        if (metrics.memory_usage && metrics.cpu_usage && resourcesChart) {
          const labels = metrics.memory_usage.map((_, index) => index);
          resourcesChart.data.labels = labels.slice(-50);
          resourcesChart.data.datasets[0].data = metrics.memory_usage.slice(-50);
          resourcesChart.data.datasets[1].data = metrics.cpu_usage.slice(-50);
          resourcesChart.update('none');
        }

        // Update status codes chart
        if (metrics.status_codes && statusCodesChart) {
          statusCodesChart.data.labels = Object.keys(metrics.status_codes);
          statusCodesChart.data.datasets[0].data = Object.values(metrics.status_codes);
          statusCodesChart.update('none');
        }
      }

      function showConnectionStatus(status) {
        // Create or update connection status indicator
        let statusElement = document.getElementById('ws-status');
        if (!statusElement) {
          statusElement = document.createElement('div');
          statusElement.id = 'ws-status';
          statusElement.style.cssText = 'position: fixed; top: 10px; right: 10px; padding: 8px 12px; border-radius: 4px; color: white; font-size: 12px; z-index: 1000;';
          document.body.appendChild(statusElement);
        }

        switch (status) {
          case 'connected':
            statusElement.textContent = '🟢 Live Updates';
            statusElement.style.backgroundColor = '#28a745';
            break;
          case 'disconnected':
            statusElement.textContent = '🔴 Disconnected';
            statusElement.style.backgroundColor = '#dc3545';
            break;
          case 'error':
            statusElement.textContent = '⚠️ Connection Error';
            statusElement.style.backgroundColor = '#ffc107';
            break;
        }
      }

      function showNotification(message, level = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.style.cssText = `
                    position: fixed; 
                    top: 50px; 
                    right: 10px; 
                    padding: 12px 16px; 
                    border-radius: 4px; 
                    color: white; 
                    font-size: 14px; 
                    z-index: 1001;
                    max-width: 300px;
                `;

        switch (level) {
          case 'success':
            notification.style.backgroundColor = '#28a745';
            break;
          case 'warning':
            notification.style.backgroundColor = '#ffc107';
            break;
          case 'error':
            notification.style.backgroundColor = '#dc3545';
            break;
          default:
            notification.style.backgroundColor = '#17a2b8';
        }

        notification.textContent = message;
        document.body.appendChild(notification);

        // Remove notification after 5 seconds
        setTimeout(() => {
          if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
          }
        }, 5000);
      }

      // Initialize WebSocket connection
      connectWebSocket();

      // Add data attributes to metric elements for easy updating
      document.addEventListener('DOMContentLoaded', function() {
        const metricElements = document.querySelectorAll('.metric-value');
        metricElements.forEach((element, index) => {
          const labels = ['total_requests', 'avg_response_time', 'peak_memory', 'peak_cpu'];
          if (labels[index]) {
            element.setAttribute('data-metric', labels[index]);
          }
        });
      });
    });
  </script>
</body>

</html>
