<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationChartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $members = DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('departments as d', 'd.id', '=', 'm.department_id')->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)->get(['u.id', 'u.name', 'u.designation', 'm.department_id', 'm.responsibility']);
        $links = DB::table('reporting_relationships')->get(['department_id', 'manager_id', 'user_id']);
        $all = in_array($user->system_role, ['super_admin', 'md', 'plan_editor'], true);
        $nodes = collect();
        $edges = collect();
        foreach ($members->groupBy('department_id') as $department => $group) {
            if (! $all && ! $group->contains('id', $user->id)) {
                continue;
            }
            $relations = $links->where('department_id', $department)->filter(fn ($link) => $group->contains('id', $link->manager_id) && $group->contains('id', $link->user_id));
            $visible = $group->pluck('id')->all();
            if (! $all) {
                $down = [$user->id];
                $up = [$user->id];
                do {
                    $before = count($down) + count($up);
                    foreach ($relations as $link) {
                        if (in_array($link->manager_id, $down)) {
                            $down[] = $link->user_id;
                        }
                        if (in_array($link->user_id, $up)) {
                            $up[] = $link->manager_id;
                        }
                    }
                    $down = array_unique($down);
                    $up = array_unique($up);
                } while ($before !== count($down) + count($up));
                $visible = array_unique([...$down, ...$up]);
            }
            $nodes = $nodes->merge($group->whereIn('id', $visible));
            $edges = $edges->merge($relations->filter(fn ($link) => in_array($link->manager_id, $visible) && in_array($link->user_id, $visible)));
        }

        return response()->json(['people' => $nodes->values(), 'links' => $edges->values(), 'departments' => DB::table('departments')->whereIn('id', $nodes->pluck('department_id')->unique())->orderBy('name')->get(['id', 'name', 'code'])]);
    }
}
