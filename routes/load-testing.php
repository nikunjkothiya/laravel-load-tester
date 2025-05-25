<?php

use Illuminate\Support\Facades\Route;
use NikunjKothiya\LaravelLoadTesting\Http\Controllers\DashboardController;

// Load testing dashboard routes
Route::prefix(config('load-testing.dashboard_url', 'load-testing-dashboard'))->group(function () {
    // Main dashboard view
    Route::get('/', [DashboardController::class, 'index'])->name('load-testing.dashboard');
    
    // List all test reports
    Route::get('/reports', [DashboardController::class, 'list'])->name('load-testing.reports');
    
    // View specific test report
    Route::get('/report/{testId}', [DashboardController::class, 'show'])->name('load-testing.report');
    
    // Real-time metrics endpoint
    Route::get('/realtime', [DashboardController::class, 'realtime'])->name('load-testing.realtime');
}); 