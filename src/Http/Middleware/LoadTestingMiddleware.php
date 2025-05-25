<?php

namespace NikunjKothiya\LaravelLoadTesting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LoadTestingMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Bypass rate limiting for load testing
        if ($request->hasHeader('X-Load-Testing') && $request->header('X-Load-Testing') === config('load-testing.secret_key')) {
            // Skip rate limiting middleware
            $request->attributes->set('load_testing', true);
        }
        
        // Handle CORS for load testing dashboard
        if ($this->isLoadTestingDashboardRequest($request)) {
            $response = $next($request);
            
            return $this->addCorsHeaders($response);
        }
        
        // Add load testing headers to identify test requests
        if (config('load-testing.enabled') && $request->attributes->get('load_testing', false)) {
            $request->headers->set('X-Load-Testing-ID', uniqid('load_test_'));
        }
        
        return $next($request);
    }
    
    /**
     * Determine if the request is for the load testing dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function isLoadTestingDashboardRequest(Request $request): bool
    {
        $dashboardUrl = config('load-testing.dashboard_url', 'load-testing-dashboard');
        
        return $request->is($dashboardUrl) || $request->is($dashboardUrl.'/*');
    }
    
    /**
     * Add CORS headers to the response.
     *
     * @param  mixed  $response
     * @return mixed
     */
    protected function addCorsHeaders($response)
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, X-Token-Auth, Authorization, X-Load-Testing');
        
        return $response;
    }
} 