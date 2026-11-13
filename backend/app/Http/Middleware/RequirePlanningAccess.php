<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequirePlanningAccess
{
    public static function canEdit(?User $user): bool
    {
        return $user?->is_active && $user->system_role === 'plan_editor';
    }

    public static function departmentIds(User $user): Collection
    {
        return DB::table('department_memberships as m')->join('departments as d', 'd.id', '=', 'm.department_id')
            ->where('m.user_id', $user->id)->where('d.is_active', true)->pluck('m.department_id')->map(fn ($id) => (int) $id);
    }

    public static function ownerIds(User $user): Collection
    {
        return DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.department_id', self::departmentIds($user))->where('u.is_active', true)->where('u.system_role', 'member')
            ->distinct()->pluck('m.user_id')->map(fn ($id) => (int) $id);
    }

    public static function documentInScope(User $user, array $document): bool
    {
        $departments = self::departmentIds($user);

        return self::ownerIds($user)->contains((int) $document['owner_id'])
            && collect($document['kpas'])->every(fn ($kpa) => $departments->contains((int) $kpa['department_id']));
    }

    public static function canManagePlan(User $user, int $plan): bool
    {
        $record = DB::table('performance_plans')->find($plan);
        if (! $record || ! self::ownerIds($user)->contains((int) $record->owner_id)) {
            return false;
        }
        $departments = self::departmentIds($user);
        if (DB::table('performance_plan_items')->where('plan_id', $plan)->whereNotIn('department_id', $departments)->exists()) {
            return false;
        }

        return DB::table('performance_plan_versions')->where('plan_id', $plan)->pluck('snapshot')
            ->every(fn ($snapshot) => collect(json_decode($snapshot, true)['kpas'])->every(fn ($kpa) => $departments->contains((int) $kpa['department_id'])));
    }

    public static function canView(?User $user): bool
    {
        return $user?->is_active && (self::canEdit($user) || ($user->system_role === 'member' && DB::table('department_memberships')
            ->join('departments', 'departments.id', '=', 'department_memberships.department_id')
            ->where('user_id', $user->id)->where('departments.is_active', true)
            ->where(fn ($query) => $query->where('responsibility', 'hof')->orWhereExists(
                DB::table('performance_plans')->whereColumn('owner_id', 'department_memberships.user_id')->whereNotNull('published_revision')
            ))->exists()));
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::canView($request->user()), 403, 'You do not have access to performance planning.');

        return $next($request);
    }
}
