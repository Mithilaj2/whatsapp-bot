<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells Postgres which user is signed in, so row-level security lets them see
 * their own memberships. Clears the context once the response is built so a
 * reused connection never carries one request's identity into the next.
 */
class SetDatabaseUser
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->setUser($request->user()?->getAuthIdentifier());

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
