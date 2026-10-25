<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify registers its own registration route, so the named "registration"
 * limiter is applied here instead of on the route definition.
 */
final class ThrottleRegistrations
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->routeIs('register.store')) {
            return $this->throttle->handle($request, $next, 'registration');
        }

        return $next($request);
    }
}
