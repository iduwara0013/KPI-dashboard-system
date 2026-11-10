<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductionSecurity
{
    public static function configurationIssues(): array
    {
        $issues = [];
        if (config('app.debug')) {
            $issues[] = 'APP_DEBUG must be false.';
        }
        if (parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https') {
            $issues[] = 'APP_URL must use HTTPS.';
        }
        if (! config('app.key')) {
            $issues[] = 'APP_KEY must be configured.';
        }
        if (! config('session.secure') || ! config('session.http_only') || ! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $issues[] = 'Session cookies must be Secure, HttpOnly and SameSite lax or strict.';
        }
        if (! in_array(config('session.driver'), ['database', 'redis', 'file'], true)) {
            $issues[] = 'Use server-side database, Redis or file sessions.';
        }
        if ((int) config('session.lifetime') < 1 || (int) config('session.lifetime') > 60) {
            $issues[] = 'SESSION_LIFETIME must be between 1 and 60 minutes.';
        }
        if (in_array(config('cache.default'), ['array', 'null'], true)) {
            $issues[] = 'Use a persistent cache store so login rate limits survive HTTP requests.';
        }
        if (config('database.default') === 'sqlsrv') {
            $database = config('database.connections.sqlsrv');
            if (! in_array(strtolower((string) ($database['encrypt'] ?? '')), ['1', 'true', 'yes', 'mandatory', 'strict'], true)) {
                $issues[] = 'SQL Server encryption must be enabled.';
            }
            if (filter_var($database['trust_server_certificate'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false) {
                $issues[] = 'SQL Server must verify a trusted server certificate.';
            }
        }

        return $issues;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && (self::configurationIssues() !== [] || ! $request->isSecure())) {
            return response()->json(['message' => 'Secure deployment configuration is required.'], 503)
                ->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
        }

        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        }
        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
