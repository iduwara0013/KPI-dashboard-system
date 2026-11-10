<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $guard = Auth::guard('web');
        if (! $request->is('api/login', 'api/logout') && $user && $request->session()->has($guard->getName())) {
            $fingerprint = $request->session()->get('account_fingerprint');
            if (! $user->is_active || ! is_string($fingerprint) || ! hash_equals($user->sessionFingerprint(), $fingerprint)) {
                $guard->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->is('api/session')
                    ? response()->json(['user' => null, 'csrf_token' => csrf_token()])
                    : response()->json(['message' => 'Your session ended. Sign in again.'], 401);
            }
        }

        return $next($request);
    }
}
