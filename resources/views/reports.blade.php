<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Load Testing Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .reports-container {
            padding: 20px;
        }
        .card {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container reports-container">
        <h1 class="mb-4">Load Testing Reports</h1>
        
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Available Reports</span>
                        <a href="{{ route('load-testing.dashboard') }}" class="btn btn-sm btn-primary">Dashboard</a>
                    </div>
                    <div class="card-body">
                        @if(count($reports) > 0)
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Test ID</th>
                                        <th>Requests</th>
                                        <th>Duration (s)</th>
                                        <th>Avg Response (ms)</th>
                                        <th>Error Rate (%)</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($reports as $report)
                                    <tr>
                                        <td>{{ $report['date'] }}</td>
                                        <td>{{ $report['test_id'] }}</td>
                                        <td>{{ $report['requests'] }}</td>
                                        <td>{{ round($report['duration'], 2) }}</td>
                                        <td>{{ round($report['avg_response_time'], 2) }}</td>
                                        <td>{{ round($report['error_rate'], 2) }}</td>
                                        <td>
                                            <a href="{{ route('load-testing.report', ['testId' => $report['test_id']]) }}" class="btn btn-sm btn-info">View</a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <div class="alert alert-info">
                                No load test reports available. Run a load test first.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 