<?php

namespace BuildWithSmk\Tenancy\Middleware;

use BuildWithSmk\Tenancy\TenancyManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `tenant` middleware: resolve the tenant from the request host.
 * Central domains pass through untouched, unknown hosts get a 404.
 */
class IdentifyTenant
{
    public function __construct(protected TenancyManager $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if ($this->tenancy->isCentralDomain($host)) {
            return $next($request);
        }

        $tenant = $this->tenancy->resolveByHost($host);

        if (! $tenant) {
            throw new NotFoundHttpException("No tenant found for host [{$host}].");
        }

        $this->tenancy->initialize($tenant);

        try {
            return $next($request);
        } finally {
            // Also on exceptions, so tenant state never leaks into the next
            // request served by the same worker (Octane).
            $this->tenancy->end();
        }
    }
}
