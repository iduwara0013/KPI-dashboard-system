<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSystemAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_active && $request->user()?->system_role === 'super_admin', 403,
            'An active system administrator account is required.');

        return $next($request);
    }
}
