<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequirePlanningAccess
{
    public static function canEdit(?User $user): bool
    {
        return $user?->is_active && $user->system_role === 'plan_editor';
    }

    public static function canView(?User $user): bool
    {
        return $user?->is_active && $user->system_role !== 'super_admin' && (self::canEdit($user) || DB::table('department_memberships')
            ->join('departments', 'departments.id', '=', 'department_memberships.department_id')
            ->where('user_id', $user->id)->where('responsibility', 'hof')->where('departments.is_active', true)->exists());
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::canView($request->user()), 403, 'You do not have access to performance planning.');

        return $next($request);
    }
}
