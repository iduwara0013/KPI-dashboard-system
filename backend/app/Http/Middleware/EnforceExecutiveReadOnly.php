<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceExecutiveReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->system_role === 'md' && ! $request->isMethodSafe() && ! $request->is('api/login', 'api/logout', 'api/notifications/read-all', 'api/notifications/*/read', 'api/notifications/*/archive')) {
            abort(403, 'MD access is read-only.');
        }

        return $next($request);
    }
}
