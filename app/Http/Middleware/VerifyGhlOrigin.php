<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyGhlOrigin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Allow GoHighLevel iframe embedding by removing restrictive X-Frame-Options
        // and setting permissive frame-ancestors CSP for GoHighLevel domains
        $response->headers->remove('X-Frame-Options');
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'self' https://*.gohighlevel.com https://*.leadconnectorhq.com https://*.msgsndr.com https://*.highlevel.com http://localhost:*;"
        );

        return $response;
    }
}
