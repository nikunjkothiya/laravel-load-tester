<?php

namespace NikunjKothiya\LaravelLoadTesting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use NikunjKothiya\LaravelLoadTesting\Services\MetricsCollector;

class DashboardController extends Controller
{
    /**
     * Display the load testing dashboard.
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Get the latest test report
        $reportPath = $this->getLatestReportPath();

        if (!$reportPath) {
            return view('load-testing::dashboard', [
                'no_data' => true,
                'summary' => [],
                'time_series' => [
                    'labels' => [],
                    'response_times' => [],
                    'memory' => [],
                    'cpu' => [],
                ],
                'routes_performance' => [],
            ]);
        }

        // Load the report data
        $reportData = json_decode(File::get($reportPath), true);

        // Process data for the charts
        $summary = $this->processReportSummary($reportData);
        $timeSeries = $this->processTimeSeriesData($reportData);
        $routesPerformance = $this->processRoutePerformance($reportData);

        return view('load-testing::dashboard', [
            'no_data' => false,
            'summary' => $summary,
            'time_series' => $timeSeries,
            'routes_performance' => $routesPerformance,
        ]);
    }

    /**
     * Display a specific test report.
     *
     * @param Request $request
     * @param string $testId
     * @return \Illuminate\View\View
     */
    public function show(Request $request, $testId)
    {
        $reportPath = $this->getReportPath($testId);

        if (!$reportPath) {
            return redirect()->route('load-testing.dashboard');
        }

        // Load the report data
        $reportData = json_decode(File::get($reportPath), true);

        // Process data for the charts
        $summary = $this->processReportSummary($reportData);
        $timeSeries = $this->processTimeSeriesData($reportData);
        $routesPerformance = $this->processRoutePerformance($reportData);

        return view('load-testing::dashboard', [
            'no_data' => false,
            'summary' => $summary,
            'time_series' => $timeSeries,
            'routes_performance' => $routesPerformance,
            'test_id' => $testId,
        ]);
    }

    /**
     * Display real-time metrics for an ongoing test.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function realtime(Request $request, MetricsCollector $metricsCollector)
    {
        $metrics = $metricsCollector->getRealtimeMetrics();

        return response()->json($metrics);
    }

    /**
     * List all available test reports.
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function list(Request $request)
    {
        $reports = $this->getAllReports();

        return view('load-testing::reports', [
            'reports' => $reports,
        ]);
    }

    /**
     * Get the latest report file path.
     *
     * @return string|null
     */
    protected function getLatestReportPath()
    {
        $storagePath = config('load-testing.reporting.output_path', 'load-testing');
        $fullPath = storage_path($storagePath);

        if (!File::exists($fullPath)) {
            return null;
        }

        $reports = File::glob($fullPath . '/summary_*.json');

        if (empty($reports)) {
            return null;
        }

        // Sort by modification time (newest first)
        usort($reports, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $reports[0];
    }

    /**
     * Get a specific report file path.
     *
     * @param string $testId
     * @return string|null
     */
    protected function getReportPath($testId)
    {
        $storagePath = config('load-testing.reporting.output_path', 'load-testing');
        $fullPath = storage_path($storagePath);
        $reportPath = $fullPath . '/summary_' . $testId . '.json';

        if (!File::exists($reportPath)) {
            return null;
        }

        return $reportPath;
    }

    /**
     * Get all available reports.
     *
     * @return array
     */
    protected function getAllReports()
    {
        $storagePath = config('load-testing.reporting.output_path', 'load-testing');
        $fullPath = storage_path($storagePath);

        if (!File::exists($fullPath)) {
            return [];
        }

        $reports = File::glob($fullPath . '/summary_*.json');
        $result = [];

        foreach ($reports as $report) {
            $fileName = basename($report);
            $testId = str_replace(['summary_', '.json'], '', $fileName);
            $data = json_decode(File::get($report), true);

            $result[] = [
                'test_id' => $testId,
                'date' => date('Y-m-d H:i:s', filemtime($report)),
                'requests' => $data['total_requests'] ?? 0,
                'duration' => $data['duration'] ?? 0,
                'avg_response_time' => $data['percentiles']['50th'] ?? 0,
                'error_rate' => $data['error_rate'] ?? 0,
            ];
        }

        // Sort by date (newest first)
        usort($result, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $result;
    }

    /**
     * Process the report data for summary metrics.
     *
     * @param array $data
     * @return array
     */
    protected function processReportSummary($data)
    {
        return [
            'total_requests' => $data['total_requests'] ?? 0,
            'failed_requests' => $data['failed_requests'] ?? 0,
            'avg_response_time' => $data['percentiles']['50th'] ?? 0,
            'peak_memory' => $data['max_memory'] ?? 0,
            'peak_cpu' => $data['max_cpu'] ?? 0,
            'throughput' => $data['throughput'] ?? 0,
            'error_rate' => $data['error_rate'] ?? 0,
            'duration' => $data['end_time'] - $data['start_time'],
            'percentiles' => $data['percentiles'] ?? [],
            'status_codes' => $data['status_codes'] ?? [],
        ];
    }

    /**
     * Process the report data for time series charts.
     *
     * @param array $data
     * @return array
     */
    protected function processTimeSeriesData($data)
    {
        // For charts, we need timestamps converted to labels
        $startTime = $data['start_time'] ?? 0;
        $labels = [];
        $responseTimes = [];
        $memory = [];
        $cpu = [];

        // Process response times
        foreach ($data['response_times'] as $index => $time) {
            if (!is_array($time)) {
                $labels[] = $index;
                $responseTimes[] = $time;
            }
        }

        // Process memory usage
        foreach ($data['memory_usage'] as $index => $usage) {
            if (!is_array($usage)) {
                $memory[] = $usage;
            }
        }

        // Process CPU usage
        foreach ($data['cpu_usage'] as $index => $usage) {
            if (!is_array($usage)) {
                $cpu[] = $usage;
            }
        }

        return [
            'labels' => $labels,
            'response_times' => $responseTimes,
            'memory' => $memory,
            'cpu' => $cpu,
        ];
    }

    /**
     * Process the report data for route performance table.
     *
     * @param array $data
     * @return array
     */
    protected function processRoutePerformance($data)
    {
        $routes = [];

        if (isset($data['routes']) && is_array($data['routes'])) {
            foreach ($data['routes'] as $route => $metrics) {
                $routes[] = [
                    'path' => $route,
                    'method' => $metrics['method'] ?? 'GET',
                    'requests' => $metrics['requests'] ?? 0,
                    'avg_response_time' => $metrics['avg_response_time'] ?? 0,
                    'min_response_time' => $metrics['min_response_time'] ?? 0,
                    'max_response_time' => $metrics['max_response_time'] ?? 0,
                    'error_rate' => $metrics['error_rate'] ?? 0,
                ];
            }
        }

        return $routes;
    }
}
