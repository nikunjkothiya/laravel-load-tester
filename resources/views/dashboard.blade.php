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
                        <div class="metric-value">{{ $summary['total_requests'] ?? 0 }}</div>
                        <div class="metric-label">Total Requests</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="metric-value">{{ $summary['avg_response_time'] ?? 0 }} ms</div>
                        <div class="metric-label">Avg Response Time</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="metric-value">{{ $summary['peak_memory'] ?? 0 }} MB</div>
                        <div class="metric-label">Peak Memory</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="metric-value">{{ $summary['peak_cpu'] ?? 0 }}%</div>
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
                                @foreach($routes_performance ?? [] as $route)
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
            // Response Time Chart
            const responseTimeCtx = document.getElementById('responseTimeChart').getContext('2d');
            new Chart(responseTimeCtx, {
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
                    }
                }
            });
            
            // Resources Chart
            const resourcesCtx = document.getElementById('resourcesChart').getContext('2d');
            new Chart(resourcesCtx, {
                type: 'line',
                data: {
                    labels: {!! json_encode($time_series['labels'] ?? []) !!},
                    datasets: [
                        {
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
                    }
                }
            });
            
            // Status Codes Chart
            const statusCodesCtx = document.getElementById('statusCodesChart').getContext('2d');
            new Chart(statusCodesCtx, {
                type: 'pie',
                data: {
                    labels: Object.keys({!! json_encode($status_codes ?? []) !!}),
                    datasets: [{
                        data: Object.values({!! json_encode($status_codes ?? []) !!}),
                        backgroundColor: [
                            'rgb(75, 192, 192)',
                            'rgb(255, 205, 86)',
                            'rgb(255, 99, 132)',
                            'rgb(54, 162, 235)',
                            'rgb(153, 102, 255)'
                        ]
                    }]
                }
            });
        });
    </script>
</body>
</html> 