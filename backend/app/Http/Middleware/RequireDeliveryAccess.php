<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequireDeliveryAccess
{
    public static function roles(?User $user): array
    {
        if (! $user?->is_active || $user->system_role !== 'member') {
            return [];
        }

        return DB::table('department_memberships as m')->join('departments as d', 'd.id', '=', 'm.department_id')
            ->where('m.user_id', $user->id)->where('d.is_active', true)->whereIn('m.responsibility', ['hof', 'manager', 'employee'])
            ->pluck('m.responsibility')->unique()->values()->all();
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_if(self::roles($request->user()) === [], 403, 'An active department responsibility is required.');

        return $next($request);
    }
}
