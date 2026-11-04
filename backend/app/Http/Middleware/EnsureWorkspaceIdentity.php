<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/session', 'api/login', 'api/logout') && $request->hasHeader('X-Workspace-User') && $request->user()) {
            abort_unless((string) $request->user()->id === $request->header('X-Workspace-User'), 401, 'The signed-in account changed. Sign back in with the original account.');
        }

        return $next($request);
    }
}
